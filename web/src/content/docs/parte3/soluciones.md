---
title: "Soluciones — Parte 3 — CQRS y Event-Driven Architecture"
description: "Código fuente de las soluciones de la Parte 3"
sidebar: {"label":"Soluciones","order":100}
---


> 📁 **28 archivos** de código de referencia.

## config/packages/messenger.yaml

```yaml
# config/packages/messenger.yaml — Buses CQRS + transporte RabbitMQ
#
# SYMFONY MESSENGER: el "correo interno" de la aplicación.
# Toda comunicación entre capas pasa por uno de estos buses.

framework:
    messenger:
        # ------------------------------------------------------------------
        # BUSES: canales aislados con middlewares propios.
        #
        #   command.bus → escritura (Commands) → handlers mutan estado
        #   query.bus   → lectura (Queries)   → handlers solo leen
        #   event.bus   → notificación (Events) → enruta a RabbitMQ
        #
        # Middlewares por bus:
        #   validate            → ejecuta Symfony Validator sobre el mensaje
        #   doctrine_transaction → cada command corre dentro de una TX (commit/rollback)
        # ------------------------------------------------------------------
        default_bus: command.bus

        buses:
            command.bus:
                middleware:
                    - validate
                    - doctrine_transaction

            query.bus:
                middleware:
                    - validate

            event.bus:
                # allow_no_handlers: un evento puede publicarse sin que el
                # productor tenga un handler local registrado (lo consume
                # otro servicio). Sin esta opción, Symflony lanzaría error.
                default_middleware: allow_no_handlers
                middleware:
                    - validate

        # ------------------------------------------------------------------
        # TRANSPORTS: destinos físicos de los mensajes.
        #
        #   events  → RabbitMQ (AMQP), exchange topic "prestaflow"
        #   failed  → la cola de mensajes que fallaron (para reprocesar)
        #
        # RabbitMQ y la topología de colas se explican en la lección 3.3.
        # ------------------------------------------------------------------
        failure_transport: failed

        transports:
            events:
                # DSN de AMQP. La URL se lee de .env (MESSENGER_TRANSPORT_DSN).
                # Ejemplo: amqp://prestaflow:secret@rabbitmq:5672/%2f
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'

                # Nuestro serializador custom (contrato JSON público).
                serializer: App\Infrastructure\Serializer\EventoSerializer

                options:
                    # EXCHANGE: el "switch" que enruta mensajes a colas.
                    exchange:
                        name: prestaflow
                        type: topic        # routing por prefijo: wallet.event.#
                        durable: true      # sobrevive reinicios del broker
                        auto_delete: false # no se borra al desconectar

                    # Cola de mensajes del propio wallet-service.
                    queues:
                        wallet_events:
                            binding_keys: ['wallet.event.#']
                            durable: true

                    # Routing key con la que se PUBLICAN los mensajes.
                    # Debe coincidir con el patrón de binding (wallet.event.#).
                    routing_key: wallet.event.transaccion

                # Reintentos automáticos al CONSUMIR (no al publicar).
                retry_strategy:
                    max_retries: 3          # máximo 3 intentos
                    delay_milliseconds: 2000 # espera base entre intentos
                    multiplier: 3            # 2s → 6s → 18s (backoff exponencial)

            # Transporte de mensajes que agotaron reintentos o fallaron.
            failed:
                dsn: 'doctrine://default?queue_name=failed'

        # ------------------------------------------------------------------
        # ROUTING: qué mensaje va a qué destino.
        # ------------------------------------------------------------------
        routing:
            # Los EVENTOS no se manejan inline: se ENRUTAN a RabbitMQ.
            # El consumidor (loan-service u otro worker) los procesa.
            'App\Message\Event\BilleteraCreadaEvent': events
            'App\Message\Event\TransaccionCompletadaEvent': events
```

## loan-service/bin/consumir.php

```php
<?php
// loan-service/bin/consumir.php — Punto de entrada del worker
//
# Este script es el "bin/console" del loan-service: conecta las piezas
# y arranca el bucle de consumo. Se ejecuta con:
#   docker compose up loan-service
# (Dockerfile ejecuta este archivo como ENTRYPOINT)

declare(strict_types=1);

// Cargar el autoloader de Composer (lo instala `composer install`).
require __DIR__ . '/../vendor/autoload.php';

use LoanService\Application\EvaluadorElegibilidad;
use LoanService\Consumer\ConsumidorTransacciones;
use LoanService\Infrastructure\EventosProcesadosRepository;

// ----------------------------------------------------------------------
// CONFIGURACIÓN por variables de entorno (12-factor app).
// Cada una tiene un valor por defecto razonable para desarrollo local.
// ----------------------------------------------------------------------
$rabbitHost = getenv('RABBITMQ_HOST') ?: 'localhost';
$rabbitPort = (int) (getenv('RABBITMQ_PORT') ?: 5672);
$rabbitUser = getenv('RABBITMQ_USER') ?: 'prestaflow';
$rabbitPass = getenv('RABBITMQ_PASSWORD') ?: 'secret';

$dbDsn = getenv('LOAN_DB_DSN') ?: 'pgsql:host=localhost;port=5432;dbname=prestaflow_loan';
$dbUser = getenv('LOAN_DB_USER') ?: 'prestaflow';
$dbPass = getenv('LOAN_DB_PASSWORD') ?: 'secret';

// ----------------------------------------------------------------------
// WIRING: construir las dependencias a mano (sin contenedor de servicios).
// Un microservicio pequeño no necesita Symfony: PDO + amqplib + lógica pura.
// ----------------------------------------------------------------------

// Logger simple a STDOUT con timestamp (suficiente para el curso).
$logger = new class implements Psr\Log\LoggerInterface {
    public function info(string $message, array $context = []): void
    {
        printf("[%s] INFO  %s%s", date('c'), $message, PHP_EOL);
    }

    public function error(string $message, array $context = []): void
    {
        printf("[%s] ERROR %s%s", date('c'), $message, PHP_EOL);
    }

    public function debug(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function notice(string $message, array $context = []): void {}
    public function critical(string $message, array $context = []): void {}
    public function alert(string $message, array $context = []): void {}
    public function emergency(string $message, array $context = []): void {}
    public function log($level, string|\Stringable $message, array $context = []): void {}
};

// Conexión PDO a la BD de resultados (PostgreSQL del loan-service).
$pdo = new PDO($dbDsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // falla fuerte, no silenciosa
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Ensamblar el consumidor con sus dependencias reales.
$consumidor = new ConsumidorTransacciones(
    evaluador: new EvaluadorElegibilidad(),
    procesados: new EventosProcesadosRepository($pdo),
    logger: $logger,
);

$logger->info('Arrancando loan-service...');

// Bucle infinito (bloquea el proceso).
$consumidor->ejecutar(
    host: $rabbitHost,
    port: $rabbitPort,
    user: $rabbitUser,
    password: $rabbitPass,
    vhost: '/',
);
```

## loan-service/composer.json

```json
{
    "name": "prestaflow/loan-service",
    "description": "Worker que consume eventos de transacciones y evalúa elegibilidad de préstamos",
    "type": "project",
    "license": "proprietary",
    "require": {
        "php": ">=8.3",
        "ext-pdo": "*",
        "php-amqplib/php-amqplib": "^3.7"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "LoanService\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "LoanService\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit --colors=always",
        "consumir": "php bin/consumir.php"
    },
    "config": {
        "sort-packages": true
    }
}
```

## loan-service/src/Application/EvaluadorElegibilidad.php

```php
<?php
// loan-service/src/Application/EvaluadorElegibilidad.php — Regla de negocio del worker
//
# EL worker es un microservicio INDEPENDIENTE. Su única regla de negocio:
# dado el evento "transaccion.completada", calcular si el usuario es
# elegible para un préstamo.
#
# Esta clase es PURO PHP: no depende de amqplib ni de PDO. Se puede
# testear sin colas ni base de datos (TDD puro).
#
# Modelo de puntaje (simplificado para el curso):
#   +30 pts  si el depósito es >= 1000 en la moneda local
#   +10 pts  si el depósito es < 1000 pero > 0
#   +40 pts  si el balance resultante >= 5000
#   +20 pts  si el balance resultante >= 1000 pero < 5000
#   +15 pts  si la moneda es MXN (moneda base de PrestaFlow)
#   -30 pts  si es un retiro (señal de liquidez negativa)
#
# Umbrales:
#   >= 70 → APROBADO
#   50-69 → EN_REVISION (requiere intervención humana)
#   < 50  → NO_ELEGIBLE

declare(strict_types=1);

namespace LoanService\Application;

use LoanService\Domain\ResultadoElegibilidad;

/**
 * Motor de puntaje para elegibilidad de préstamos.
 */
final class EvaluadorElegibilidad
{
    private const DEPOSITO_ALTO = 1000.0;      // umbral depósito "fuerte"
    private const BALANCE_ALTO = 5000.0;        // umbral balance "sólido"
    private const BALANCE_MEDIO = 1000.0;       // umbral balance "aceptable"
    private const UMBRAL_APROBADO = 70;         // puntaje para aprobar
    private const UMBRAL_REVISION = 50;         // puntaje mínimo de revisión

    /**
     * Evalúa un evento de transacción (formato JSON del contrato).
     *
     * @param array<string, mixed> $evento Evento decodificado de la cola
     *                                     (ver contrato en lección 3.2)
     *
     * @return ResultadoElegibilidad Resultado con puntaje y recomendación
     */
    public function evaluar(array $evento): ResultadoElegibilidad
    {
        // Extraer el payload con fallbacks seguros.
        // Nunca asumimos que el evento trae todos los campos.
        $payload = $evento['payload'] ?? $evento;
        $tipo = (string) ($payload['tipo'] ?? 'desconocido');
        $monto = (float) ($payload['monto'] ?? 0.0);
        $balance = (float) ($payload['balance_nuevo'] ?? 0.0);
        $moneda = strtoupper((string) ($payload['moneda'] ?? ''));

        // Puntaje base: comienza en 0 y se acumula con reglas.
        $puntaje = 0;

        // REGLA 1: tamaño del depósito.
        if ($tipo === 'deposito' && $monto >= self::DEPOSITO_ALTO) {
            $puntaje += 30;
        } elseif ($tipo === 'deposito') {
            $puntaje += 10;
        }

        // REGLA 2: balance resultante (salud financiera).
        if ($balance >= self::BALANCE_ALTO) {
            $puntaje += 40;
        } elseif ($balance >= self::BALANCE_MEDIO) {
            $puntaje += 20;
        }

        // REGLA 3: moneda base (incentivo a operar en MXN).
        if ($moneda === 'MXN') {
            $puntaje += 15;
        }

        // REGLA 4: los retiros son señal negativa de liquidez.
        if ($tipo === 'retiro') {
            $puntaje -= 30;
        }

        // Asegurar límites inferior (0) y superior (100).
        $puntaje = max(0, min(100, $puntaje));

        // Clasificar según umbrales.
        if ($puntaje >= self::UMBRAL_APROBADO) {
            $recomendacion = ResultadoElegibilidad::APROBADO;
            $razon = "Puntaje {$puntaje}: perfil sólido, aprobación automática.";
        } elseif ($puntaje >= self::UMBRAL_REVISION) {
            $recomendacion = ResultadoElegibilidad::EN_REVISION;
            $razon = "Puntaje {$puntaje}: requiere revisión manual del analista.";
        } else {
            $recomendacion = ResultadoElegibilidad::NO_ELEGIBLE;
            $razon = "Puntaje {$puntaje}: no cumple el perfil mínimo.";
        }

        return new ResultadoElegibilidad(
            puntaje: $puntaje,
            recomendacion: $recomendacion,
            razon: $razon,
        );
    }
}
```

## loan-service/src/Consumer/ConsumidorTransacciones.php

```php
<?php
// loan-service/src/Consumer/ConsumidorTransacciones.php — Bucle de consumo AMQP
//
# ESTE ES EL CORAZÓN DEL WORKER: un proceso que vive para consumir.
# Se conecta a RabbitMQ, escucha la cola `loan_events` y procesa
# cada mensaje con confirmación explícita (ack/nack).
#
# Flujo del mensaje:
#   wallet-service publica → exchange "prestaflow" (topic)
#     → routing key "wallet.event.transaccion"
#     → binding "loan_events" ← "wallet.event.#" (el patrón coincide)
#     → nuestro consumidor recibe el AMQPMessage
#
# Ciclo de vida del mensaje:
#   basic_ack()   → procesado OK → RabbitMQ lo elimina
#   basic_nack(requeue=false) → falló → va a la Dead Letter Queue
#   (nada)        → si el proceso muere, RabbitMQ lo re-entrega (at-least-once)

declare(strict_types=1);

namespace LoanService\Consumer;

use LoanService\Application\EvaluadorElegibilidad;
use LoanService\Infrastructure\EventosProcesadosRepository;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * Consumidor de eventos de transacción para el loan-service.
 */
final class ConsumidorTransacciones
{
    private const EXCHANGE = 'prestaflow';
    private const EXCHANGE_TYPE = 'topic';
    private const COLA = 'loan_events';
    private const BINDING_KEY = 'wallet.event.#';

    public function __construct(
        private readonly EvaluadorElegibilidad $evaluador,
        private readonly EventosProcesadosRepository $procesados,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Inicia el bucle de consumo (bloquea el proceso para siempre).
     *
     * @param string $host      Host de RabbitMQ
     * @param int    $port      Puerto AMQP (5672)
     * @param string $user      Usuario AMQP
     * @param string $password  Contraseña AMQP
     * @param string $vhost     Virtual host (por defecto "/")
     */
    public function ejecutar(
        string $host,
        int $port,
        string $user,
        string $password,
        string $vhost,
    ): void {
        // 1. Conexión TCP + canal. Un canal = una "sesión" sobre la conexión.
        $conexion = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
        $canal = $conexion->channel();

        // 2. Declarar la topología (idempotente: no falla si ya existe).
        //    exchange_declare(topic, durable) — MISMO exchange que declara
        //    el wallet-service: RabbitMQ es un directorio compartido.
        $canal->exchange_declare(
            self::EXCHANGE, self::EXCHANGE_TYPE, false, true, false,
        );

        //    queue_declare(durable) — la cola sobrevive reinicios del broker.
        $canal->queue_declare(self::COLA, false, true, false, false);

        //    queue_bind(columna, exchange, binding key).
        //    "Todo lo que empiece con wallet.event. me interesa".
        $canal->queue_bind(self::COLA, self::EXCHANGE, self::BINDING_KEY);

        // 3. Prefetch: procesar UN mensaje a la vez. Sin esto, RabbitMQ
        //    nos enviaría cientos de mensajes en ráfaga y saturaríamos la BD.
        $canal->basic_qos(null, 1, null);

        // 4. Registrar el callback por mensaje.
        //    Este closure se ejecuta por CADA mensaje recibido.
        $canal->basic_consume(
            self::COLA,          // cola
            '',                  // consumer_tag (auto)
            false,               // no_local
            false,               // no_ack → necesitamos ack EXPLÍCITO
            false,               // exclusive
            false,               // no_wait
            $this->procesar(...), // callback (referencia al método)
        );

        $this->logger->info('loan-service escuchando. Cola: ' . self::COLA);

        // 5. BUCLE INFINITO: el worker nunca termina. Escucha y bloquea.
        while ($canal->is_consuming()) {
            $canal->wait(); // espera el siguiente mensaje
        }

        // 6. Limpieza (solo si algún día se rompe el bucle).
        $canal->close();
        $conexion->close();
    }

    /**
     * Callback: procesa UN mensaje de la cola.
     *
     * @param AMQPMessage $mensaje Mensaje AMQP recibido
     */
    private function procesar(AMQPMessage $mensaje): void
    {
        $canal = $mensaje->getChannel();

        try {
            // 1. Decodificar el JSON del contrato público.
            //    El wallet-service publicó el EVENTO como body puro (JSON),
            //    gracias al EventoSerializer. Cualquier lenguaje lo leería.
            $evento = json_decode(
                $mensaje->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            // 2. Extraer la clave de deduplicación.
            //    Preferimos event_id; si el evento no lo trae, usamos
            //    el transaccion_id del payload como fallback.
            $payload = $evento['payload'] ?? [];
            $eventId = (string) ($evento['event_id'] ?? $payload['transaccion_id'] ?? '');

            if ($eventId === '') {
                throw new \RuntimeException('Evento sin ID de deduplicación.');
            }

            // 3. IDEMPOTENCIA: si ya lo procesamos, ack sin re-procesar.
            if ($this->procesados->existe($eventId)) {
                $this->logger->info("Evento duplicado ignorado: {$eventId}");
                $canal->basic_ack($mensaje->getDeliveryTag());

                return;
            }

            // 4. Ejecutar la regla de negocio (puro PHP, sin infra).
            $resultado = $this->evaluador->evaluar($evento);

            // 5. Persistir resultado + marcar como procesado (misma TX lógica).
            $this->procesados->registrar(
                $eventId,
                $resultado->puntaje,
                $resultado->recomendacion,
            );

            // 6. Confirmar al broker: mensaje procesado con éxito.
            $canal->basic_ack($mensaje->getDeliveryTag());

            $this->logger->info(
                "Evento {$eventId} → {$resultado->recomendacion} "
                . "(puntaje {$resultado->puntaje})"
            );
        } catch (\Throwable $e) {
            // Cualquier fallo → nack con requeue=false → Dead Letter Queue.
            // NO reintentamos aquí: el DLQ es el destino de lo que falla.
            $this->logger->error('Fallo procesando evento: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $canal->basic_nack($mensaje->getDeliveryTag(), false, false);
        }
    }
}
```

## loan-service/src/Domain/ResultadoElegibilidad.php

```php
<?php
// loan-service/src/Domain/ResultadoElegibilidad.php — Value Object de salida
//
# El worker no devuelve "sí/no"; devuelve un VALOR DE DOMINIO con:
#   - Un puntaje (score 0-100)
#   - Una recomendación (APROBADO / EN_REVISION / NO_ELEGIBLE)
#   - Una razón legible (para apoyo al negocio)
#
# Inmutable y auto-documentado: imposible crear un resultado inconsistente.

declare(strict_types=1);

namespace LoanService\Domain;

/**
 * Resultado de la evaluación de elegibilidad para un préstamo.
 */
final readonly class ResultadoElegibilidad
{
    public const APROBADO = 'APROBADO';
    public const EN_REVISION = 'EN_REVISION';
    public const NO_ELEGIBLE = 'NO_ELEGIBLE';

    /**
     * @param int    $puntaje        Puntaje calculado (0-100)
     * @param string $recomendacion  Una de las constantes APROBADO/EN_REVISION/NO_ELEGIBLE
     * @param string $razon          Explicación legible del resultado
     */
    public function __construct(
        public readonly int $puntaje,
        public readonly string $recomendacion,
        public readonly string $razon,
    ) {
        // Validación de invariantes: el puntaje vive en el rango 0-100.
        if ($puntaje < 0 || $puntaje > 100) {
            throw new \InvalidArgumentException(
                "Puntaje fuera de rango: {$puntaje}. Debe estar entre 0 y 100."
            );
        }

        // La recomendación solo puede tomar los 3 valores definidos.
        $validas = [self::APROBADO, self::EN_REVISION, self::NO_ELEGIBLE];
        if (!in_array($recomendacion, $validas, true)) {
            throw new \InvalidArgumentException(
                "Recomendación inválida: '{$recomendacion}'."
            );
        }
    }

    /**
     * ¿El préstamo fue aprobado automáticamente?
     *
     * @return bool true si la recomendación es APROBADO
     */
    public function fueAprobado(): bool
    {
        return $this->recomendacion === self::APROBADO;
    }

    /**
     * Representación JSON del resultado (lo que guarda el worker).
     *
     * @return array<string, string|int> Array serializable
     */
    public function toArray(): array
    {
        return [
            'puntaje' => $this->puntaje,
            'recomendacion' => $this->recomendacion,
            'razon' => $this->razon,
        ];
    }
}
```

## loan-service/src/Infrastructure/EventosProcesadosRepository.php

```php
<?php
// loan-service/src/Infrastructure/EventosProcesadosRepository.php — Idempotencia del worker
//
# At-least-once delivery: RabbitMQ puede ENTREGAR el mismo evento dos veces
# (reconexiones, reintentos, fallos de ack). Si el worker procesara dos
# veces, duplicaría la evaluación. La tabla `eventos_procesados` es la
# memoria del worker contra duplicados.
#
# Clave de deduplicación: event_id (o transaccion_id como fallback).
# La UNIQUE CONSTRAINT en la BD es la garantía final (no solo el código).

declare(strict_types=1);

namespace LoanService\Infrastructure;

use PDO;

/**
 * Registro de eventos ya procesados (idempotencia del consumidor).
 */
final class EventosProcesadosRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * ¿Este evento ya fue procesado?
     *
     * @param string $eventId ID único del evento
     *
     * @return bool true si ya está registrado
     */
    public function existe(string $eventId): bool
    {
        // Prepared statement: parámetro :eventId previene SQL injection.
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM eventos_procesados WHERE event_id = :eventId'
        );
        $stmt->execute(['eventId' => $eventId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Registra un evento como procesado (junto con su resultado).
     *
     * @param string $eventId        ID del evento
     * @param int    $puntaje        Puntaje calculado
     * @param string $recomendacion  Recomendación (APROBADO/EN_REVISION/NO_ELEGIBLE)
     */
    public function registrar(string $eventId, int $puntaje, string $recomendacion): void
    {
        // INSERT ... ON CONFLICT DO NOTHING: si otro worker (o un reintento)
        // ya insertó esta clave, esta ejecución es un no-op.
        $stmt = $this->pdo->prepare(
            'INSERT INTO eventos_procesados (event_id, puntaje, recomendacion, procesado_en)
             VALUES (:eventId, :puntaje, :recomendacion, NOW())
             ON CONFLICT (event_id) DO NOTHING'
        );
        $stmt->execute([
            'eventId' => $eventId,
            'puntaje' => $puntaje,
            'recomendacion' => $recomendacion,
        ]);
    }
}
```

## loan-service/tests/EvaluadorElegibilidadTest.php

```php
<?php
// loan-service/tests/EvaluadorElegibilidadTest.php — TDD de la regla de negocio
//
# El activo más valioso del loan-service es su lógica de puntaje.
# Se testea sin RabbitMQ, sin PDO, sin Docker: PHPUnit puro.

declare(strict_types=1);

namespace LoanService\Tests;

use LoanService\Application\EvaluadorElegibilidad;
use LoanService\Domain\ResultadoElegibilidad;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifica el motor de puntaje de elegibilidad.
 */
final class EvaluadorElegibilidadTest extends TestCase
{
    private EvaluadorElegibilidad $evaluador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluador = new EvaluadorElegibilidad();
    }

    #[Test]
    public function deposito_fuerte_mxn_con_balance_alto_aprueba(): void
    {
        // Evento típico: depósito de 3000 MXN, balance 8500 MXN.
        $evento = $this->eventoDeposito('3000.00', '8500.00');

        $resultado = $this->evaluador->evaluar($evento);

        // 30 (depósito alto) + 40 (balance alto) + 15 (MXN) = 85 → APROBADO
        self::assertSame(85, $resultado->puntaje);
        self::assertSame(ResultadoElegibilidad::APROBADO, $resultado->recomendacion);
        self::assertTrue($resultado->fueAprobado());
    }

    #[Test]
    public function deposito_bajo_rechaza(): void
    {
        // Depósito pequeño en USD con balance bajo.
        $evento = $this->eventoDeposito('50.00', '120.00', 'USD');

        $resultado = $this->evaluador->evaluar($evento);

        // 10 (depósito < 1000) + 0 (balance < 1000) + 0 (no MXN) = 10 → NO
        self::assertSame(10, $resultado->puntaje);
        self::assertSame(ResultadoElegibilidad::NO_ELEGIBLE, $resultado->recomendacion);
    }

    #[Test]
    public function retiro_penaliza_fuertemente(): void
    {
        // Un retiro grande destroza el puntaje aunque el balance sea alto.
        $evento = [
            'event_type' => 'transaccion.completada',
            'payload' => [
                'transaccion_id' => 'tx-retiro-1',
                'tipo' => 'retiro',
                'monto' => '2000.00',
                'moneda' => 'MXN',
                'balance_nuevo' => '4500.00',
            ],
        ];

        $resultado = $this->evaluador->evaluar($evento);

        // 0 (no es depósito) + 20 (balance 4500) + 15 (MXN) - 30 (retiro) = 5
        self::assertSame(5, $resultado->puntaje);
        self::assertSame(ResultadoElegibilidad::NO_ELEGIBLE, $resultado->recomendacion);
    }

    #[Test]
    public function evento_malformado_no_rompe(): void
    {
        // Un evento sin payload no debe tirar el worker.
        $resultado = $this->evaluador->evaluar([]);

        self::assertSame(ResultadoElegibilidad::NO_ELEGIBLE, $resultado->recomendacion);
        self::assertGreaterThanOrEqual(0, $resultado->puntaje);
        self::assertLessThanOrEqual(100, $resultado->puntaje);
    }

    #[Test]
    public function puntaje_limita_en_cien_y_cero(): void
    {
        // Evento extremo: monto y balance gigantescos nunca pasan de 100.
        $evento = $this->eventoDeposito('9999999.00', '9999999.00');

        $resultado = $this->evaluador->evaluar($evento);

        self::assertSame(100, $resultado->puntaje);

        // Y un retiro extremo nunca baja de 0.
        $eventoRetiro = [
            'payload' => [
                'tipo' => 'retiro',
                'monto' => '9999999.00',
                'moneda' => 'EUR',
                'balance_nuevo' => '0.00',
            ],
        ];

        $resultadoRetiro = $this->evaluador->evaluar($eventoRetiro);

        self::assertSame(0, $resultadoRetiro->puntaje);
    }

    /**
     * Construye un evento de depósito con los campos mínimos.
     *
     * @return array<string, mixed> Evento en formato contrato
     */
    private function eventoDeposito(string $monto, string $balance, string $moneda = 'MXN'): array
    {
        return [
            'event_id' => 'evt-' . uniqid(),
            'event_type' => 'transaccion.completada',
            'timestamp' => '2026-09-10T12:00:00Z',
            'payload' => [
                'transaccion_id' => 'tx-' . uniqid(),
                'billetera_id' => 'wal-1',
                'tipo' => 'deposito',
                'monto' => $monto,
                'moneda' => $moneda,
                'balance_nuevo' => $balance,
            ],
        ];
    }
}
```

## src/Application/CommandHandler/CrearBilleteraCommandHandler.php

```php
<?php
// src/Application/CommandHandler/CrearBilleteraCommandHandler.php — Use case handler
//
# El HANDLER de un Command es el USE CASE en su forma más pura:
# recibe un DTO (Command), ejecuta la lógica de aplicación y NO devuelve
# nada relacionado con HTTP.
#
# Flujo completo:
#   HTTP POST /api/v1/billeteras
#     → BilleteraController
#     → commandBus->dispatch(new CrearBilleteraCommand(...))   [CommandBus facade]
#     → command.bus (middlewares: validation, doctrine_transaction)
#     → CrearBilleteraCommandHandler::__invoke($command)
#     → retorna ID de la billetera (string)
#
# Reglas de capa:
#   - Solo usa puertos (interfaces), nunca implementaciones concretas
#   - Orquesta el dominio (Billetera), no duplica su lógica
#   - Publica eventos de dominio SIEMPRE después del flush

declare(strict_types=1);

namespace App\Application\CommandHandler;

use App\Domain\Billetera;
use App\Domain\Exception\MonedaNoSoportada;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Message\Command\CrearBilleteraCommand;
use App\Message\Event\BilleteraCreadaEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Maneja el comando "crear una billetera".
 *
 * La convención `__invoke` es la estándar de Symfony Messenger:
 * el bus llama al handler como si fuera una función.
 */
final readonly class CrearBilleteraCommandHandler
{
    /**
     * @param BilleteraRepository   $billeteras Puerto de persistencia (inyectado por interfaz)
     * @param MessageBusInterface   $eventBus   Bus de eventos (autowire: event.bus)
     */
    public function __construct(
        private BilleteraRepository $billeteras,
        private MessageBusInterface $eventBus,
    ) {
    }

    /**
     * Ejecuta el caso de uso.
     *
     * @param CrearBilleteraCommand $command El comando recibido del bus
     *
     * @return string ID UUID de la billetera creada
     *
     * @throws MonedaNoSoportada Si el código de moneda no existe en el sistema
     */
    public function __invoke(CrearBilleteraCommand $command): string
    {
        // 1. Convertir el código ISO del command en el enum de dominio.
        //    desdeCodigo() lanza \ValueError si no existe; lo traducimos
        //    a la excepción de dominio con el mensaje amigable.
        $moneda = self::monedaDesdeCodigo($command->moneda);

        // 2. Delegar la creación ENTERA al agregado.
        //    La Billetera es quien sabe cómo crearse a sí misma:
        //    inicia movimientos vacíos, registra timestamp.
        //    (Constructor público del dominio - Parte 1)
        $billetera = new Billetera(
            id: (string) Uuid::v4(),
            usuarioId: $command->usuarioId,
            moneda: $moneda,
        );

        // 3. Persistir a través del PUERTO (no sabemos si es Doctrine/Mongo).
        $this->billeteras->save($billetera);

        // 4. Publicar el evento de dominio DESPUÉS del flush.
        //    "Billetera creada" es un hecho consumado → va a RabbitMQ.
        //    Los consumidores (loan-service, notifications, audit) reaccionan solos.
        $evento = new BilleteraCreadaEvent(
            billeteraId: $billetera->id,
            usuarioId: $billetera->usuarioId,
            moneda: $billetera->moneda->value,
            creadoEn: $billetera->creadoEn->format('Y-m-d\TH:i:s\Z'),
            eventId: (string) Uuid::v4(),
        );

        // dispatch sobre event.bus → routing lo envía al transporte RabbitMQ
        $this->eventBus->dispatch($evento);

        // 5. Retornar el ID para que el controller responda 201 Created.
        return $billetera->id;
    }

    /**
     * Traduce \ValueError (nativo de enums) a MonedaNoSoportada (dominio).
     *
     * @param string $codigo Código ISO de moneda (ej: "MXN")
     *
     * @return Moneda Instancia del enum
     *
     * @throws MonedaNoSoportada Si el código no es soportado
     */
    private static function monedaDesdeCodigo(string $codigo): Moneda
    {
        $moneda = Moneda::tryFrom(strtoupper($codigo));

        if ($moneda === null) {
            throw new MonedaNoSoportada(
                codigoMoneda: $codigo,
                monedasSoportadas: array_column(Moneda::cases(), 'value'),
            );
        }

        return $moneda;
    }
}
```

## src/Application/CommandHandler/RealizarDepositoCommandHandler.php

```php
<?php
// src/Application/CommandHandler/RealizarDepositoCommandHandler.php — Use case handler
//
# Este handler muestra las 3 responsabilidades de un use case financiero:
#   1. IDEMPOTENCIA → verificar que el comando no se haya procesado antes
#   2. LÓGICA      → delegar en el agregado (depositar)
#   3. PROPAGACIÓN → publicar el evento de dominio a la cola
#
# Orden crítico de operaciones:
#   a. Verificar idempotencia ANTES de tocar la billetera (fail fast)
#   b. Persistir y luego publicar el evento (el evento lleva el balance FINAL)

declare(strict_types=1);

namespace App\Application\CommandHandler;

use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Domain\Repository\IdempotenciaRepository;
use App\Message\Command\RealizarDepositoCommand;
use App\Message\Event\TransaccionCompletadaEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Maneja el comando "realizar un depósito".
 *
 * Retorna el balance NUEVO como string decimal ("1550.00") para que
 * la respuesta HTTP sea útil de inmediato (sin query adicional).
 */
final readonly class RealizarDepositoCommandHandler
{
    /**
     * @param BilleteraRepository    $billeteras  Puerto de persistencia de billeteras
     * @param IdempotenciaRepository $idempotencia Puerto de claves idempotentes
     * @param MessageBusInterface    $eventBus     Bus de eventos (event.bus)
     */
    public function __construct(
        private BilleteraRepository $billeteras,
        private IdempotenciaRepository $idempotencia,
        private MessageBusInterface $eventBus,
    ) {
    }

    /**
     * Ejecuta el depósito de forma idempotente.
     *
     * @param RealizarDepositoCommand $command El comando del bus
     *
     * @return string Balance nuevo en formato decimal ("1550.00")
     *
     * @throws BilleteraNoEncontrada Si la billetera no existe
     */
    public function __invoke(RealizarDepositoCommand $command): string
    {
        // 1. CLAVE DE IDEMPOTENCIA: única por operación de negocio.
        //    Si el cliente reenvía el mismo comando (timeout, reintento),
        //    la clave se repite → detectamos y NO duplicamos el depósito.
        $clave = $command->claveUnica();

        // 2. Localizar la billetera.
        $billetera = $this->billeteras->findById($command->billeteraId);

        // 2b. Si no existe, el caso de uso no puede continuar.
        if ($billetera === null) {
            throw new BilleteraNoEncontrada($command->billeteraId);
        }

        // 3. PASO IDEMPOTENTE: si esta clave ya se procesó, retornamos
        //    el balance actual SIN ejecutar nada. Así el cliente obtiene
        //    exactamente la misma respuesta que la primera vez.
        if ($this->idempotencia->existe($clave)) {
            return $billetera->balance()->formateadoDecimal();
        }

        // 4. Construir el Value Object Dinero (valida formato y negatividad).
        //    La moneda la tomamos del command; depositar() verifica que
        //    coincida con la moneda de la billetera (regla de dominio).
        $moneda = Moneda::desdeCodigo($command->moneda);
        $dinero = Dinero::crear($command->monto, $moneda);

        // 5. Delegar la mutación al agregado.
        //    Billetera::depositar() valida: monto > 0, misma moneda,
        //    y registra el movimiento en su historial.
        $movimiento = $billetera->depositar($dinero, $command->descripcion);

        // 6. Persistir el nuevo estado (INSERT movimiento + UPDATE billetera).
        $this->billeteras->save($billetera);

        // 7. Registrar la clave como procesada (después del flush exitoso).
        //    Si el flush fallara, la clave NO se marca → reintento posible.
        $this->idempotencia->registrar($clave);

        // 8. Publicar el evento de dominio. Todo el ecosistema se entera:
        //    loan-service actualiza elegibilidad, auditoría registra,
        //    analytics mide. El wallet-service no conoce a ninguno.
        $balanceNuevo = $billetera->balance();
        $this->eventBus->dispatch(
            new TransaccionCompletadaEvent(
                transaccionId: $movimiento->id,
                billeteraId: $billetera->id,
                tipo: 'deposito',
                monto: $dinero->formateadoDecimal(),
                moneda: $moneda->value,
                balanceNuevo: $balanceNuevo->formateadoDecimal(),
                timestamp: $movimiento->creadoEn->format('Y-m-d\TH:i:s\Z'),
            ),
        );

        // 9. Respuesta útil para el controller: balance actualizado.
        return $balanceNuevo->formateadoDecimal();
    }
}
```

## src/Application/Exception/BilleteraNoEncontrada.php

```php
<?php
// src/Application/Exception/BilleteraNoEncontrada.php — Excepción de capa de aplicación
//
# Diferencia con las excepciones de DOMINIO:
#   - Domain: reglas de negocio (FondosInsuficientes, MonedaNoSoportada)
#   - Application: problemas de ejecución del caso de uso (recurso no existe)
#
# El controller convierte esta excepción en un HTTP 404.

declare(strict_types=1);

namespace App\Application\Exception;

/**
 * Se lanza cuando un caso de uso no encuentra la billetera solicitada.
 */
final class BilleteraNoEncontrada extends \DomainException
{
    public function __construct(string $billeteraId)
    {
        parent::__construct(
            "No se encontró la billetera con ID '{$billeteraId}'."
        );
    }
}
```

## src/Application/QueryHandler/ObtenerBilleteraQueryHandler.php

```php
<?php
// src/Application/QueryHandler/ObtenerBilleteraQueryHandler.php — Query handler
//
# Los QUERY HANDLERS NUNCA modifican estado. Solo leen y modelan la respuesta.
#
# Si CQRS se lleva al extremo, la lectura ni siquiera toca la BD de escritura:
# se usa un "read model" (proyección) o caché. En este curso, leemos
# del mismo repositorio para mantener la sencillez, PERO la separación
# de buses ya nos permite migrar a un read model sin tocar controllers.
#
# El retorno es un ARRAY (snapshot), no la entidad. ¿Por qué?
#   - La entidad expone comportamiento; el snapshot expone datos
#   - El controller serializa el array directamente a JSON
#   - El consumidor (HTTP client) no puede mutar la entidad

declare(strict_types=1);

namespace App\Application\QueryHandler;

use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Repository\BilleteraRepository;
use App\Message\Query\ObtenerBilleteraQuery;

/**
 * Maneja la consulta "dame la billetera X" (solo lectura).
 */
final readonly class ObtenerBilleteraQueryHandler
{
    public function __construct(
        private BilleteraRepository $billeteras,
    ) {
    }

    /**
     * Ejecuta la consulta y devuelve un snapshot serializable.
     *
     * @param ObtenerBilleteraQuery $query La consulta del bus
     *
     * @return array<string, string|int> Snapshot de la billetera
     *
     * @throws BilleteraNoEncontrada Si no existe
     */
    public function __invoke(ObtenerBilleteraQuery $query): array
    {
        $billetera = $this->billeteras->findById($query->billeteraId);

        if ($billetera === null) {
            throw new BilleteraNoEncontrada($query->billeteraId);
        }

        // Modelo el snapshot con las claves EXACTAS que el API expone.
        // El namespace del JSON (camel_case) es el contrato público.
        return [
            'id' => $billetera->id,
            'usuario_id' => $billetera->usuarioId,
            'moneda' => $billetera->moneda->value,
            'balance' => $billetera->balance()->formateadoDecimal(),
            'total_movimientos' => $billetera->totalMovimientos(),
        ];
    }
}
```

## src/Controller/BilleteraController.php

```php
<?php
// src/Controller/BilleteraController.php — Controller API v3 (CQRS)
//
# Modificado en la Parte 3: el controller ya NO toca repositorios ni
# servicios de aplicación directamente. Ahora es un TRANSLATOR:
#   HTTP JSON → Command/Query → bus → handler → resultado → HTTP JSON
#
# Responsabilidades que CONSERVA:
#   - Parsear el request (validar tipos básicos)
#   - Mapear a Command/Query
#   - Traducir resultados a respuestas HTTP (201, 200, 400, 404)
#
# Responsabilidades que CEDE:
#   - Reglas de negocio → dominio (Billetera)
#   - Persistencia → repositorios (infraestructura)
#   - Validación profunda → middlewares/handlers

declare(strict_types=1);

namespace App\Controller;

use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Exception\FondosInsuficientes;
use App\Domain\Exception\MonedaNoSoportada;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\QueryBus;
use App\Message\Command\CrearBilleteraCommand;
use App\Message\Command\RealizarDepositoCommand;
use App\Message\Query\ObtenerBilleteraQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * API REST de billeteras sobre buses CQRS.
 */
final class BilleteraController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {
    }

    /**
     * POST /api/v1/billeteras — Crear una billetera.
     *
     * @param Request $request Request HTTP
     *
     * @return JsonResponse 201 con la billetera creada
     */
    #[Route('/api/v1/billeteras', name: 'billetera_crear', methods: ['POST'])]
    public function crear(Request $request): JsonResponse
    {
        // 1. Leer el body JSON como array asociativo.
        //    json_decode con true → array; si el body es inválido → null.
        $datos = json_decode($request->getContent(), true);

        // 2. Validación de contrato MÍNIMA en el controller:
        //    el resto de la validación vive en handlers/dominio.
        if (!is_array($datos)
            || empty($datos['usuario_id'])
            || empty($datos['moneda'])
        ) {
            return new JsonResponse(
                [
                    'error' => 'Solicitud inválida',
                    'detalle' => 'Campos requeridos: usuario_id (string), moneda (string)',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            // 3. Construir el command y despacharlo en el bus de comandos.
            //    HandleTrait nos devuelve la ID de la billetera creada.
            $billeteraId = $this->commandBus->dispatch(
                CrearBilleteraCommand::crear(
                    usuarioId: $datos['usuario_id'],
                    moneda: strtoupper($datos['moneda']),
                ),
            );
        } catch (MonedaNoSoportada $e) {
            // 4b. Dominio rechazó la moneda → 400 con detalle legible.
            return new JsonResponse(
                ['error' => 'Moneda no soportada', 'detalle' => $e->getMessage()],
                Response::HTTP_BAD_REQUEST,
            );
        }

        // 4. Éxito → 201 Created con Location + ID.
        return new JsonResponse(
            ['id' => $billeteraId],
            Response::HTTP_CREATED,
            ['Location' => "/api/v1/billeteras/{$billeteraId}"],
        );
    }

    /**
     * POST /api/v1/billeteras/{id}/deposito — Depositar saldo.
     *
     * @param string  $id      ID de la billetera (ruta)
     * @param Request $request Request HTTP
     *
     * @return JsonResponse 200 con el balance actualizado
     */
    #[Route('/api/v1/billeteras/{id}/deposito', name: 'billetera_depositar', methods: ['POST'])]
    public function depositar(string $id, Request $request): JsonResponse
    {
        $datos = json_decode($request->getContent(), true);

        if (!is_array($datos)
            || !isset($datos['monto'])
            || !isset($datos['moneda'])
        ) {
            return new JsonResponse(
                [
                    'error' => 'Solicitud inválida',
                    'detalle' => 'Campos requeridos: monto (string), moneda (string), '
                        . 'opcional: descripcion, idempotency_key',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            // Despachar el command; retorna el balance NUEVO como string.
            $balanceNuevo = $this->commandBus->dispatch(
                RealizarDepositoCommand::crear(
                    billeteraId: $id,
                    monto: (string) $datos['monto'],
                    moneda: strtoupper((string) $datos['moneda']),
                    descripcion: $datos['descripcion'] ?? 'Depósito API',
                    idempotencyKey: $datos['idempotency_key'] ?? '',
                ),
            );
        } catch (BilleteraNoEncontrada $e) {
            return new JsonResponse(
                ['error' => 'Billetera no encontrada', 'detalle' => $e->getMessage()],
                Response::HTTP_NOT_FOUND,
            );
        } catch (FondosInsuficientes|\InvalidArgumentException $e) {
            // FondosInsuficientes podría darse en retiros; aquí por robustez.
            // InvalidArgumentException: monto cero, moneda distinta, formato malo.
            return new JsonResponse(
                ['error' => 'Operación rechazada', 'detalle' => $e->getMessage()],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return new JsonResponse(
            ['balance_nuevo' => $balanceNuevo],
        );
    }

    /**
     * GET /api/v1/billeteras/{id} — Obtener billetera.
     *
     * @param string $id ID de la billetera (ruta)
     *
     * @return JsonResponse 200 con el snapshot
     */
    #[Route('/api/v1/billeteras/{id}', name: 'billetera_obtener', methods: ['GET'])]
    public function obtener(string $id): JsonResponse
    {
        // Solo lectura: resultado del query handler (array snapshot).
        $snapshot = $this->queryBus->ask(new ObtenerBilleteraQuery($id));

        if ($snapshot === null) {
            return new JsonResponse(
                ['error' => 'Billetera no encontrada'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse($snapshot);
    }
}
```

## src/Domain/Repository/BilleteraRepository.php

```php
<?php
// src/Domain/Repository/BilleteraRepository.php — PUERTO (interfaz) de persistencia
//
# En arquitectura hexagonal, los PUERTOS (ports) definen los contratos
# que la capa de aplicación necesita de la infraestructura.
#
# Aquí NO hay implementación. Solo la firma de los métodos.
# La implementación concreta vive en Infrastructure\Repository.
#
# ¿Por qué el puerto vive en src/Domain/ y la implementación en src/Infrastructure/?
# Porque la Regla de Dependencia dice: "las dependencias apuntan HACIA DENTRO".
#
#   Capa Aplicación (use cases / handlers)  → usa el PUERTO
#   Capa Infraestructura (Doctrine)         → implementa el PUERTO
#
# Ventajas:
#   1. Los use cases no saben si los datos viven en PostgreSQL, MongoDB o memoria
#   2. Los tests unitarios pueden usar un puerto FALSO (in-memory) sin BD
#   3. Cambiar de ORM no toca la capa de aplicación

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Billetera;

/**
 * Contrato de persistencia para el agregado Billetera.
 *
 * Cualquier clase que `implements` esta interfaz ES un repositorio
 * de billeteras, sin importar la tecnología detrás.
 */
interface BilleteraRepository
{
    /**
     * Guarda (inserta o actualiza) una billetera.
     *
     * @param Billetera $billetera La billetera a persistir
     */
    public function save(Billetera $billetera): void;

    /**
     * Busca una billetera por su ID.
     *
     * @param string $id ID UUID de la billetera
     *
     * @return Billetera|null La billetera o null si no existe
     */
    public function findById(string $id): ?Billetera;
}
```

## src/Domain/Repository/IdempotenciaRepository.php

```php
<?php
// src/Domain/Repository/IdempotenciaRepository.php — PUERTO de idempotencia
//
# La idempotencia es el "cinturón de seguridad" de los sistemas financieros.
# Permite procesar el MISMO comando N veces con el mismo resultado,
# sin duplicar transacciones.
#
# La tabla `idempotencia` guarda las claves ya procesadas.
# Este puerto define el contrato mínimo necesario.

declare(strict_types=1);

namespace App\Domain\Repository;

/**
 * Contrato de persistencia para claves de idempotencia.
 */
interface IdempotenciaRepository
{
    /**
     * ¿Existe ya esta clave en la tabla de idempotencia?
     *
     * @param string $clave Clave única de la operación
     *
     * @return bool true si la operación ya se procesó
     */
    public function existe(string $clave): bool;

    /**
     * Registra una clave como procesada.
     *
     * Este método debe ser INCREMENTAL: si la clave ya existe,
     * simplemente no hace nada (o no lanza error).
     *
     * @param string $clave Clave única de la operación
     */
    public function registrar(string $clave): void;
}
```

## src/Infrastructure/Bus/CommandBus.php

```php
<?php
// src/Infrastructure/Bus/CommandBus.php — Fachada del command bus con HandleTrait
//
# PROBLEMA: MessageBus::dispatch() retorna siempre un Envelope, no el
# resultado del handler. El controller tendría que extraer el HandledStamp.
#
# SOLUCIÓN: Implementamos una fachada con HandleTrait. Symfony incluye
# este trait justamente para este caso:
#
#   HandleTrait::handle($message)
#     → dispatch($message)
#     → espera el HandledStamp que deja HandleMessageMiddleware
#     → retorna el VALOR DE RETORNO del handler (string, array, ...)
#
# Así el controller puede hacer:
#   $id = $commandBus->dispatch(new CrearBilleteraCommand(...));
# y recibe la ID directamente, sin desglosar envelopes.

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fachada de uso cómodo para el bus de comandos.
 *
 * Uso:
 *   $id = $commandBus->dispatch(new CrearBilleteraCommand(...));
 */
final class CommandBus
{
    use HandleTrait;

    /**
     * @param MessageBusInterface $commandBus El bus "command.bus" (autowire por nombre)
     */
    public function __construct(MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;
    }

    /**
     * Despacha el command y retorna el valor del handler.
     *
     * @param object $command El command a ejecutar
     *
     * @return mixed Resultado del handler (string, array, void...)
     */
    public function dispatch(object $command): mixed
    {
        return $this->handle($command);
    }
}
```

## src/Infrastructure/Bus/QueryBus.php

```php
<?php
// src/Infrastructure/Bus/QueryBus.php — Fachada del query bus con HandleTrait
//
# Misma técnica que CommandBus, para preguntas de solo lectura.
# Separar estas dos fachadas hace EXPLÍCITO el patrón CQRS en el código:
#   - ¿Quiero cambiar estado?  → inyecto CommandBus
#   - ¿Quiero leer datos?      → inyecto QueryBus
#
# Los controllers ya no dependen de "el bus mágico" sino de la fachada
# que comunica su intención en el propio nombre del parámetro.

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fachada de uso cómodo para el bus de queries.
 *
 * Uso:
 *   $datos = $queryBus->ask(new ObtenerBilleteraQuery($id));
 */
final class QueryBus
{
    use HandleTrait;

    /**
     * @param MessageBusInterface $queryBus El bus "query.bus" (autowire por nombre)
     */
    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    /**
     * Ejecuta la query y retorna el resultado del handler.
     *
     * @param object $query La query de lectura
     *
     * @return mixed Snapshot de datos (array, scalar...)
     */
    public function ask(object $query): mixed
    {
        return $this->handle($query);
    }
}
```

## src/Infrastructure/Repository/BilleteraRepository.php

```php
<?php
// src/Infrastructure/Repository/BilleteraRepository.php — Adapter de Doctrine (Parte 3)
//
# MODIFICADO en la Parte 3: ahora `implements` el puerto de dominio.
# La Parte 2 lo dejaba como clase libre; aquí formalizamos el contrato.
#
# Diferencia conceptual:
#   - Antes:  el controller usaba esta clase concreta (acoplamiento)
#   - Ahora:  el handler usa App\Domain\Repository\BilleteraRepository (interfaz)
#             y Symfony inyecta esta implementación automáticamente (autowiring)
#
# Patrón ADAPTER: convierte la API de Doctrine en la API que el dominio espera.

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Billetera;
use App\Domain\Repository\BilleteraRepository as BilleteraRepositoryPort;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Implementación concreta del puerto BilleteraRepository usando Doctrine ORM.
 *
 * @extends ServiceEntityRepository<Billetera>
 */
final class BilleteraRepository extends ServiceEntityRepository implements BilleteraRepositoryPort
{
    /**
     * @param ManagerRegistry $registry Registry de Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Billetera::class);
    }

    /**
     * Guarda una billetera (INSERT o UPDATE según tenga ID o no).
     *
     * @param Billetera $billetera La billetera a persistir
     */
    public function save(Billetera $billetera): void
    {
        $this->getEntityManager()->persist($billetera);
        $this->getEntityManager()->flush();
    }

    /**
     * Busca una billetera por su ID UUID.
     *
     * @param string $id ID de la billetera
     *
     * @return Billetera|null La billetera encontrada o null
     */
    public function findById(string $id): ?Billetera
    {
        return $this->find($id);
    }
}
```

## src/Infrastructure/Repository/DoctrineIdempotenciaRepository.php

```php
<?php
// src/Infrastructure/Repository/DoctrineIdempotenciaRepository.php — Adapter de idempotencia
//
# Uso SQL nativo (no ORM) porque la tabla `idempotencia` es una simple
# clave + timestamp. No necesita mapeo de entidad.
#
# La UNIQUE CONSTRAINT de la columna `clave` es la verdadera guardia:
#   - Usamos INSERT ... ON CONFLICT DO NOTHING (PostgreSQL)
#   - Si la clave ya existe, la inserción simplemente no hace nada
#   - Esto hace a registrar() idempotente por diseño

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\IdempotenciaRepository;
use Doctrine\ORM\EntityManagerInterface;
use DateTimeImmutable;

/**
 * Implementación del puerto IdempotenciaRepository sobre PostgreSQL.
 */
final class DoctrineIdempotenciaRepository implements IdempotenciaRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function existe(string $clave): bool
    {
        // getConnection(): acceso directo a la conexión PDO/DBAL.
        $connection = $this->entityManager->getConnection();

        // Parámetro :clave evita SQL injection (prepared statement).
        // fetchOne() retorna el primer valor de la primera fila, o false si no hay.
        $resultado = $connection
            ->executeQuery(
                'SELECT 1 FROM idempotencia WHERE clave = :clave',
                ['clave' => $clave],
            )
            ->fetchOne();

        // false → no existe. Cualquier otro valor (1) → existe.
        return $resultado !== false;
    }

    /**
     * {@inheritDoc}
     */
    public function registrar(string $clave): void
    {
        $connection = $this->entityManager->getConnection();

        // INSERT ... ON CONFLICT DO NOTHING:
        //   - PostgreSQL específico (compatible con nuestra BD)
        //   - Si `clave` ya existe (UNIQUE), la operación es un no-op
        //   - Evita la carrera entre `existe()` y `insert()` en concurrencia
        $connection->executeStatement(
            'INSERT INTO idempotencia (clave, procesado_en)
             VALUES (:clave, :procesadoEn)
             ON CONFLICT (clave) DO NOTHING',
            [
                'clave' => $clave,
                'procesadoEn' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }
}
```

## src/Infrastructure/Serializer/EventoSerializer.php

```php
<?php
// src/Infrastructure/Serializer/EventoSerializer.php — Serializador de eventos (contrato)
//
# ¿POR QUÉ un serializador custom?
#
# Por defecto, Symfony Messenger serializa los mensajes con el formato
# nativo de PHP (o JSON de su serializador interno) y los envuelve en un
# ENVELOPE con headers. El mensaje en la cola se ve así:
#
#   {"body":"O:36:\"App\\Message\\Event\\...\":{...}","headers":{"type":"App\\Message\\Event\\..."}}
#
# PROBLEMA: esto acopla el contrato de la cola a clases de PHP de Symfony.
# Un consumidor en Python, Go o (como en nuestro curso) PHP plano NO puede
# leer ese formato.
#
# SOLUCIÓN CONTRACT-FIRST: el cuerpo del mensaje en RabbitMQ debe ser JSON
# puro con un schema público y documentado:
#
#   {
#     "event_id": "uuid",
#     "event_type": "billetera.creada",
#     "version": "1.0",
#     "timestamp": "2026-09-10T12:00:00Z",
#     "payload": { ... }
#   }
#
# Así CUALQUIER tecnología puede consumir nuestros eventos. El transport
# es un detalle de implementación; el CONTRATO es el JSON.

declare(strict_types=1);

namespace App\Infrastructure\Serializer;

use App\Message\Event\BilleteraCreadaEvent;
use App\Message\Event\TransaccionCompletadaEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Convierte eventos de dominio a JSON público (encode) y viceversa (decode).
 */
final class EventoSerializer implements SerializerInterface
{
    /**
     * ENCODE: transport → RabbitMQ.
     *
     * Convierte el evento en el cuerpo JSON que viajará por la cola.
     *
     * @param Envelope $envelope El envelope que contiene el evento
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        // Los eventos exponen toJson(); cualquier otro mensaje es error.
        if (!$message instanceof BilleteraCreadaEvent
            && !$message instanceof TransaccionCompletadaEvent
        ) {
            throw new \LogicException(
                'EventoSerializer solo soporta eventos de dominio. '
                . 'Recibió: ' . $message::class
            );
        }

        // headers: metadata estándar que Symfony añade al envelope.
        // El TYPE permite que decode() (y otras herramientas) sepan qué es.
        return [
            'body' => $message->toJson(),
            'headers' => [
                'type' => $message->eventType,
                'Content-Type' => 'application/json',
            ],
        ];
    }

    /**
     * DECODE: RabbitMQ → objeto PHP.
     *
     * Se usa cuando el WALLET-SERVICE consume sus propios eventos
     * (procesos internos como auditoría). El loan-service NO usa
     * este método: parsea el JSON directamente con php-amqplib.
     *
     * @param array<string, mixed> $encodedChunk El {"body","headers"} recibido
     *
     * @return Envelope Envelope con el evento reconstruido
     */
    public function decode(array $encodedChunk): Envelope
    {
        $body = json_decode($encodedChunk['body'], true, 512, JSON_THROW_ON_ERROR);
        $payload = $body['payload'] ?? [];

        // Reconstruir la clase según event_type (contrato versionado).
        return match ($body['event_type'] ?? '') {
            'billetera.creada' => new Envelope(
                new BilleteraCreadaEvent(
                    billeteraId: $payload['billetera_id'],
                    usuarioId: $payload['usuario_id'],
                    moneda: $payload['moneda'],
                    creadoEn: $body['timestamp'] ?? '',
                    eventId: $body['event_id'] ?? '',
                ),
            ),
            'transaccion.completada' => new Envelope(
                new TransaccionCompletadaEvent(
                    transaccionId: $payload['transaccion_id'],
                    billeteraId: $payload['billetera_id'],
                    tipo: $payload['tipo'],
                    monto: $payload['monto'],
                    moneda: $payload['moneda'],
                    balanceNuevo: $payload['balance_nuevo'],
                    timestamp: $body['timestamp'] ?? '',
                ),
            ),
            default => throw new \LogicException(
                'Tipo de evento desconocido: ' . ($body['event_type'] ?? 'null')
            ),
        };
    }
}
```

## src/Message/Command/CrearBilleteraCommand.php

```php
<?php
// src/Message/Command/CrearBilleteraCommand.php — Command de CQRS
//
# COMMAND: Representa una INTENCIÓN de cambiar el estado del sistema.
#
# Diferencia clave entre Command y Query:
#   - Command: "Haz algo" → Cambia el estado → Retorna SOLO confirmación (void o ID)
#   - Query:   "Dame datos" → NO cambia el estado → Retorna datos
#
# Ejemplos de Commands en PrestaFlow:
#   - CrearBilleteraCommand
#   - RealizarDepositoCommand
#   - RealizarRetiroCommand
#   - AprobarPrestamoCommand
#
# ¿Por qué Commands y Queries separados?
#   1. CQRS (Command Query Responsibility Segregation)
#   2. Optimización: el query side puede usar una BD leída optimizada
#   3. Escalabilidad: el command side puede escalar horizontalmente
#   4. Historial: los commands se pueden re-ejecutar (event sourcing)
#
# IMPORTANTE: Un Command es un DTO (Data Transfer Object).
# - Es inmutable (readonly)
# - No contiene lógica
# - Solo lleva datos + metadata
# - Es serializable (se envía a través de RabbitMQ)

declare(strict_types=1);

namespace App\Message\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Command: crear una billetera nueva.
 *
 * Este mensaje se envía al Message Bus (Dispatcher).
 * El bus lo enruta al CommandHandler correspondiente.
 *
 * Ejemplo de flujo:
 *   POST /api/v1/billeteras
 *     → BilleteraController
 *     → messageBus->dispatch(new CrearBilleteraCommand(...))
 *     → MessageBus enruta a CrearBilleteraHandler
 *     → Handler ejecuta: validar moneda + crear + persistir
 *     → Retorna el ID de la billetera creada
 */
final readonly class CrearBilleteraCommand
{
    /**
     * @param string $usuarioId ID del propietario de la billetera
     * @param string $moneda    Código ISO de moneda (MXN, USD, EUR)
     * @param string $commandId ID único del command (para idempotencia/tracing)
     */
    public function __construct(
        public string $usuarioId,
        public string $moneda,
        public string $commandId = '',
    ) {
        // Si no se pasó commandId, generamos uno automáticamente.
        // Cómo asignar un default en un readonly constructor:
        // Esta es una técnica de "constructor promotion con default dinámico".
    }

    /**
     * Factory method: crea un command con commandId automático.
     *
     * @return self Command con commandId UUID v4
     */
    public static function crear(string $usuarioId, string $moneda): self
    {
        return new self(
            usuarioId: $usuarioId,
            moneda: $moneda,
            commandId: (string) Uuid::v4(),
        );
    }
}
```

## src/Message/Command/RealizarDepositoCommand.php

```php
<?php
// src/Message/Command/RealizarDepositoCommand.php — Command de CQRS
//
# Command: registrar un depósito en una billetera.

declare(strict_types=1);

namespace App\Message\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Command: realizar un depósito en una billetera.
 *
 * Este command lleva TODA la información necesaria para procesar el depósito:
 *   - Qué billetera (id)
 *   - Cuánto (monto)
 *   - En qué moneda
 *   - Idempotencia (idempotencyKey)
 */
final readonly class RealizarDepositoCommand
{
    /**
     * @param string      $billeteraId   ID de la billetera destino
     * @param string      $monto         Monto en formato decimal (ej: "1500.00")
     * @param string      $moneda        Código ISO de moneda
     * @param string      $descripcion   Descripción legible
     * @param string      $idempotencyKey Clave única para evitar duplicados
     * @param string      $commandId     ID del command (tracing)
     */
    public function __construct(
        public readonly string $billeteraId,
        public readonly string $monto,
        public readonly string $moneda,
        public readonly string $descripcion,
        public readonly string $idempotencyKey = '',
        public readonly string $commandId = '',
    ) {
    }

    /**
     * Factory method con generación automática de claves.
     *
     * @param string $idempotencyKey Clave de idempotencia del cliente
     * @return self Command listo para despachar
     */
    public static function crear(
        string $billeteraId,
        string $monto,
        string $moneda,
        string $descripcion,
        string $idempotencyKey,
    ): self {
        return new self(
            billeteraId: $billeteraId,
            monto: $monto,
            moneda: $moneda,
            descripcion: $descripcion,
            idempotencyKey: $idempotencyKey,
            commandId: (string) Uuid::v4(),
        );
    }

    /**
     * Genera una clave de idempotencia automática si el cliente no la provee.
     *
     * @return string Clave de idempotencia
     */
    public function claveUnica(): string
    {
        // Si el cliente no envió idempotency_key, generamos una
        // basada en el commandId (siempre es único).
        return $this->idempotencyKey !== ''
            ? $this->idempotencyKey
            : $this->commandId;
    }
}
```

## src/Message/Event/BilleteraCreadaEvent.php

```php
<?php
// src/Message/Event/BilleteraCreadaEvent.php — Event de dominio
//
# EVENT: Algo que YA OCURRIÓ en el sistema.
#
# Diferencia clave entre Command y Event:
#   - Command: "Haz esto" (intención, puede fallar)
#   - Event: "Esto pasó" (hecho consumado, no puede fallar)
#
# La arquitectura orientada a eventos (EDA):
#
#   ┌──────────────┐  publica   ┌─────────┐  consume  ┌──────────────────┐
#   │ wallet-service│ ─────────▶ │ RabbitMQ│ ────────▶ │ loan-service      │
#   │ (productor)  │  evento    │ (broker)│           │ (consumidor)     │
#   └──────────────┘            └─────────┘           └──────────────────┘
#
#   El productor NO sabe quién consume. Solo publica.
#   El consumidor NO sabe quién publicó. Solo recibe.
#   Esto desacopla los microservicios por completo.

declare(strict_types=1);

namespace App\Message\Event;

/**
 * Event: una billetera fue creada exitosamente.
 *
 * Este evento se publica en RabbitMQ para que OTROS servicios
 * reaccionen (notifications, audit, analytics).
 *
 * El event payload debe ser AUTOSUFICIENTE:
 *   - No debe requerir que el consumidor haga llamadas extra
 *   - Debe incluir todos los datos relevantes
 *   - El consumidor puede actuar sin consultar la BD del productor
 */
final readonly class BilleteraCreadaEvent
{
    /**
     * @param string      $billeteraId ID de la billetera creada
     * @param string      $usuarioId   ID del propietario
     * @param string      $moneda      Moneda de la billetera
     * @param string      $creadoEn    Timestamp ISO 8601 de creación
     * @param string      $eventId     ID único del evento (para deduplicación)
     * @param string      $eventType   Tipo del evento (versionamiento)
     * @param string      $version     Versión del schema del evento
     */
    public function __construct(
        public readonly string $billeteraId,
        public readonly string $usuarioId,
        public readonly string $moneda,
        public readonly string $creadoEn,
        public readonly string $eventId = '',
        public readonly string $eventType = 'billetera.creada',
        public readonly string $version = '1.0',
    ) {
    }

    /**
     * Convierte el evento a formato JSON (para RabbitMQ).
     *
     * @return string JSON string del evento
     */
    public function toJson(): string
    {
        // json_encode: convierte el array asociativo a JSON string.
        // JSON_THROW_ON_ERROR: si falla, lanza excepción (nunca falla silenciosamente).
        return json_encode([
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'version' => $this->version,
            'timestamp' => $this->creadoEn,
            'payload' => [
                'billetera_id' => $this->billeteraId,
                'usuario_id' => $this->usuarioId,
                'moneda' => $this->moneda,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
```

## src/Message/Event/TransaccionCompletadaEvent.php

```php
<?php
// src/Message/Event/TransaccionCompletadaEvent.php — Event de dominio
//
# Evento publicado DESPUÉS de que una transacción se completó exitosamente.
# Este es el evento más importante del sistema: dispara notificaciones,
# auditoría, reconciliación, y análisis.

declare(strict_types=1);

namespace App\Message\Event;

/**
 * Event: una transacción financiera fue completada con éxito.
 *
 * Este evento propaga el hecho a todo el ecosistema:
 *   - Notification Service: envía Email/SMS al usuario
 *   - Audit Service: registra la transacción para auditoría
 *   - Loan Service: actualiza elegibilidad de préstamos
 *   - Analytics: métricas de uso
 */
final readonly class TransaccionCompletadaEvent
{
    /**
     * @param string $transaccionId ID de la transacción
     * @param string $billeteraId   ID de la billetera
     * @param string $tipo          Tipo: "deposito" o "retiro"
     * @param string $monto         Monto en formato decimal
     * @param string $moneda        Código ISO de moneda
     * @param string $balanceNuevo  Balance posterior a la transacción
     * @param string $timestamp     Timestamp ISO 8601
     * @param string $eventType     Tipo del evento (para versionamiento)
     */
    public function __construct(
        public readonly string $transaccionId,
        public readonly string $billeteraId,
        public readonly string $tipo,
        public readonly string $monto,
        public readonly string $moneda,
        public readonly string $balanceNuevo,
        public readonly string $timestamp,
        public readonly string $eventType = 'transaccion.completada',
    ) {
    }

    /**
     * Convierte el evento a JSON para RabbitMQ.
     *
     * @return string JSON string
     */
    public function toJson(): string
    {
        return json_encode([
            'event_type' => $this->eventType,
            'timestamp' => $this->timestamp,
            'payload' => [
                'transaccion_id' => $this->transaccionId,
                'billetera_id' => $this->billeteraId,
                'tipo' => $this->tipo,
                'monto' => $this->monto,
                'moneda' => $this->moneda,
                'balance_nuevo' => $this->balanceNuevo,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
```

## src/Message/Query/ObtenerBilleteraQuery.php

```php
<?php
// src/Message/Query/ObtenerBilleteraQuery.php — Query de CQRS
//
# QUERY: Representa una CONSULTA (lectura) del estado del sistema.
#
# Diferencias con Command:
#   - Command: modifica el estado (INSERT/UPDATE/DELETE)
#   - Query: solo lee (SELECT)
#
# Beneficio clave de separar Commands y Queries:
#   - Commands pueden ser validados y auditados (¿quién hizo esto?)
#   - Queries pueden ser cacheadas (resultado de una query no cambia
#     necesariamente en cada llamada)

declare(strict_types=1);

namespace App\Message\Query;

/**
 * Query: obtener los datos de una billetera por su ID.
 *
 * El QueryHandler retorna un QueryResult con los datos.
 */
final readonly class ObtenerBilleteraQuery
{
    public function __construct(
        public string $billeteraId,
    ) {
    }
}
```

## tests/Unit/Application/CrearBilleteraCommandHandlerTest.php

```php
<?php
// tests/Unit/Application/CrearBilleteraCommandHandlerTest.php — Test del use case
//
# TDD: probamos el handler SIN base de datos, SIN Doctrine, SIN RabbitMQ.
# El puerto BilleteraRepository se sustituye por un FAKE (clase anónima).
# Esto prueba que el handler es verdaderamente independiente de la
# infraestructura (arquitectura hexagonal en acción).

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\CommandHandler\CrearBilleteraCommandHandler;
use App\Domain\Billetera;
use App\Domain\Exception\MonedaNoSoportada;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Message\Command\CrearBilleteraCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Verifica el caso de uso "crear billetera" de forma aislada.
 */
final class CrearBilleteraCommandHandlerTest extends TestCase
{
    private array $guardadas = [];
    private MessageBusInterface $eventBus;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake del event bus: captura los eventos despachados sin enviarlos.
        $this->eventBus = new class implements MessageBusInterface {
            public array $despachados = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->despachados[] = $message;

                return new Envelope($message);
            }
        };
    }

    #[Test]
    public function crea_billetera_y_retorna_id(): void
    {
        // Prepara un repositorio fake que guarda en memoria.
        $repositorio = $this->repositorioFake();

        // GIVEN: un command para crear una billetera MXN de la usuaria "ana".
        $command = CrearBilleteraCommand::crear(
            usuarioId: 'ana',
            moneda: 'MXN',
        );

        // WHEN: se ejecuta el handler.
        $handler = new CrearBilleteraCommandHandler($repositorio, $this->eventBus);
        $id = $handler($command);

        // THEN: se retornó un ID no vacío.
        self::assertNotEmpty($id);

        // THEN: la billetera fue persistida con los datos correctos.
        self::assertCount(1, $this->guardadas);
        $billeteraGuardada = $this->guardadas[0];
        self::assertSame('ana', $billeteraGuardada->usuarioId);
        self::assertSame(Moneda::MXN, $billeteraGuardada->moneda);
        self::assertSame($id, $billeteraGuardada->id);
    }

    #[Test]
    public function publica_evento_billetera_creada(): void
    {
        // WHEN: se ejecuta el handler.
        $handler = new CrearBilleteraCommandHandler(
            $this->repositorioFake(),
            $this->eventBus,
        );
        $handler(CrearBilleteraCommand::crear('bob', 'USD'));

        // THEN: se publicó exactamente UN evento de tipo BilleteraCreada.
        self::assertCount(1, $this->eventBus->despachados);
        $evento = $this->eventBus->despachados[0];

        self::assertInstanceOf(\App\Message\Event\BilleteraCreadaEvent::class, $evento);
        self::assertSame('USD', $evento->moneda);
        self::assertSame('bob', $evento->usuarioId);
        self::assertSame('billetera.creada', $evento->eventType);
    }

    #[Test]
    public function rechaza_moneda_no_soportada(): void
    {
        // EXPECT: moneda BTC lanza excepción de dominio.
        $this->expectException(MonedaNoSoportada::class);

        $handler = new CrearBilleteraCommandHandler(
            $this->repositorioFake(),
            $this->eventBus,
        );
        $handler(CrearBilleteraCommand::crear('carol', 'BTC'));
    }

    /**
     * Repositorio fake en memoria (implementa el puerto de dominio).
     *
     * @return BilleteraRepository Puerto fake
     */
    private function repositorioFake(): BilleteraRepository
    {
        return new class($this->guardadas) implements BilleteraRepository {
            public function __construct(private array &$storage)
            {
            }

            public function save(Billetera $billetera): void
            {
                // Guardamos por referencia con el ID como clave.
                $this->storage[$billetera->id] = $billetera;
            }

            public function findById(string $id): ?Billetera
            {
                return $this->storage[$id] ?? null;
            }
        };
    }
}
```

## tests/Unit/Application/ObtenerBilleteraQueryHandlerTest.php

```php
<?php
// tests/Unit/Application/ObtenerBilleteraQueryHandlerTest.php — Test del query handler
//
# Los queries NUNCA mutan estado: este test verifica que el handler
# devuelve un snapshot plan (array) y con los contratos JSON exactos.

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\CommandHandler\ObtenerBilleteraQueryHandler;
use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Message\Query\ObtenerBilleteraQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Verifica el caso de uso "obtener billetera" (solo lectura).
 */
final class ObtenerBilleteraQueryHandlerTest extends TestCase
{
    #[Test]
    public function retorna_snapshot_con_balance_calculado(): void
    {
        // GIVEN: una billetera con un depósito de 2500 MXN.
        $billetera = new Billetera(
            id: 'wal-123',
            usuarioId: 'pepe',
            moneda: Moneda::MXN,
        );
        $billetera->depositar(Dinero::crear('2500.00', Moneda::MXN), 'Salario');

        $handler = new ObtenerBilleteraQueryHandler($this->repositorioFake($billetera));

        // WHEN: se consulta la billetera.
        $snapshot = $handler(new ObtenerBilleteraQuery('wal-123'));

        // THEN: el snapshot expone los campos del contrato API.
        self::assertSame([
            'id' => 'wal-123',
            'usuario_id' => 'pepe',
            'moneda' => 'MXN',
            'balance' => '2500.00',
            'total_movimientos' => 1,
        ], $snapshot);
    }

    #[Test]
    public function query_inexistente_lanza_excepcion(): void
    {
        $handler = new ObtenerBilleteraQueryHandler($this->repositorioFake(null));

        $this->expectException(BilleteraNoEncontrada::class);

        $handler(new ObtenerBilleteraQuery('no-existe'));
    }

    private function repositorioFake(?Billetera $billetera): BilleteraRepository
    {
        return new class($billetera) implements BilleteraRepository {
            public function __construct(private ?Billetera $billetera)
            {
            }

            public function save(Billetera $billetera): void
            {
                $this->billetera = $billetera;
            }

            public function findById(string $id): ?Billetera
            {
                return $this->billetera;
            }
        };
    }
}
```

## tests/Unit/Application/RealizarDepositoCommandHandlerTest.php

```php
<?php
// tests/Unit/Application/RealizarDepositoCommandHandlerTest.php — Test del use case
//
# Los tests de idempotencia son LOS MÁS importantes de un sistema financiero:
#   - Rendir el depósito DOS veces con la misma clave debe dar el MISMO resultado
#   - Rendir con clave distinta sí debe duplicar (es otra operación)

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\CommandHandler\RealizarDepositoCommandHandler;
use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Billetera;
use App\Domain\Exception\FondosInsuficientes;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Domain\Repository\IdempotenciaRepository;
use App\Message\Command\RealizarDepositoCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Verifica el caso de uso "depositar" incluyendo idempotencia.
 */
final class RealizarDepositoCommandHandlerTest extends TestCase
{
    #[Test]
    public function deposita_y_retorna_nuevo_balance(): void
    {
        $billetera = $this->billeteraConSaldo('1000.00');
        $handler = $this->handler(almacen: [$billetera]);

        // WHEN: depósito de 500 MXN.
        $balance = $handler(RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '500.00',
            moneda: 'MXN',
            descripcion: 'Nómina',
            idempotencyKey: 'clave-1',
        ));

        // THEN: balance nuevo = 1500.00.
        self::assertSame('1500.00', $balance);
    }

    #[Test]
    public function misma_clave_no_duplica_deposito(): void
    {
        $billetera = $this->billeteraConSaldo('1000.00');
        $idempotencia = $this->idempotenciaFake();
        $handler = $this->handler(
            almacen: [$billetera],
            idempotencia: $idempotencia,
        );

        $command = RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '500.00',
            moneda: 'MXN',
            descripcion: 'Nómina',
            idempotencyKey: 'misma-clave',
        );

        // WHEN: se procesa DOS veces con la misma clave.
        $primerBalance = $handler($command);
        $segundoBalance = $handler($command);

        // THEN: ambos retornan el mismo resultado (1500.00), no 2000.00.
        self::assertSame('1500.00', $primerBalance);
        self::assertSame('1500.00', $segundoBalance);

        // Y solo hay UN movimiento registrado (no dos).
        self::assertSame(1, $billetera->totalMovimientos());
    }

    #[Test]
    public function clave_distinta_si_duplica(): void
    {
        $billetera = $this->billeteraConSaldo('0.00');
        $idempotencia = $this->idempotenciaFake();
        $handler = $this->handler(
            almacen: [$billetera],
            idempotencia: $idempotencia,
        );

        // WHEN: dos depósitos legítimos con claves diferentes.
        $handler(RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '100.00',
            moneda: 'MXN',
            descripcion: 'A',
            idempotencyKey: 'clave-A',
        ));
        $handler(RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '100.00',
            moneda: 'MXN',
            descripcion: 'B',
            idempotencyKey: 'clave-B',
        ));

        // THEN: hay dos movimientos y balance 200.00.
        self::assertSame(2, $billetera->totalMovimientos());
        self::assertSame('200.00', $billetera->balance()->formateadoDecimal());
    }

    #[Test]
    public function billetera_inexistente_lanza_excepcion(): void
    {
        $handler = $this->handler(almacen: []);

        $this->expectException(BilleteraNoEncontrada::class);

        $handler(RealizarDepositoCommand::crear(
            billeteraId: 'no-existe',
            monto: '10.00',
            moneda: 'MXN',
            descripcion: 'x',
            idempotencyKey: 'k',
        ));
    }

    #[Test]
    public function publica_evento_transaccion_completada(): void
    {
        $billetera = $this->billeteraConSaldo('100.00');
        $eventBus = $this->eventBusFake();
        $handler = $this->handler(
            almacen: [$billetera],
            eventBus: $eventBus,
        );

        $handler(RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '50.00',
            moneda: 'MXN',
            descripcion: 'Test',
            idempotencyKey: 'k-evento',
        ));

        self::assertCount(1, $eventBus->despachados);
        $evento = $eventBus->despachados[0];
        self::assertInstanceOf(\App\Message\Event\TransaccionCompletadaEvent::class, $evento);
        self::assertSame('150.00', $evento->balanceNuevo);
        self::assertSame('deposito', $evento->tipo);
    }

    #[Test]
    public function evento_no_se_publica_en_reintento_idempotente(): void
    {
        $billetera = $this->billeteraConSaldo('100.00');
        $idempotencia = $this->idempotenciaFake();
        $eventBus = $this->eventBusFake();
        $handler = $this->handler(
            almacen: [$billetera],
            idempotencia: $idempotencia,
            eventBus: $eventBus,
        );

        $command = RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '50.00',
            moneda: 'MXN',
            descripcion: 'x',
            idempotencyKey: 'k-dup',
        );

        $handler($command);
        $handler($command);

        // Solo la PRIMERA ejecución debe publicar el evento.
        self::assertCount(1, $eventBus->despachados);
    }

    /**
     * Construye el handler con dependencias configurables.
     */
    private function handler(
        array $almacen,
        ?IdempotenciaRepository $idempotencia = null,
        ?MessageBusInterface $eventBus = null,
    ): RealizarDepositoCommandHandler {
        $repositorio = $this->repositorioFake($almacen);

        return new RealizarDepositoCommandHandler(
            billeteras: $repositorio,
            idempotencia: $idempotencia ?? $this->idempotenciaFake(),
            eventBus: $eventBus ?? $this->eventBusFake(),
        );
    }

    /**
     * Billetera de prueba con saldo inicial.
     */
    private function billeteraConSaldo(string $saldo): Billetera
    {
        $billetera = new Billetera(
            id: (string) Uuid::v4(),
            usuarioId: 'ana',
            moneda: Moneda::MXN,
        );

        // Depositar el saldo inicial directamente (sin handler).
        $billetera->depositar(
            \App\Domain\Dinero::crear($saldo, Moneda::MXN),
            'Saldo inicial',
        );

        return $billetera;
    }

    /**
     * @param array<string, Billetera> $almacen
     */
    private function repositorioFake(array &$almacen): BilleteraRepository
    {
        return new class($almacen) implements BilleteraRepository {
            public function __construct(private array &$storage)
            {
            }

            public function save(Billetera $billetera): void
            {
                $this->storage[$billetera->id] = $billetera;
            }

            public function findById(string $id): ?Billetera
            {
                return $this->storage[$id] ?? null;
            }
        };
    }

    private function idempotenciaFake(): IdempotenciaRepository
    {
        return new class implements IdempotenciaRepository {
            /** @var array<string, true> */
            private array $claves = [];

            public function existe(string $clave): bool
            {
                return isset($this->claves[$clave]);
            }

            public function registrar(string $clave): void
            {
                $this->claves[$clave] = true;
            }
        };
    }

    private function eventBusFake(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            public array $despachados = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->despachados[] = $message;

                return new Envelope($message);
            }
        };
    }
}
```

