# Parte 3 — CQRS, Message Bus y Microservicios Asíncronos con RabbitMQ

> **En esta parte tu wallet-service se convierte en un sistema orientado a eventos.**
> Aprendes CQRS (Commands/Queries/Events), configuras Symfony Messenger con RabbitMQ
> (exchanges, colas, dead letters) y construyes tu **segundo microservicio**: el
> **loan-service**, un worker autónomo que consume eventos y decide elegibilidad
> de préstamos.

---

## Contenido

| Lección | Tema | Conceptos clave |
|---|---|---|
| 3.1 | CQRS: Commands, Queries y Events | DTOs, buses separados, intención vs hecho |
| 3.2 | Symfony Messenger | Buses, middlewares, serialización contract-first |
| 3.3 | RabbitMQ: Exchanges, Colas y DLQ | AMQP, topic exchange, bindings, dead letters |
| 3.4 | El loan-service (worker asíncrono) | php-amqplib, ack/nack, prefetch, idempotencia |
| 3.5 | Consistencia eventual | At-least-once, deduplicación, outbox, poison messages |

**Ejercicios 3.1 – 3.8 · Puntos: 52 · Total acumulado: 188**

---

## 3.1 CQRS: Commands, Queries y Events

### El problema que resuelve

Hasta ahora tu controller llamaba a tu servicio, y tu servicio a tu repositorio:

```
HTTP → Controller → Service → Repository → Doctrine → PostgreSQL
```

Esto funciona, pero tiene problemas cuando el sistema crece:

1. **Un solo "buzón" para todo:** no distingues entre *escribir* y *leer*.
2. **Los efectos secundarios se pegan al controlador:** notificar, auditar,
   enviar eventos... todo vive en el mismo método.
3. **Imposible escalar por separado:** la lectura (muchas consultas) y la
   escritura (menos pero críticas) compiten por el mismo código.
4. **No hay historial:** si algo sale mal, no sabes *qué se intentó hacer*.

### La solución: tres tipos de mensaje

CQRS (Command Query Responsibility Segregation) separa el sistema en tres
canales de comunicación, cada uno con reglas propias:

```
┌─────────────────────────────────────────────────────────────────────┐
│                        COMMAND  (intención)                          │
│  "Haz algo" → cambia el estado → puede fallar → se audita            │
│  Ej: CrearBilleteraCommand, RealizarDepositoCommand                  │
├─────────────────────────────────────────────────────────────────────┤
│                        QUERY  (pregunta)                             │
│  "Dame datos" → no cambia nada → se cachea → no genera efectos        │
│  Ej: ObtenerBilleteraQuery                                           │
├─────────────────────────────────────────────────────────────────────┤
│                        EVENT  (hecho consumado)                      │
│  "Esto pasó" → no se puede evitar → se publica al ecosistema         │
│  Ej: BilleteraCreadaEvent, TransaccionCompletadaEvent                │
└─────────────────────────────────────────────────────────────────────┘
```

| Pregunta | Command | Query | Event |
|---|---|---|---|
| ¿Cambia el estado? | ✅ Sí | ❌ No | ✅ Sí *(ya cambió)* |
| ¿Puede fallar? | ✅ Sí | ✅ Sí (lectura) | ❌ No |
| ¿Quién lo produce? | El usuario/API | El usuario/API | Un Command exitoso |
| ¿Dónde se procesa? | Handler (síncrono) | Handler (síncrono) | Cualquier consumidor |
| ¿Qué retorna? | Confirmación | Datos | Nada |
| Tiempo verbal | Imperativo ("crea") | Interrogativo ("dame") | Pasado ("fue creada") |

### Regla de oro del dominio: **los eventos nacen en los handlers**

Un Command handler no solo ejecuta: **publica el evento que documenta lo
que acaba de pasar**. En nuestro código:

```php
// src/Application/CommandHandler/CrearBilleteraCommandHandler.php (fragmento)
public function __invoke(CrearBilleteraCommand $command): string
{
    $moneda  = self::monedaDesdeCodigo($command->moneda);   // validar
    $billetera = new Billetera(...);                        // construir
    $this->billeteras->save($billetera);                    // persistir

    // El hecho consumado se publica al mundo:
    $this->eventBus->dispatch(new BilleteraCreadaEvent(
        billeteraId: $billetera->id,
        usuarioId: $billetera->usuarioId,
        moneda: $billetera->moneda->value,
        creadoEn: $billetera->creadoEn->format('Y-m-d\TH:i:s\Z'),
        eventId: (string) Uuid::v4(),   // cada evento tiene su propia identidad
    ));

    return $billetera->id;
}
```

> **¿Por qué `eventId` no puede ser el id de la billetera?**
> Porque un evento es un hecho *con su propia identidad*. La misma billetera
> puede disparar "creada", "depositada" (mil veces), "retirada"... Cada uno
> es un evento distinto. El `event_id` es la clave que usan los consumidores
> para detectar duplicados.

### Las fachadas CommandBus y QueryBus (HandleTrait)

`MessageBusInterface::dispatch()` siempre retorna un `Envelope` (sobre que
envuelve el mensaje), no el resultado del handler. Para que el controller
reciba el resultado directamente, usamos el trait oficial de Symfony:

```php
// src/Infrastructure/Bus/CommandBus.php (fragmento)
final class CommandBus
{
    use HandleTrait;   // convierte dispatch() en handle() → retorna el valor del handler

    public function __construct(MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;   // se autoconecta a "command.bus"
    }

    public function dispatch(object $command): mixed
    {
        return $this->handle($command);    // → retorna string $id, array, etc.
    }
}
```

El controller queda limpísimo:

```php
$billeteraId = $this->commandBus->dispatch(CrearBilleteraCommand::crear(
    usuarioId: $datos['usuario_id'],
    moneda: strtoupper($datos['moneda']),
));
// $billeteraId es un string con el UUID creado → respondemos 201
```

---

## 3.2 Symfony Messenger: los buses y su configuración

### Qué es Messenger

Symfony Messenger es el **buzón de mensajes** de Symfony. Define *buses*
(canales con reglas), *handlers* (quién procesa qué) y *transports*
(dónde viajan los mensajes: en memoria, Doctrine, RabbitMQ, SQS...).

### Nuestra topología de buses

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        default_bus: command.bus        # el bus que se usa por defecto

        buses:
            command.bus:                # escritura
                middleware:
                    - validate                  # valida el Command (Constraints)
                    - doctrine_transaction       # cada command = 1 transacción BD

            query.bus:                  # lectura
                middleware:
                    - validate

            event.bus:                  # publicación de eventos
                default_middleware: allow_no_handlers   # se puede publicar sin handler local
                middleware:
                    - validate
```

| Bus | Uso | Middleware clave |
|---|---|---|
| `command.bus` | Manejar Commands | `doctrine_transaction` → commit/rollback automático |
| `query.bus` | Manejar Queries | solo validación |
| `event.bus` | Publicar Events | `allow_no_handlers` (los consumen otros servicios) |

> **`doctrine_transaction`** envuelve cada command en una transacción:
> si el handler lanza una excepción, todo se revierte. No vuelvas a
> escribir `beginTransaction()/commit()` a mano.

### Autowiring de buses por nombre de parámetro

Symfony 6.1+ resuelve el bus correcto según el **nombre del parámetro**:

| Parámetro en constructor | Bus inyectado |
|---|---|
| `MessageBusInterface $commandBus` | `command.bus` |
| `MessageBusInterface $queryBus` | `query.bus` |
| `MessageBusInterface $eventBus` | `event.bus` |

```php
final readonly class CrearBilleteraCommandHandler
{
    public function __construct(
        private BilleteraRepository $billeteras,   // puerto (interfaz)
        private MessageBusInterface $eventBus,      // → event.bus
    ) {}
}
```

### Serialización contract-first: el evento como JSON público

Por defecto, Messenger serializa los mensajes con un formato interno.
Nosotros **no** queremos eso: queremos que el JSON que viaja por RabbitMQ
sea un contrato público que cualquier tecnología pueda leer.

```php
// src/Infrastructure/Serializer/EventoSerializer.php (fragmento)
public function encode(Envelope $envelope): array
{
    $mensaje = $envelope->getMessage();

    return [
        'body' => $mensaje->toJson(),          // ← JSON limpio del contrato
        'headers' => ['type' => $mensaje->eventType],
    ];
}
```

El evento serializado luce así en la cola (contrato v1.0):

```json
{
  "event_id": "3f2a1b9c-8e4d-4a22-9c55-001122334455",
  "event_type": "transaccion.completada",
  "version": "1.0",
  "timestamp": "2026-09-10T12:00:00Z",
  "payload": {
    "transaccion_id": "mov_66f9...",
    "billetera_id": "9d1c...",
    "tipo": "deposito",
    "monto": "1500.00",
    "moneda": "MXN",
    "balance_nuevo": "1500.00"
  }
}
```

> **Regla de oro del diseño de eventos:** el contrato JSON **es** el API
> entre servicios. Documenta `version`, `timestamp` y `event_type` desde
> el día uno. Cuando un consumidor necesite más datos, subirás `version`
> a `1.1` sin romper a los que leen `1.0`.

---

## 3.3 RabbitMQ: Exchanges, Colas y Dead Letter Queues

### El modelo AMQP en 60 segundos

RabbitMQ implementa AMQP. La unidad de enrutamiento es el **exchange**
("switch"): el productor **no publica a una cola**, publica a un exchange
con una **routing key**. El exchange decide a qué colas (bindings) entrega
el mensaje según el **tipo de exchange**.

```
Productor (wallet-service)
   │  publica → exchange "prestaflow" (topic)
   │           routing_key = "wallet.event.transaccion"
   ▼
┌──────────────────────┐
│    prestaflow        │
│     (topic)          │
└──────┬──────────┬────┘
       │          │
  binding      binding
  "wallet.     "wallet.
   event.#"     event.#"
       │          │
       ▼          ▼
┌────────────┐ ┌────────────┐
│ wallet_    │ │ loan_      │
│ events     │ │ events     │
│ (wallet-   │ │ (loan-     │
│  service)  │ │  service)  │
└────────────┘ └────────────┘
```

| Exchange tipo | Routing | Uso |
|---|---|---|
| `direct` | clave exacta | enrutar a 1 cola específica |
| `fanout` | ignora la key | broadcast a TODAS las colas |
| `topic` | patrón `a.b.#` / `a.*.c` | enrutamiento por prefijo (nuestro caso) |
| `headers` | por headers | casos raros |

> **Por qué `topic` es la elección profesional:** permite que *n* consumidores
> se suscriban a subconjuntos de eventos. Hoy solo escuchamos
> `wallet.event.#`; mañana un "audit-service" puede escuchar solo
> `wallet.event.retiro` sin tocar al loan-service.

### Nuestra configuración en `messenger.yaml`

```yaml
transports:
    events:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'      # amqp://prestaflow:secret@rabbitmq:5672/%2f
        serializer: App\Infrastructure\Serializer\EventoSerializer
        options:
            exchange:
                name: prestaflow
                type: topic
                durable: true
            queues:
                wallet_events:
                    binding_keys: ['wallet.event.#']   # la cola DEL wallet-service
                    durable: true
            routing_key: wallet.event.transaccion       # con qué clave publicamos
        retry_strategy:
            max_retries: 3
            delay_milliseconds: 2000
            multiplier: 3

    failed:
        dsn: 'doctrine://default?queue_name=failed'
```

### ¿Qué hace RabbitMQ por nosotros?

| Garantía | ¿Cómo? |
|---|---|
| Mensajes no se pierden | `durable: true` en exchange y colas → persisten en disco |
| Reenvío tras caída del consumidor | El mensaje no se elimina hasta recibir `ack` |
| Reintentos con backoff | `retry_strategy` (2s → 6s → 18s) |
| Mensajes eternamente fallidos | `failure_transport: failed` (colas Doctrine) |
| Mensajes rechazados → DLQ | Dead Letter Exchange (ver abajo) |
| Balanceo entre instancias | Varios workers consumen la misma cola → RabbitMQ reparte |

### Dead Letter Queue: el "hospicio" de los mensajes fallidos

Cuando un consumidor hace `basic_nack(requeue: false)` (falla y no quiere
reintentar), el mensaje se pierde... **a menos que** la cola esté configurada
con un **Dead Letter Exchange (DLX)**. La declaramos con `rabbitmqadmin`
(1 sola vez, idempotente):

```bash
# 1. Crear el DLX (direct, durable)
docker compose exec rabbitmq rabbitmqadmin declare exchange \
    name=prestaflow_dlx type=direct durable=true

# 2. Crear la cola de mensajes muertos
docker compose exec rabbitmq rabbitmqadmin declare queue \
    name=dlq_eventos durable=true

# 3. Bindear la cola muerta al DLX (routing_key "eventos.fallidos")
docker compose exec rabbitmq rabbitmqadmin declare binding \
    source=prestaflow_dlx destination=dlq_eventos routing_key=eventos.fallidos
```

> **La DLQ no es opcional en fintech.** Un mensaje de transacción que no
> llega a procesarse es dinero que "se cae del sistema". Con DLQ al menos
> queda **visible y auditable** para un operador.

---

## 3.4 El loan-service: un microservicio worker

### Filosofía: ¿por qué un worker y no una parte del wallet-service?

El **loan-service** decide si un usuario es elegible para un préstamo según
su historial de transacciones. No necesita servir HTTP: es un **proceso de
larga vida** que consume eventos y escribe resultados.

```
                 ┌──────────────────────────────┐
                 │       RABBITMQ               │
   wallet-       │   exchange: prestaflow       │       loan-
   service ─────▶│                              │◀───── service
   (API REST)    │   cola: loan_events          │       (worker)
                 └──────────────────────────────┘
   Productor     función: transportar           Consumidor
   (nunca sabe     el hecho consumado           (nunca conoce
    quién lee)     de forma fiable               quién escribió)
```

| | wallet-service | loan-service |
|---|---|---|
| Rol | Productor (API) | Consumidor (worker) |
| Interfaz | HTTP | Cola AMQP |
| Framework | Symfony completo | PHP plano + php-amqplib |
| BD | PostgreSQL (billeteras) | PostgreSQL (resultados) |
| Arranque | Nginx + PHP-FPM | `php bin/consumir.php` |
| Escala | réplicas horizontales | réplicas horizontales |

> **Detalle deliberado:** el loan-service **no usa Symfony**. Demuestra
> que un consumidor solo necesita el contrato JSON y un cliente AMQP.
> python, Go, Node... podrían ocupar su lugar sin tocar al productor.

### El bucle de consumo: ack, nack y prefetch

```php
// loan-service/src/Consumer/ConsumidorTransacciones.php (fragmento)
$canal->basic_qos(null, 1, null);          // prefetch=1: un mensaje a la vez

$canal->basic_consume(self::COLA, '', false, false, false, false, $this->procesar(...));

while ($canal->is_consuming()) {
    $canal->wait();                        // bucle infinito: escucha y bloquea
}
```

| Concepto | Qué hace | Por qué importa |
|---|---|---|
| `basic_ack` | Confirma que procesaste el mensaje | RabbitMQ lo elimina de la cola |
| `basic_nack(requeue: false)` | Rechaza el mensaje | Va a la DLQ (dead letter) |
| *(sin ack)* | El consumidor murió | RabbitMQ lo re-entrega a otro |
| `basic_qos(1)` | Prefetch | No saturar la BD con ráfagas |
| `basic_consume` | Registrar callback | Se ejecuta por cada mensaje |

### El ciclo de vida de cada evento en el worker

```
Mensaje llega de la cola
   │
   ├─ 1. json_decode (contrato público) ─┐ falla ─▶ nack → DLQ
   │
   ├─ 2. ¿event_id ya en eventos_procesados? ─ sí → ack (duplicado, no re-procesar)
   │
   ├─ 3. EvaluadorElegibilidad::evaluar($evento)  (regla de negocio pura)
   │
   ├─ 4. Persistir resultado + marcar event_id como procesado
   │
   └─ 5. ack → "procesado con éxito"
```

### Idempotencia del consumidor: la tabla `eventos_procesados`

RabbitMQ garantiza **al menos una vez** (at-least-once): un evento puede
llegar DDoS veces (reconexión justo después del procesar pero antes del ack).
El worker debe ser **idempotente**:

```sql
-- loan-service/config/schema.sql
CREATE TABLE IF NOT EXISTS eventos_procesados (
    event_id       VARCHAR(64) PRIMARY KEY,   -- la UNIQUE garantiza 1 solo proceso
    puntaje        INT         NOT NULL,
    recomendacion  VARCHAR(20) NOT NULL,
    procesado_en   TIMESTAMP   NOT NULL DEFAULT NOW()
);
```

```php
if ($this->procesados->existe($eventId)) {   // ¿ya lo vi?
    $canal->basic_ack($mensaje->getDeliveryTag());   // ack sin reprocesar
    return;
}
```

> **Patrón check-then-act vs. SQL:** el check `existe()` y el insert
> `registrar()` no son atómicos, pero la **UNIQUE constraint** de la BD es
> la garantía final: si dos workers procesan el mismo evento a la vez,
> solo uno gana el INSERT.

---

## 3.5 Consistencia eventual y el patrón Outbox

### El problema: BD y cola no son un solo commit

En `RealizarDepositoCommandHandler` hacemos dos cosas que deberían ser
atómicas pero no lo son:

```php
$this->billeteras->save($billetera);           // 1. BD: INSERT movimiento
$this->eventBus->dispatch($evento);            // 2. Cola: publicar evento
```

¿Qué pasa si el paso 1 funciona y el paso 2 falla?
- El depósito **existe** en la BD pero **nadie se entera** (ni préstamos,
  ni auditoría, ni notificaciones). **Y es un sistema financiero.**

### El patron Outbox (solución de producción)

```
 1. Command handler INSERTa el evento en la tabla OUTBOX (misma BD, mismo commit)
 2. Un "publisher" (reloj) lee la outbox cada 1s
 3. Publica a RabbitMQ SAROMOS el commit de la BD
 4. Marca el evento como "publicado"
```

```sql
CREATE TABLE IF NOT EXISTS outbox (
    id         BIGSERIAL PRIMARY KEY,
    event_id   VARCHAR(64) UNIQUE NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    payload    JSONB NOT NULL,
    publicado  BOOLEAN NOT NULL DEFAULT FALSE,
    creado_en  TIMESTAMP NOT NULL DEFAULT NOW()
);
```

| Pro | Contra |
|---|---|
| Consistencia real entre BD y cola | +20 ms de latencia (reloj cada 1s) |
| Suele venir con el framework (ver `symfony/messenger` + `relay:outbox`) | Más complejidad al inicio |
| Reintentos y auditoría gratis | — |

> **Para el curso:** el handler publica directo a la cola (sencillo).
> En producción: usa `Relay\Symfony\Messenger` o el outbox nativo.
> La lección importante: **nunca publiques antes del commit**.

### Tipos de entrega y qué implican

| Entrega | Significado | Implicación en tu código |
|---|---|---|
| At-most-once | "quizá llegue, quizá no" | nunca en fintech |
| At-least-once | "llegará, puede repetirse" | **consumidor idempotente obligatorio** ← lo nuestro |
| Exactly-once | "llegará exactamente una vez" | requiere deduplicación distribuida (idempotency keys + UNIQUE) |

> **Mito:** RabbitMQ no da exactly-once. Lo que SÍ se logra es
> *at-least-once + consumidor idempotente* = efecto exactly-once.
> Eso es exactamente lo que construimos con `eventos_procesados`.

### Poison messages: el mensaje que mata al worker

Un mensaje malformado que hace fallar al handler **no puede causar un
bucle infinito** (consumir → fallar → re-entrega → consumir → fallar →...).
Nuestras defensas:

1. `basic_nack(requeue: false)` → va a la DLQ, no a la cola
2. `retry_strategy` con `max_retries: 3` → se detiene tras 3 intentos
3. El try/catch del callback nunca sale del callback

---

## Resumen de la Parte 3

| Lo que construiste | Archivo de referencia |
|---|---|
| Commands (DTOs de intención) | `src/Message/Command/*.php` |
| Query (DTO de lectura) | `src/Message/Query/ObtenerBilleteraQuery.php` |
| Events (hechos consumados) | `src/Message/Event/*.php` |
| Command/Query handlers | `src/Application/{CommandHandler,QueryHandler}/*.php` |
| Puertos de infraestructura | `src/Domain/Repository/*.php` |
| Fachadas con HandleTrait | `src/Infrastructure/Bus/{CommandBus,QueryBus}.php` |
| Serializador contract-first | `src/Infrastructure/Serializer/EventoSerializer.php` |
| Buses + transporte RabbitMQ | `config/packages/messenger.yaml` |
| Controller CQRS | `src/Controller/BilleteraController.php` |
| **Microservicio loan-service** | `loan-service/**` (worker + evaluador + idempotencia) |

---

## 🧪 Ejercicios de la Parte 3 · 52 puntos

> Formato: escribe el código → confirma con la verificación de cada
> ejercicio → acumula puntos. Total del curso hasta aquí: **188 pts**.

| Ejercicio | Tema | ⭐ |
|---|---|---|
| [3.1 — Commands y Queries](ejercicios/3.1-commands-queries.md) | Definir mensajes CQRS | 5 |
| [3.2 — Handlers con HandleTrait](ejercicios/3.2-handlers.md) | Implementar use cases | 8 |
| [3.3 — Configurar transporte RabbitMQ](ejercicios/3.3-transporte-rabbitmq.md) | messenger.yaml + .env | 6 |
| [3.4 — Topología de colas](ejercicios/3.4-topologia-colas.md) | Exchange, bindings, DLQ | 6 |
| [3.5 — Publicar y consumir](ejercicios/3.5-publicar-consumir.md) | Demo full-stack evento | 10 |
| [3.6 — Idempotencia del productor](ejercicios/3.6-idempotencia-productor.md) | Claves únicas + UNIQUE | 7 |
| [3.7 — Idempotencia del consumidor](ejercicios/3.7-idempotencia-consumidor.md) | eventos_procesados | 6 |
| [3.8 — Elegibilidad del loan-service](ejercicios/3.8-elegibilidad.md) | Test TDD del evaluador | 4 |

**Total parte: 52 pts · Curso acumulado: 188 pts**

---

## Comandos útiles de la Parte 3

```bash
# Ver la cola y los mensajes (management UI)
open http://localhost:15672   # prestaflow / secret

# Consumir eventos con el worker manualmente (fuera de Docker)
cd loan-service && composer install && php bin/consumir.php

# Reintentar mensajes de la cola de fallos
php bin/console messenger:consume failed

# Crear la tabla de mensajes fallidos (Doctrine transport)
php bin/console messenger:setup-transports

# Ver la topología declarada por Messenger
php bin/console debug:messenger
```