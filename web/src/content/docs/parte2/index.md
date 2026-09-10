---
title: "PARTE 2: Symfony Framework, Docker y Arquitectura Hexagonal"
description: "Objetivo"
sidebar: {"order":2}
---


> **Objetivo**: Dockerizar el wallet-service, conectarlo a PostgreSQL, y demostrar
> la separación entre Dominio, Aplicación e Infraestructura.
>
> **Duración estimada**: 18–24 horas
>
> **Resultado**: wallet-service dockerizado con API REST funcional, persistencia en PostgreSQL,
> tests de integración y funcionales pasando.
>
> **Prerrequisito**: Parte 1 completada (wallet-service con make ci pasando)

---

## Índice

- [2.1 Dockerización Profesional](#21-dockerización-profesional)
- [2.2 Configuración de Symfony y Dependency Injection](#22-configuración-de-symfony-y-dependency-injection)
- [2.3 Doctrine ORM y Persistencia](#23-doctrine-orm-y-persistencia)
- [2.4 API REST: Controllers con Lógica Real](#24-api-rest-controllers-con-lógica-real)
- [2.5 Arquitectura Hexagonal en la Práctica](#25-arquitectura-hexagonal-en-la-práctica)
- [2.6 Tests de Integración y Funcionales](#26-tests-de-integración-y-funcionales)
- [Ejercicios de la Parte 2](#ejercicios-de-la-parte-2)

---

## 2.1 Dockerización Profesional

### ¿Por qué Docker?

Docker resuelve el problema de "funciona en mi máquina":

```
Sin Docker:                      Con Docker:
┌──────────────┐                ┌──────────────────────────────┐
│ Mi máquina   │                │ Cualquier máquina            │
│ PHP 8.1      │                │ Docker Desktop                │
│ PostgreSQL 15│                │ ┌──────────────────────────┐  │
│ RabbitMQ 3.12│                │ │ php:8.3-fpm-alpine       │  │
│ Nada coincide│                │ │ postgres:16-alpine       │  │
│ con producción│               │ │ rabbitmq:3.13-alpine     │  │
└──────────────┘                │ │ nginx:1.25-alpine        │  │
                                │ │ Todos = producción        │  │
                                │ └──────────────────────────┘  │
                                └──────────────────────────────┘
```

### Arquitectura de Contenedores

```
┌──────────────────────────────────────────────────────────────┐
│                    docker-compose.yml                         │
│                                                              │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐   │
│  │  nginx   │  │ php-fpm  │  │ postgres │  │ rabbitmq │   │
│  │  :80     │  │  :9000   │  │  :5432   │  │  :5672   │   │
│  │          │──│          │──│          │  │  :15672  │   │
│  │ (HTTP)   │  │ (FastCGI)│  │ (SQL)    │  │ (AMQP)   │   │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘   │
│                                                              │
│  Red: prestaflow-net (bridge)                                │
│  Todos los servicios se resuelven por NOMBRE                 │
└──────────────────────────────────────────────────────────────┘
```

### Multi-Stage Build: ¿Por qué?

```
ETAPA 1: Build (~800MB)              ETAPA 2: Runtime (~200MB)
┌────────────────────────────┐      ┌────────────────────────┐
│ php:8.3-fpm-alpine (base)  │      │ Extensiones compiladas │
│ + gcc, g++, make           │ ──▶  │ + Composer             │
│ + php-dev headers          │      │ + xdebug              │
│ + extensiones compiladas   │      │ + config PHP-FPM       │
│ + xdebug                   │      │ (sin gcc ni headers)   │
│ + composer                 │      └────────────────────────┘
└────────────────────────────┘
```

### Paso a Paso: Crear la Estructura Docker

```bash
# Desde la raíz del monorepo
cd ~/fsc-php-curso

# Crear estructura de Docker
mkdir -p parte2/soluciones/docker/{php-fpm,nginx}
mkdir -p parte2/soluciones/config/{packages,routes}
mkdir -p parte2/soluciones/migrations
mkdir -p parte2/soluciones/src/{Infrastructure,Application}
mkdir -p parte2/soluciones/tests/{Functional,Integration}
```

### Dockerfile de PHP-FPM (Explicado)

```dockerfile
# docker/php-fpm/Dockerfile
#
# Multi-stage build: 2 etapas para reducir tamaño de imagen.

# ETAPA 1: Build — compilar extensiones
FROM php:8.3-fpm-alpine AS build

# Instalar dependencias de compilación.
# Estas se eliminan en la etapa 2.
RUN apk add --no-cache \
    git unzip libzip-dev icu-dev libpq-dev oniguruma-dev linux-headers \
    && docker-php-ext-install opcache intl mbstring zip bcmath pdo pdo_pgsql pgsql \
    && pecl install apcu xdebug \
    && docker-php-ext-enable apcu xdebug

# ETAPA 2: Runtime — solo lo necesario
FROM php:8.3-fpm-alpine

# Copiar extensiones compiladas de la etapa anterior.
COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/

# Copiar configuración PHP.
COPY --from=build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Crear usuario no-root.
RUN addgroup -g 1000 -S appgroup && adduser -u 1000 -S appuser -G appgroup

# Directorio de trabajo.
WORKDIR /app

# Copiar Composer (para instalar dependencias en runtime si es necesario).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Puerto de FastCGI (no HTTP).
EXPOSE 9000

# Iniciar PHP-FPM en foreground.
CMD ["php-fpm", "--nodaemonize"]
```

### docker-compose.yml (Explicado)

```yaml
# docker-compose.yml — 5 servicios
version: '3.8'

services:
  nginx:
    build:
      context: .
      dockerfile: docker/nginx/Dockerfile
    ports:
      - "8080:80"          # Host:8080 → Contenedor:80
    volumes:
      - "../../:/app"      # Código fuente montado
    depends_on:
      php-fpm:
        condition: service_healthy

  php-fpm:
    build:
      context: .
      dockerfile: docker/php-fpm/Dockerfile
    environment:
      APP_ENV: dev
      APP_DEBUG: "1"
      DATABASE_URL: "postgresql://prestaflow:prestaflow_secret@postgres:5432/prestaflow_wallet?serverVersion=16"
    volumes:
      - "../../:/app"
    healthcheck:
      test: ["CMD-SHELL", "php-fpm -t 2>/dev/null || exit 1"]
      interval: 10s
      retries: 3

  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: prestaflow_wallet
      POSTGRES_USER: prestaflow
      POSTGRES_PASSWORD: prestaflow_secret
    ports:
      - "5433:5432"        # Host:5433 → Contenedor:5432
    volumes:
      - pgdata:/var/lib/postgresql/data

  rabbitmq:
    image: rabbitmq:3.13-management-alpine
    ports:
      - "5672:5672"
      - "15672:15672"      # Management UI

  datadog-agent:
    image: datadog/agent:7
    environment:
      DD_API_KEY: "${DD_API_KEY:-placeholder}"
      DD_APM_ENABLED: "true"
    ports:
      - "8126:8126"

volumes:
  pgdata:

networks:
  prestaflow-net:
    driver: bridge
```

### Iniciar los Servicios

```bash
cd parte2/soluciones

# Construir y iniciar todos los contenedores
docker compose up -d --build
```

**Salida esperada:**

```
[+] Building 45.2s (18/18) FINISHED
 => [php-fpm] exporting to image
 => => naming to docker.io/library/parta2-soluciones-php-fpm
[+] Running 5/5
 ✔ Network parte2-soluciones_prestaflow-net Created    0.1s
 ✔ Container parte2-soluciones-postgres-1     Started   0.3s
 ✔ Container parte2-soluciones-rabbitmq-1     Started   0.5s
 ✔ Container parte2-soluciones-php-fpm-1      Started   0.7s
 ✔ Container parte2-soluciones-nginx-1        Started   0.9s
```

```bash
# Verificar que los servicios están corriendo
docker compose ps
```

**Salida esperada:**

```
NAME                        STATUS          PORTS
parta2-soluciones-nginx-1   Up (healthy)    0.0.0.0:8080->80/tcp
parta2-soluciones-php-fpm-1 Up (healthy)    9000/tcp
parta2-soluciones-postgres-1 Up (healthy)   0.0.0.0:5433->5432/tcp
parta2-soluciones-rabbitmq-1 Up (healthy)   0.0.0.0:5672->5672/tcp, 0.0.0.0:15672->15672/tcp
```

### Verificar la Conexión

```bash
# Probar que Nginx sirve el health check
curl -s http://localhost:8080/salud | python3 -m json.tool
```

**Salida esperada:**

```json
{
    "servicio": "wallet-service",
    "estado": "ok",
    "version": "1.0.0",
    "timestamp": "2026-09-10T15:30:00+00:00"
}
```

```bash
# Verificar PostgreSQL
docker compose exec postgres psql -U prestaflow -d prestaflow_wallet -c "\dt"
```

**Salida esperada:**

```
Did not find any relations.
```

> **Bien**: PostgreSQL está corriendo pero no tiene tablas aún.
> Las crearemos con Doctrine Migrations en la Lección 2.3.

### Checkpoint 2.1 ✓

```bash
# Todos los servicios con status "Up (healthy)"
docker compose ps | grep -c "Up (healthy)"
# Salida: 4 (o 5 si Datadog está habilitado)
```

---

## 2.2 Configuración de Symfony y Dependency Injection

### ¿Qué es el Container de Servicios?

El container de servicios es el "cerebro" de Symfony:
almacena y crea objetos (servicios) que la aplicación necesita.

```yaml
# config/services.yaml — Registro de servicios
#
# Cada archivo que Symfony necesita "sabe" cómo crear,
# se registra aquí como un "servicio".

parameters:
    # Parámetros: valores constantes que se inyectan en servicios.
    # Estos son como "constantes de configuración".
    app.version: '1.0.0'
    app.timezone: '%env(APP_TIMEZONE)%'

services:
    # Configuración por defecto para TODOS los servicios en src/.
    # _defaults: aplica a todos los servicios que no tengan configuración específica.
    defaults:
        # autowire: Symfony crea automáticamente las dependencias.
        # Si un constructor pide BilleteraRepository, Symfony lo busca y lo inyecta.
        autowire: true

        # autoconfigure: Symfony detecta automáticamente las interfaces.
        # Si un service implementa EventSubscriberInterface, Symfony lo registra
        # como subscriber automáticamente.
        autoconfigure: true

    # Namespace raíz de la aplicación.
    # Symfony escanea src/ buscando clases para registrar.
    App\:
        resource: '../src/'
        # exclude: clases que NO deben registrarse como servicios.
        # Controller: se registran por su configuración específica.
        # DTO: no son servicios (son objetos de datos).
        exclude:
            - '../src/DependencyInjection/'
            - '../src/Entity/'
            - '../src/Kernel.php'

    # Registro explícito de controllers.
    # Los controllers necesitan configuración especial porque:
    #   1. Se registran como servicios públicos (accesibles desde routing)
    #   2. Se les inyectan dependencias del request (Request, ParameterBag)
    App\Controller\:
        resource: '../src/Controller/'
        tags: ['controller.service_arguments']

    # Registro explícito del Repositorio.
    # Doctrine crea automáticamente el repositorio si la Entity tiene
    # el atributo #[Entity(repositoryClass: ...)].
    # Pero podemos registrarlo explícitamente para tener control total.
    App\Infrastructure\Repository\BilleteraRepository:
        # factory: cómo crear el repositorio.
        # Doctrine extension provee este factory que:
        #   1. Obtiene el EntityManager
        #   2. Crea el repositorio con la Entity correcta
        factory: ['doctrine.orm.entity_manager', 'getRepository']
        arguments:
            - App\Domain\Billetera::class

    # Application Services: explícitamente registrados.
    # Autowire funciona, pero explícito es más legible en proyectos grandes.
    App\Application\Service\CrearBilleteraService:
        arguments:
            $repository: '@App\Infrastructure\Repository\BilleteraRepository'

    App\Application\Service\RealizarDepositoService:
        arguments:
            $repository: '@App\Infrastructure\Repository\BilleteraRepository'
```

### Dependency Injection en Práctica

```php
<?php
// Ejemplo de inyección automática (autowire).

// Symfony lee el constructor y crea automáticamente las dependencias:
class BilleteraController extends AbstractController
{
    // Symfony ve: "necesito CrearBilleteraService"
    // Symfony ve: "CrearBilleteraService necesita BilleteraRepository"
    // Symfony crea ambos y los inyecta.
    public function __construct(
        private readonly CrearBilleteraService $crearService,
    ) {}
}

// ¿Qué hace Symfony internamente?
// 1. Lee el container: $container->get('App\Application\Service\CrearBilleteraService')
// 2. Lee el constructor de CrearBilleteraService: __construct(BilleteraRepository $repo)
// 3. Busca BilleteraRepository en el container: $container->get('App\Infrastructure\Repository\BilleteraRepository')
// 4. Crea CrearBilleteraService con el repository inyectado
// 5. Retorna la instancia lista para usar
```

### Verificar Servicios

```bash
# Listar todos los servicios registrados
docker compose exec php-fpm php bin/console debug:container

# Verificar un servicio específico
docker compose exec php-fpm php bin/console debug:container App\\Application\\Service\\CrearBilleteraService
```

### Checkpoint 2.2 ✓

```bash
# Symfony debug:container no debe mostrar errores
docker compose exec php-fpm php bin/console debug:container 2>&1 | head -20
# Debe mostrar la lista de servicios sin errores
```

---

## 2.3 Doctrine ORM y Persistencia

### ¿Qué es Doctrine ORM?

Doctrine Object-Relational Mapper convierte entre:
- **Objetos PHP** (Billetera, Dinero, Movimiento) → **Filas SQL** (INSERT, UPDATE, SELECT)

```
PHP Object                     SQL Row
┌──────────────────┐          ┌──────────────────────────────────┐
│ Billetera         │          │ billetera                         │
│  id: "uuid-001"  │   ───▶   │  id: 'uuid-001'                  │
│  usuarioId: "u1" │          │  usuario_id: 'u1'                │
│  moneda: MXN     │          │  moneda: 'MXN'                   │
│  saldo: 150000   │          │  saldo_centavos: 150000          │
└──────────────────┘          └──────────────────────────────────┘
```

### Entity Mapping

```php
// Doctrine mapea entidades con atributos PHP 8:

#[ORM\Entity(repositoryClass: BilleteraRepository::class)]
#[ORM\Table(name: 'billetera')]
class Billetera
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 3)]
    private string $moneda;

    #[ORM\Column(type: 'integer')]
    private int $saldoCentavos;
}
```

### Doctrine Migrations

```bash
# Crear una migración desde las entities
docker compose exec php-fpm php bin/console doctrine:migrations:diff

# Ejecutar la migración
docker compose exec php-fpm php bin/console doctrine:migrations:migrate

# Ver el historial de migraciones
docker compose exec php-fpm php bin/console doctrine:migrations:migrate --no-interaction
```

**Salida esperada:**

```
WARNING! You are about to execute a migration in database "prestaflow_wallet"
that could result in data loss and is not reversible.

Do you confirm the execution of all pending migrations (yes/no) [yes]:
 > yes

Migrating: 20260910000000
Processed: 1 migrations (took 12.4s, used 20MB memory)
```

### DQL vs SQL

```php
<?php
// Doctrine Query Language (DQL): SQL orientado a objetos.

// SQL tradicional:
// SELECT * FROM billetera WHERE usuario_id = 'user_001' AND moneda = 'MXN'

// DQL de Doctrine:
$qb = $repository->createQueryBuilder('b');
$qb->where('b.usuarioId = :usuarioId')
   ->setParameter('usuarioId', 'user_001')
   ->andWhere('b.moneda = :moneda')
   ->setParameter('moneda', 'MXN');

// Doctrine genera el SQL automáticamente y retorna entidades PHP.
```

### Checkpoint 2.3 ✓

```bash
# Verificar tablas creadas
docker compose exec postgres psql -U prestaflow -d prestaflow_wallet -c "\dt"
```

**Salida esperada:**

```
           List of relations
 Schema |     Name      | Type  |  Owner
--------+---------------+-------+----------
 public | billetera     | table | prestaflow
 public | movimiento    | table | prestaflow
 public | migration_versions | table | prestaflow
```

---

## 2.4 API REST: Controllers con Lógica Real

### Arquitectura de Capas

```mermaid
graph LR
    subgraph Exterior["Exterior"]
        API["REST API"]
        CLI["CLI"]
        MQ["RabbitMQ"]
    end
    subgraph Puertos["Puertos (Interfaces)"]
        CP["Command Port"]
        QP["Query Port"]
    end
    subgraph Core["Core del Dominio"]
        DOM["Domain\nEntities + Rules"]
    end
    subgraph Adaptadores["Adaptadores"]
        AD1["Doctrine Repo"]
        AD2["HTTP Controller"]
        AD3["Message Handler"]
    end
    Exterior --> Puertos
    Puertos --> Core
    Adaptadores --> Puertos
    style DOM fill:#dbeafe,stroke:#1a56db,stroke-width:2px
```

### REST API Design

| Método | Ruta | Descripción | Status Codes |
|---|---|---|---|
| `POST` | `/api/v1/billeteras` | Crear billetera | 201, 400, 409 |
| `GET` | `/api/v1/billeteras/{id}` | Consultar billetera | 200, 404 |
| `POST` | `/api/v1/billeteras/{id}/deposito` | Realizar depósito | 200, 400, 404, 422 |
| `GET` | `/api/v1/billeteras/{id}/movimientos` | Historial | 200, 404 |

### Respuestas JSON Estructuradas

**Éxito (201 Created):**

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "usuario_id": "user_001",
  "moneda": "MXN",
  "saldo": "0.00",
  "creado_en": "2026-09-10T15:30:00+00:00"
}
```

**Error (400 Bad Request):**

```json
{
  "error": {
    "codigo": "CAMPOS_REQUERIDOS",
    "mensaje": "Los campos \"usuario_id\" y \"moneda\" son obligatorios"
  }
}
```

**Error (422 Unprocessable Entity):**

```json
{
  "error": {
    "codigo": "FONDOS_INSUFICIENTES",
    "mensaje": "Saldo insuficiente para completar la transacción",
    "detalle": {
      "saldo_actual": "500.00",
      "monto_solicitado": "1500.00",
      "déficit": "1000.00"
    }
  }
}
```

### Checkpoint 2.4 ✓

```bash
# Crear una billetera
curl -s -X POST http://localhost:8080/api/v1/billeteras \
  -H "Content-Type: application/json" \
  -d '{"usuario_id":"user_001","moneda":"MXN"}' | python3 -m json.tool
```

**Salida esperada:**

```json
{
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "usuario_id": "user_001",
    "moneda": "MXN",
    "saldo": "0.00",
    "creado_en": "2026-09-10T15:30:00+00:00"
}
```

---

## 2.5 Arquitectura Hexagonal en la Práctica

### El Test que Prueba la Pureza del Dominio

```php
<?php
// tests/Architecture/DominioPuroTest.php

#[Test]
public function test_dominio_no_importa_symfony(): void
{
    // Este test escanea src/Domain/ buscando imports de Symfony.
    // Si encuentra alguno, el test FALLA.

    $forbiddenNamespaces = [
        'Symfony\\',
        'Doctrine\\',
        'Psr\\',
    ];

    foreach ($phpFiles as $file) {
        foreach ($forbiddenNamespaces as $namespace) {
            if (strstr($content, 'use '.$namespace) !== false) {
                $violations[] = "{$relativePath} importa {$namespace}";
            }
        }
    }

    $this->assertEmpty($violations, "Dominio puro violado:\n". implode("\n", $violations));
}
```

### ¿Por qué importa?

Si el dominio importa Symfony:
- **No puedes copiar src/Domain/ a otro proyecto**
- **No puedes testear sin el kernel de Symfony** (más lento)
- **Los cambios de Symfony rompen tu dominio**

Si el dominio es puro:
- **Puedes copiar src/Domain/ a cualquier proyecto PHP 8.3+**
- **Los tests unitarios son ultrarrápidos** (milisegundos)
- **Symfony es un detalle de implementación**, no una dependencia

### La Prueba: Copiar el Dominio a Otro Proyecto

```bash
# Copiar solo el dominio a un proyecto sin Symfony
mkdir -p /tmp/puro && cp -r src/Domain/ /tmp/puro/
ls /tmp/puro/
```

**Salida:**

```
Billetera.php    Dinero.php    Moneda.php
Movimiento.php   TipoMovimiento.php   Exception/
```

```bash
# Verificar que NO hay imports de framework
grep -r "use Symfony" /tmp/puro/
# (sin salida = el dominio es puro ✓)
```

---

## 2.6 Tests de Integración y Funcionales

### Tipos de Tests en el Curso

```
┌─────────────────────────────────────────────────────────────────┐
│                    Pirámide de Tests                             │
│                                                                 │
│                          /\                                     │
│                         /  \      Tests E2E                     │
│                        / 2  \     (Playwright) [Parte 4]        │
│                       /______\                                  │
│                      /        \   Tests Funcionales              │
│                     /   10     \  (WebTestCase) [Parte 2]       │
│                    /____________\                                │
│                   /              \ Tests de Integración          │
│                  /      25        \ (BD real) [Parte 2]         │
│                 /__________________\                             │
│                /                    \ Tests Unitarios             │
│               /         60           \ (dominio puro) [Parte 1] │
│              /________________________\                          │
│                                                                 │
│  Más tests arriba = más rápidos y baratos                        │
│  Menos tests abajo = más lentos pero más realistas               │
└─────────────────────────────────────────────────────────────────┘
```

### Test de Integración vs Funcional

| Aspecto | Integración | Funcional |
|---|---|---|
| Qué prueba | Repository + DB | Controller + HTTP + DB |
| Cómo llama | Método PHP directo | Simula petición HTTP |
| Velocidad | ~50ms | ~200ms |
| Cuándo usar | Verificar queries | Verificar API completa |

### Ejecutar los Tests

```bash
# Tests unitarios (sin DB, ~100ms total)
docker compose exec php-fpm php bin/phpunit --testsuite unit

# Tests de integración (con DB, ~2s)
docker compose exec php-fpm php bin/phpunit --testsuite integration

# Tests funcionales (HTTP + DB, ~5s)
docker compose exec php-fpm php bin/phpunit --testsuite functional

# Todos los tests
docker compose exec php-fpm php bin/phpunit
```

### Checkpoint 2.6 ✓

```bash
docker compose exec php-fpm php bin/phpunit --testdox
```

**Salida esperada:**

```
App\Tests\Unit\Domain\DineroTest
 ✓ Crear dinero desde monto decimal
 ✓ Monto negativo lanza excepción
 ✓ Conversion centavos (un centavo)
 ✓ Conversion centavos (un peso)
 ✓ Conversion centavos (quinientos)
 ✓ Conversion centavos (sin decimales)

App\Tests\Unit\Domain\BilleteraTest
 ✓ Billetera nueva tiene balance cero
 ✓ Depositar incrementa balance
 ✓ Multiples depositos acumulan balance
 ✓ Depositar monto cero lanza excepción
 ✓ Depositar moneda diferente lanza excepción
 ✓ Retirar decrementa balance
 ✓ Retirar saldo insuficiente lanza FondosInsuficientes
 ✓ Retirar monto cero lanza excepción
 ✓ Movimientos se mantienen en orden
 ✓ Movimientos retorna copia

App\Tests\Integration\Repository\BilleteraRepositoryTest
 ✓ Guardar y recuperar billetera
 ✓ Guardar billetera con depósito
 ✓ Contar billeteras
 ✓ Buscar por usuario
 ✓ Retorna null cuando no existe

App\Tests\Functional\BilleteraControllerTest
 ✓ Salud returns ok
 ✓ Crear billetera
 ✓ Crear billetera campos faltantes
 ✓ Obtener billetera no existe

App\Tests\Architecture\DominioPuroTest
 ✓ Dominio no importa Symfony
 ✓ Value objects son readonly
 ✓ Todos archivos PHP usan strict_types

Time: 00:02.150, Memory: 15.2 MB

OK (30 tests, 48 assertions)
```

---

## Ejercicios de la Parte 2

---

### Ejercicio 2.1: Crear Docker Compose

**Objetivo**: Configurar la infraestructura Docker para el wallet-service.

**Instrucciones**:

1. Crear `docker-compose.yml` con servicios: nginx, php-fpm, postgres, rabbitmq
2. Crear `docker/php-fpm/Dockerfile` con multi-stage build
3. Crear `docker/nginx/default.conf` con routing FastCGI
4. Ejecutar `docker compose up -d --build`
5. Verificar que todos los servicios están "Up (healthy)"

**Verificación**:

```bash
docker compose ps | grep -c "healthy"
# Salida: 3 (mínimo: nginx, php-fpm, postgres)
```

**Puntos**: 8

---

### Ejercicio 2.2: Doctrine ORM y Migrations

**Objetivo**: Conectar el wallet-service a PostgreSQL con Doctrine.

**Instrucciones**:

1. Instalar Doctrine: `composer require symfony/orm-pack`
2. Configurar `config/packages/doctrine.yaml` con DATABASE_URL
3. Crear la migración inicial: `php bin/console doctrine:migrations:diff`
4. Ejecutar la migración: `php bin/console doctrine:migrations:migrate`
5. Verificar tablas creadas en PostgreSQL

**Verificación**:

```bash
docker compose exec postgres psql -U prestaflow -d prestaflow_wallet -c "\dt"
# Debe mostrar: billetera, movimiento, migration_versions
```

**Puntos**: 7

---

### Ejercicio 2.3: Application Services

**Objetivo**: Crear los servicios de aplicación para crear billeteras y depositar.

**Instrucciones**:

1. Crear `CrearBilleteraService` con validación de moneda
2. Crear `RealizarDepositoService` con validación de saldo
3. Crear tests unitarios para ambos servicios
4. Verificar que los tests pasan

**Verificación**:

```bash
./vendor/bin/phpunit tests/Unit/Application/ --testdox
```

**Puntos**: 6

---

### Ejercicio 2.4: API REST de Billeteras

**Objetivo**: Exponer la API de billeteras a través de HTTP.

**Instrucciones**:

1. Crear `BilleteraController` con endpoints: crear, obtener, depositar
2. Configurar rutas en `config/routes.yaml`
3. Implementar respuestas JSON consistentes
4. Probar con curl

**Verificación**:

```bash
# Crear billetera
curl -s -X POST http://localhost:8080/api/v1/billeteras \
  -H "Content-Type: application/json" \
  -d '{"usuario_id":"test","moneda":"MXN"}' | jq '.id'
# Debe retornar un UUID

# Depositar
curl -s -X POST http://localhost:8080/api/v1/billeteras/{id}/deposito \
  -H "Content-Type: application/json" \
  -d '{"monto":"1500.00","descripcion":"Test"}' | jq '.balance_nuevo'
# Debe retornar "1500.00"
```

**Puntos**: 8

---

### Ejercicio 2.5: Tests de Integración

**Objetivo**: Verificar que el Repository funcione con la BD real.

**Instrucciones**:

1. Crear tests de integración para BilleteraRepository
2. Test: guardar y recuperar billetera
3. Test: guardar con depósito y verificar saldo
4. Test: contar y buscar por usuario

**Verificación**:

```bash
./vendor/bin/phpunit tests/Integration/ --testdox
```

**Puntos**: 6

---

### Ejercicio 2.6: Tests Funcionales

**Objetivo**: Verificar la API completa vía HTTP simulado.

**Instrucciones**:

1. Crear tests funcionales con WebTestCase
2. Test: GET /salud retorna 200
3. Test: POST /api/v1/billeteras crea billetera
4. Test: POST sin campos retorna 400
5. Test: GET billetera inexistente retorna 404

**Verificación**:

```bash
./vendor/bin/phpunit tests/Functional/ --testdox
```

**Puntos**: 8

---

### Ejercicio 2.7: Arquitectura Hexagonal

**Objetivo**: Verificar que el dominio se mantiene puro.

**Instrucciones**:

1. Ejecutar el test de arquitectura de la Parte 1
2. Verificar que src/Domain/ no importa Symfony ni Doctrine
3. Verificar que los Value Objects son readonly

**Verificación**:

```bash
./vendor/bin/phpunit tests/Architecture/ --testdox
```

**Puntos**: 4

---

### Ejercicio 2.8: Pipeline CI con Docker

**Objetivo**: Ejecutar la pipeline completa dentro de Docker.

**Instrucciones**:

1. Ejecutar tests unitarios, integración, y funcionales dentro del contenedor
2. Ejecutar PHPStan nivel 6
3. Ejecutar CS-Fixer dry-run
4. Verificar que todo pasa

**Verificación**:

```bash
docker compose exec php-fpm make ci
# Debe mostrar: ✅ CI PASSED
```

**Puntos**: 5

---

## Siguiente Paso

Una vez completados los ejercicios de la Parte 2, avanza a la **Parte 3: CQRS y Event-Driven Architecture con RabbitMQ** donde:
- Separaremos Commands y Queries (CQRS)
- Configuraremos Symfony Messenger con RabbitMQ
- Crearemos el primer microservicio asíncrono (loan-service)
- Implementaremos eventos de dominio entre servicios
