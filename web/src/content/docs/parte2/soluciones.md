---
title: "Soluciones — Parte 2 — Symfony y Arquitectura Hexagonal"
description: "Código fuente de las soluciones de la Parte 2"
sidebar: {"label":"Soluciones","order":100}
---


> 📁 **14 archivos** de código de referencia.

## config/packages/doctrine.yaml

```yaml
# config/packages/doctrine.yaml — Configuración de Doctrine ORM
#
# Doctrine ORM es el "Object-Relational Mapper" de Symfony.
# Mapea entidades PHP → tablas de PostgreSQL.
#
# ¿Por qué ORM y no SQL directo?
#   1. Portabilidad: cambiar de PostgreSQL a MySQL = cambiar config, no código
#   2. Seguridad: Doctrine previene SQL injection automáticamente
#   3. Productividad: object-oriented queries vs SQL string concatenation
#   4. Migrations: Doctrine genera y ejecuta cambios de schema automáticamente
#
# Flujo de Doctrine:
#   Entity PHP → Doctrine Metadata → SQL Query → PostgreSQL → Result Set → Entity PHP

doctrine:
    dbal:
        # URL de conexión a la base de datos.
        # Formato: postgresql://user:password@host:port/database?params
        #
        # DATABASE_URL es una variable de entorno (definida en docker-compose.yml).
        # En desarrollo local: usar SQLite (sin Docker).
        # En Docker: PostgreSQL via red interna.
        #
        #server_version: "16"  # Versión de PostgreSQL (Doctrine necesita saberlo
        #                      # para generar SQL compatible)

        # Configuración de profiling (solo en dev).
        # profiling_collect_backtrace: incluir stack trace en queries lentas.
        # Esto ayuda a encontrar QUÉ código ejecutó una query lenta.
        profiling_collect_backtrace: "%kernel.debug%"

    orm:
        # auto_generate_proxy_classes: Doctrine genera "proxies" para lazy loading.
        # En dev: true (regenerar cada request para ver cambios).
        # En prod: false (usar proxies cacheados).
        auto_generate_proxy_classes: true

        # enable_lazy_ghost_objects: PHP 8.2+ feature.
        # En lugar de proxies de Doctrine (que son clases generadas dinámicamente),
        # usa "ghost objects" que son más ligeros y no requieren generación.
        enable_lazy_ghost_objects: true

        # naming_strategy: convención para nombres de tablas y columnas.
        # UnderscoreNamingStrategy: Billetera → billetera, saldoActual → saldo_actual
        # Este es el estándar en bases de datos relacionales.
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware

        # auto_mapping: Doctrine detecta entidades automáticamente.
        # Escanea src/ para encontrar clases con #[Entity].
        auto_mapping: true

        # Mappings: define DÓNDE buscar entidades y CÓMO mapearlas.
        mappings:
            App:
                # is_bundle: false → las entidades NO vienen de un bundle.
                # Son de nuestra aplicación.
                is_bundle: false

                # dir: directorio donde buscar entidades.
                # %kernel.project_dir% es la raíz del proyecto (equivalente a __DIR__/../..).
                dir: "%kernel.project_dir%/src"

                # prefix: namespace de las entidades.
                # Todas las entidades empiezan con App\.
                prefix: "App\\"

                # alias: alias para referenciar este mapping en queries DQL.
                alias: App

    # Configuración de\dbal/types: tipos personalizados.
    # Doctrine no conoce tipos como "Money" o "UUID".
    # Definimos tipos custom para que Doctrine los maneje correctamente.
    dbal:
        types:
            # Tipo UUID: almacena identificadores únicos como strings.
            # Doctrine necesita saber cómo convertir PHP → SQL y viceversa.
            uuid: App\Domain\Type\UuidType

```

## config/packages/messenger.yaml

```yaml
# config/packages/messenger.yaml — Configuración de Symfony Messenger
#
# Messenger es el componente de Symfony para mensajería asíncrona.
# Implementa el patrón "Command/Event Bus":
#   - Commands: acciones que el sistema debe ejecutar (CrearTransaccion)
#   - Events: cosas que ya pasaron (TransaccionCreada)
#
# Transport: cómo se entregan los mensajes.
#   - sync: ejecutar inmediatamente (mismo proceso, para testing)
#   - amqp: enviar a RabbitMQ (asíncrono, para producción)
#   - doctrine: guardar en base de datos (para retry manual)

framework:
    messenger:
        # failures_transport: donde van los mensajes que fallan después de N reintentos.
        # La "dead letter" queue: mensajes que nadie pudo procesar.
        # En producción, un admin revisa esta cola manualmente.
        failure_transport: failed

        # Bus de transaccionalidad.
        # transactional: si el handler falla, la transacción DB se revierte.
        # Útil para commands que modifican la base de datos.
        buses:
            messenger.bus.default:
                # default_middleware: middleware por defecto de Symfony Messenger.
                # resolve_handler: resuelve qué handler procesa cada mensaje.
                # doctrine_transaction: envuelve el handler en una transacción DB.
                default_middleware:
                    allow_no_senders: true

        # transports: define cómo se entregan los mensajes.
        transports:
            # Transport "sync": ejecuta el handler inmediatamente.
            # Útil para desarrollo y testing (no necesita RabbitMQ).
            async: '%env(MESSENGER_TRANSPORT_DSN)%'

            # Transport "failed": cola de mensajes fallidos.
            # Doctrine: almacena en la tabla `messenger_messages`.
            failed: 'doctrine://%env(DATABASE_URL)%'

        # routing: define qué mensajes van a qué transport.
        # Por defecto, todos van al transport "async".
        # Si necesitas routing específico:
        # routing:
        #     'App\Message\CrearTransaccion': async
        #     'App\Event\TransaccionCreada': async

```

## docker/nginx/default.conf

```nginx
# docker/nginx/default.conf — Configuración de Nginx para PrestaFlow
#
# Nginx es un servidor web de alto rendimiento. En nuestro stack:
#   - Nginx recibe las peticiones HTTP del cliente
#   - Para archivos estáticos (CSS, JS, imágenes): los sirve directamente
#   - Para PHP: delega a PHP-FPM via FastCGI
#
# Nginx NO ejecuta PHP directamente. Se comunica con PHP-FPM a través
# del protocolo FastCGI (puerto 9000 por defecto).

# Bloque server: define un servidor virtual.
# Cada bloque server maneja un dominio/puerto.
server {
    # Puerto de escucha. En Docker, el 80 es el estándar HTTP.
    # HTTPS (443) se configura con un proxy reverso o cert-manager en K8s.
    listen 80;

    # Nombre del servidor (host header).
    # Si tienes múltiples dominios en el mismo Nginx, cada uno tiene un server_name.
    server_name localhost;

    # Directorio raíz de los archivos web.
    # Nginx busca archivos estáticos aquí.
    # /app/public es donde Symfony ubica index.php y assets.
    root /app/public;

    # Charset de los archivos de texto.
    # UTF-8 es el estándar para soportar caracteres especiales (ñ, tildes, etc.)
    charset utf-8;

    # Configuración de logging.
    # access_log: cada petición HTTP se registra en este archivo.
    # En Docker, usamos /dev/stdout para que Docker Captive los logs.
    # "main" es el nombre del formato de log definido más abajo.
    access_log /var/log/nginx/access.log main;
    error_log /var/log/nginx/error.log;

    # max_client_body_size: tamaño máximo del body de una petición.
    # Si un cliente envía más de 20MB, Nginx rechaza la petición con 413.
    # Esto previene ataques de denegación de servicio (enviar archivos enormes).
    client_max_body_size 20M;

    # ═══════════════════════════════════════════════════════════════════════════
    # Routing de archivos estáticos
    # ═══════════════════════════════════════════════════════════════════════════

    # Hacer que los archivos estáticos sean cacheables por el navegador.
    # assets/ incluye CSS, JS, imágenes compiladas por Webpack/AssetMapper.
    location ~ ^/assets/ {
        # expires: le dice al navegador que cachee por 1 año.
        # Esto reduce peticiones HTTP en el navegador del usuario.
        expires 1y;

        # add_header: agrega el header Cache-Control.
        # public: el navegador Y los proxies pueden cachear.
        # immutable: el archivo NO cambia (se versiona con hash).
        add_header Cache-Control "public, immutable";

        # no_packet: Nginx no intenta buscar el archivo en el directorio raíz.
        # Solo sirve archivos que existen en la ubicación exacta.
        try_files $uri =404;
    }

    # Favicon: si no existe favicon.ico, retornar 204 (No Content)
    # en lugar de 404 (evita ruido en logs).
    location = /favicon.ico { access_log off; log_not_found off; }

    # Robots.txt: lo mismo que favicon.
    location = /robots.txt  { access_log off; log_not_found off; }

    # ═══════════════════════════════════════════════════════════════════════════
    # Routing de Symfony (FastCGI)
    # ═══════════════════════════════════════════════════════════════════════════

    # Bloque location /: maneja TODAS las peticiones que no son archivos estáticos.
    # La ubicación con "/" es la más general (catch-all).
    location / {
        # try_files: intenta servir el archivo estático primero.
        # Si no existe, redirige a index.php (el front controller de Symfony).
        # $uri: la ruta original (ej: /api/v1/transacciones)
        # $uri/: buscar directorio (para / -> /index.html)
        # /index.php?$query_string: fallback al front controller
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Bloque location para archivos PHP.
    # Solo ejecuta PHP los archivos que terminan en .php.
    # Esto previene que archivos PHP arbitrarios se ejecuten (seguridad).
    location ~ ^/index\.php(/|$) {
        # split_clients: divide el tráfico en 2 grupos (A/B testing).
        # Esto es un ejemplo; en producción lo quitarías.
        # $request_id: identificador único de la petición (para tracing).
        split_clients $request_id $variant_name 2;

        # fastcgi_pass: dirección del servidor PHP-FPM.
        # En Docker networking: el nombre del servicio es el hostname.
        # "php-fpm" es el nombre del servicio en docker-compose.yml.
        # Puerto 9000: el que definimos en php-fpm-pool.conf.
        fastcgi_pass php-fpm:9000;

        # fastcgi_split_path_info: divide la URI en script + path info.
        # Ejemplo: /app.php/some/path → script=/app.php, path=/some/path
        # Esto es necesario para que Symfony funcione correctamente.
        fastcgi_split_path_info ^(.+\.php)(/.*)$;

        # Incluir la configuración de fastcgi de Nginx.
        # Este archivo define parámetros como timeout, buffers, etc.
        include fastcgi_params;

        # fastcgi_param: parámetros adicionales enviados a PHP-FPM.
        # SCRIPT_FILENAME: el archivo PHP que PHP-FPM debe ejecutar.
        # $document_root: el valor de "root" (=/app/public).
        # $fastcgi_script_name: el nombre del script (ej: index.php).
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        # Parámetros de Symfony.
        # APP_ENV: entorno de la aplicación (dev, test, prod).
        # APP_DEBUG: si mostrar errores detallados (true en dev).
        fastcgi_param APP_ENV dev;
        fastcgi_param APP_DEBUG 1;

        # Parámetros de seguridad.
        # HTTPS: indicar si la conexión es HTTPS (para redirects).
        # Si Nginx está detrás de un load balancer, $https puede no estar definido.
        fastcgi_param HTTPS off;

        # Parámetros de performance.
        # fastcgi_buffer_size: buffer para la primera parte de la respuesta.
        # Headers de respuesta pueden ser grandes (cookies, CORS headers).
        fastcgi_buffer_size 32k;

        # fastcgi_buffers: buffers para el body de la respuesta.
        # 16 buffers de 16KB = 256KB total.
        # Si la respuesta es más grande, se escribe a disco temporalmente.
        fastcgi_buffers 16 16k;

        # fastcgi_read_timeout: tiempo máximo de espera para PHP-FPM.
        # 30s es razonable para la mayoría de requests API.
        # Para tareas pesadas (reports), aumentar o usar jobs asíncronos.
        fastcgi_read_timeout 30s;
    }

    # Bloque para ejecutar PHP fuera de index.php.
    # Esto permite ejecutar scripts PHP sueltos (ej: composer, artisan).
    location ~ \.php$ {
        # Verificar que el archivo existe ANTES de enviarlo a PHP-FPM.
        # Esto previene exploits donde se ejecuta un PHP que no existe.
        try_files $uri =404;

        fastcgi_pass php-fpm:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param APP_ENV dev;
        fastcgi_param APP_DEBUG 1;
        fastcgi_buffer_size 32k;
        fastcgi_buffers 16 16k;
        fastcgi_read_timeout 30s;
    }

    # Bloque de denegación de archivos ocultos.
    # Archivos como .htaccess, .env, .git no deben ser accesibles públicamente.
    location ~ /\.ht {
        deny all;  # Retornar 403 Forbidden
    }

    location ~ /\.env {
        deny all;  # Retornar 403 Forbidden
    }

    # Deshabilitar el header Server para no revelar información.
    # Un atacante no debe saber que usas Nginx, PHP 8.3, Symfony, etc.
    server_tokens off;
}

# Formato de log personalizado.
# Incluye更多信息 que el formato por defecto de Nginx.
# $remote_addr: IP del cliente
# $remote_user: usuario HTTP (si hay autenticación básica)
# [$time_local]: fecha/hora de la petición
# $request: método + URI + versión HTTP (ej: "GET /api/v1/transacciones HTTP/1.1")
# $status: código de respuesta HTTP
# $body_bytes_sent: tamaño del body de respuesta en bytes
# $http_referer: de qué página vino el cliente
# $http_user_agent: navegador/app del cliente
# $request_time: tiempo total de la petición en milisegundos
log_format main '$remote_addr - $remote_user [$time_local] '
                '"$request" $status $body_bytes_sent '
                '"$http_referer" "$http_user_agent" '
                '$request_time';

```

## docker/php-fpm/php-fpm-pool.conf

```nginx
; php-fpm-pool.conf — Configuración del pool de PHP-FPM
;
; Un "pool" es un grupo de procesos PHP que manejan requests.
; Por defecto, el pool se llama "www".
;
; Cada proceso del pool puede manejar 1 request a la vez.
; Si llegan 50 requests concurrentes y solo tienes 5 procesos,
; 45 requests esperan en cola.

; Nombre del pool (usado en logs y monitoreo).
[www]

; El usuario con el que ejecutan los procesos PHP.
; NUNCA usar root en producción.
user = appuser
group = appgroup

; Cómo FPM acepta conexiones de Nginx.
; /run/php/php8.3-fpm.sock: archivo de socket Unix (más rápido que TCP)
; 127.0.0.1:9000: TCP (útil para Docker networking)
; En Docker usamos TCP porque Nginx y PHP-FPM están en contenedores separados.
listen = 0.0.0.0:9000

; Configuración del proceso manager.
; static: número fijo de procesos (mejor para containers con RAM limitada)
; dynamic: min/max de procesos según demanda
; ondemand: crear procesos bajo demanda (más lento al inicio)
; Para Docker, usamos 'static' porque el orquestador (K8s) ya maneja la escala.
pm = static

; Número de procesos hijos. Ajustar según RAM disponible.
; Cada proceso PHP consume ~20-50MB de RAM.
; Con 4GB de RAM: 4000MB / 50MB = ~80 procesos máximos.
pm.max_children = 5

; Tiempo máximo (en segundos) que un proceso puede estar idle (sin requests).
; 10s es razonable para desarrollo. En producción, 60s.
pm.process_idle_timeout = 10s

; Número máximo de requests que un proceso puede atender antes de reiniciarse.
; Esto previene memory leaks (fugas de memoria).
; 500 es un buen balance: evita leaks sin reiniciar demasiado frecuente.
pm.max_requests = 500

; Logging.
; Si el directorio de log no existe, FPM no arranca.
; En Docker, redirigimos logs a stdout/stderr (convención de 12-factor app).
; PERO necesitamos que el directorio exista para que FPM no falle al iniciar.
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /proc/self/fd/2

; Variables de entorno de PHP.
; Las pasamos al script PHP para que Symfony las lea.
clear_env = no

; Límites de ejecución.
; Estos valores previerten que scripts infinitos consuman todos los recursos.
; memory_limit: máximo de memoria por script (256MB es razonable para Symfony)
; max_execution_time: máximo de tiempo en segundos (30s para API, 300s para tareas pesadas)
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 30
php_admin_value[upload_max_filesize] = 10M
php_admin_value[post_max_size] = 12M

; Date timezone — debe coincidir con el servidor de base de datos.
php_admin_value[date.timezone] = America/Mexico_City

; Realpath cache — mejora performance de resolución de rutas.
; Symfony lee muchos archivos de configuración, este cache acelera eso.
php_admin_value[realpath_cache_size] = 4096K
php_admin_value[realpath_cache_ttl] = 600

```

## docker-compose.yml

```yaml
# docker-compose.yml — Orquestación de servicios para PrestaFlow
#
# Docker Compose define y ejecuta múltiples contenedores como un solo sistema.
# Cada servicio se ejecuta en su propio contenedor (aislamiento total).
#
# Uso:
#   docker compose up -d          → Iniciar todos los servicios
#   docker compose down           → Detener y eliminar contenedores
#   docker compose logs -f        → Ver logs en tiempo real
#   docker compose exec php bash  → Shell dentro del contenedor PHP
#   docker compose ps             → Ver estado de los servicios
#
# Red de servicios:
#   ┌──────────┐     ┌──────────┐     ┌────────────┐     ┌──────────┐
#   │  Nginx   │────▶│ PHP-FPM  │────▶│ PostgreSQL │     │ RabbitMQ │
#   │  :80     │     │  :9000   │     │  :5432     │     │  :5672   │
#   └──────────┘     └──────────┘     └────────────┘     │  :15672  │
#                                                         └──────────┘

# Versión del formato de docker-compose.yml.
# "3.8" es la última estable y soporta todas las features que necesitamos.
# IMPORTANTE: esta versión es del formato de archivo, NO de Docker Compose.
version: '3.8'

# Servicios: cada entrada define un contenedor independiente.
services:

  # ═══════════════════════════════════════════════════════════════════════════
  # NGINX — Servidor web reverso
  # ═══════════════════════════════════════════════════════════════════════════
  # Nginx recibe peticiones HTTP y las distribuye:
  #   - Archivos estáticos: sirve directamente
  #   - PHP: delega a PHP-FPM via FastCGI
  nginx:
    # build: construir la imagen desde un Dockerfile local.
    # context: directorio raíz del build (donde está el Dockerfile).
    # dockerfile: ruta al Dockerfile relativo al context.
    build:
      context: .
      dockerfile: docker/nginx/Dockerfile

    # ports: mapeo de puertos del contenedor al host.
    # "8080:80" significa: puerto 8080 del host → puerto 80 del contenedor.
    # En el host accedes a http://localhost:8080
    # En Docker networking, otros contenedores acceden via "nginx:80"
    ports:
      - "8080:80"

    # volumes: montar directorios del host dentro del contenedor.
    # Esto permite editar archivos en tu editor y que los cambios
    # se reflejen inmediatamente en el contenedor (sin rebuild).
    #
    # "../../:/app": monta la RAÍZ DEL MONOREPO en /app dentro del contenedor.
    #   Esto permite que Nginx acceda al código fuente de Symfony.
    #   El "../.." es porque el docker-compose.yml está en parte2/soluciones/.
    #
    # "./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf":
    #   Monta nuestra configuración de Nginx sobre la configuración por defecto.
    volumes:
      - "../../:/app"
      - "./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro"

    # depends_on: Define el ORDEN de inicio de servicios.
    # Nginx depende de PHP-FPM (no puede servir PHP sin él).
    # Con healthcheck, espera a que PHP-FPM esté realmente listo.
    depends_on:
      php-fpm:
        condition: service_healthy

    # networks: red de Docker donde este servicio participa.
    # Todos los servicios en la misma red se pueden comunicar por nombre.
    # "prestaflow-net" es la red que definimos abajo.
    networks:
      - prestaflow-net

  # ═══════════════════════════════════════════════════════════════════════════
  # PHP-FPM — Runtime de PHP
  # ═══════════════════════════════════════════════════════════════════════════
  php-fpm:
    build:
      context: .
      dockerfile: docker/php-fpm/Dockerfile

    # environment: Variables de entorno para la aplicación.
    # Symfony lee APP_ENV para determinar el entorno de ejecución.
    # DATABASE_*: credenciales de conexión a PostgreSQL.
    # MESSENGER_TRANSPORT_DSN: conexión a RabbitMQ (Parte 3).
    environment:
      APP_ENV: dev
      APP_DEBUG: "1"
      DATABASE_URL: "postgresql://prestaflow:prestaflow_secret@postgres:5432/prestaflow_wallet?serverVersion=16&charset=utf8"
      MESSENGER_TRANSPORT_DSN: "amqp://guest:guest@rabbitmq:5672/%2f/messages"

    # volumes: montar el código fuente para desarrollo.
    # Sin esto, los cambios en tu editor no se reflejan en el contenedor.
    volumes:
      - "../../:/app"

    # Health check: verificar que PHP-FPM está listo para recibir requests.
    # healthcheck.cmd: comando que Docker ejecuta cada intervalo.
    # Si el comando falla 3 veces, el servicio se marca como "unhealthy".
    healthcheck:
      test: ["CMD-SHELL", "php-fpm -t 2>/dev/null || exit 1"]
      interval: 10s       # Ejecutar cada 10 segundos
      timeout: 5s         # Timeout del comando (si tarda más, falla)
      retries: 3          # Número de intentos antes de marcar unhealthy
      start_period: 15s   # Período de gracia al iniciar (el servicio arranca lento)

    networks:
      - prestaflow-net

  # ═══════════════════════════════════════════════════════════════════════════
  # POSTGRESQL — Base de datos relacional
  # ═══════════════════════════════════════════════════════════════════════════
  # PostgreSQL es la base de datos principal para datos transaccionales.
  # Version 16: última estable con soporte LTS hasta 2028.
  postgres:
    # image: usar imagen oficial de Docker Hub.
    # "16-alpine": versión 16 en Alpine Linux (más ligero).
    image: postgres:16-alpine

    # environment: configuración de PostgreSQL.
    # POSTGRES_DB: nombre de la base de datos a crear al iniciar.
    # POSTGRES_USER: usuario administrador de la base de datos.
    # POSTGRES_PASSWORD: contraseña del usuario administrador.
    #   IMPORTANTE: NUNCA usar passwords débiles ni committing a Git.
    #   En producción usar Docker Secrets o Vault.
    environment:
      POSTGRES_DB: prestaflow_wallet
      POSTGRES_USER: prestaflow
      POSTGRES_PASSWORD: prestaflow_secret

    # ports: exponer PostgreSQL al host para herramientas externas.
    # "5433:5432": host usa 5433 para evitar conflicto con PostgreSQL local.
    # Solo para desarrollo. En producción, PostgreSQL NO se expone externamente.
    ports:
      - "5433:5432"

    # volumes: persistir datos del contenedor.
    # Sin un volume, los datos se PERDERían al eliminar el contenedor.
    # "pgdata:" es un named volume (Docker gestiona el almacenamiento).
    volumes:
      - pgdata:/var/lib/postgresql/data

    # Health check: verificar que PostgreSQL acepta conexiones.
    # pg_isready es un tool de PostgreSQL que verifica si el servidor está listo.
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U prestaflow -d prestaflow_wallet"]
      interval: 5s
      timeout: 5s
      retries: 5

    networks:
      - prestaflow-net

  # ═══════════════════════════════════════════════════════════════════════════
  # RABBITMQ — Message Broker
  # ═══════════════════════════════════════════════════════════════════════════
  # RabbitMQ implementa el patrón "message broker":
  #   - Los productores envían mensajes a "exchanges"
  #   - Los exchanges routan mensajes a "queues"
  #   - Los consumidores leen de las queues
  #
  # Esto desacopla servicios: el wallet-service no sabe quién consume
  # sus eventos. Puede ser notification-service, audit-service, etc.
  rabbitmq:
    image: rabbitmq:3.13-management-alpine

    # management: incluye la interfaz web en puerto 15672.
    # Puedes acceder a http://localhost:15672 con guest/guest.
    environment:
      RABBITMQ_DEFAULT_USER: guest
      RABBITMQ_DEFAULT_PASS: guest

    # Exponer puertos:
    #   5672: AMQP protocol (conexión de la aplicación)
    #   15672: Management UI (interfaz web para monitorear)
    #   15692: Prometheus metrics (para Datadog)
    ports:
      - "5672:5672"
      - "15672:15672"
      - "15692:15692"

    # Health check: verificar que RabbitMQ acepta conexiones AMQP.
    healthcheck:
      test: ["CMD", "rabbitmq-diagnostics", "-q", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

    networks:
      - prestaflow-net

  # ═══════════════════════════════════════════════════════════════════════════
  # DATADOG AGENT — Observabilidad
  # ═══════════════════════════════════════════════════════════════════════════
  # El Datadog Agent recoge métricas, traces y logs de todos los contenedores.
  # Se comunica con la plataforma Datadog (SaaS) para visualización y alertas.
  #
  # Para configuración local sin Datadog SaaS, usaramos logs estructurados
  # y tracing personalizado que veremos en la Parte 5.
  datadog-agent:
    # Si no tienes cuenta de Datadog, puedes comentar este servicio.
    # La aplicación funciona sin él; solo pierdes trazas y métricas.
    image: datadog/agent:7

    environment:
      # DD_API_KEY: tu API key de Datadog.
      # Si no tienes cuenta, usar "placeholder" para que el agente arranque.
      DD_API_KEY: "${DD_API_KEY:-placeholder}"
      # DD_SITE: región de Datadog (us1.datadoghq.com para EU, us3 para US)
      DD_SITE: "datadoghq.com"
      # DD_APM_ENABLED: habilitar APM (Application Performance Monitoring).
      # Esto permite enviar traces desde la aplicación PHP.
      DD_APM_ENABLED: "true"
      # DD_LOGS_ENABLED: habilitar recopilación de logs.
      DD_LOGS_ENABLED: "true"
      # DD_APM_PORT: puerto donde el agente escucha traces.
      # La aplicación PHP enviará traces a este puerto.
      DD_APM_PORT: "8126"
      # DD_TRACE_AGENT_URL: URL del agente para traces.
      DD_TRACE_AGENT_URL: "http://datadog-agent:8126"

    # ports: exponer el puerto de APM para traces externos.
    # La aplicación PHP se conecta a este puerto para enviar traces.
    ports:
      - "8126:8126"   # APM traces
      - "8125:8125/udp" # DogStatsD (métricas custom)

    # volumes: montar el socket de Docker para recoger logs de contenedores.
    # /var/run/docker.sock: socket de Docker (el agente detecta nuevos contenedores).
    # /proc/: información de procesos del host.
    # /sys/: información del kernel.
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - /proc/:/host/proc/:ro
      - /sys/:/host/sys/:ro
      - /etc/passwd:/etc/passwd:ro

    # El agente necesita permisos de root para acceder a /proc y /sys.
    # En producción, usar capabilities específicas en lugar de full root.
    user: root

    networks:
      - prestaflow-net

# ═══════════════════════════════════════════════════════════════════════════════
# VOLUMES PERSISTENTES
# ═══════════════════════════════════════════════════════════════════════════════
# Los named volumes persisten datos más allá del ciclo de vida de los contenedores.
# Si ejecutas `docker compose down`, los datos NO se pierden.
# Si ejecutas `docker compose down -v`, SÍ se pierden.
volumes:
  pgdata:
    # driver: motor de almacenamiento.
    # local: almacenamiento local del host (default).
    # En producción: usar nfs, ebs, o云 storage.
    driver: local

# ═══════════════════════════════════════════════════════════════════════════════
# REDES
# ═══════════════════════════════════════════════════════════════════════════════
# Las redes definen qué servicios pueden comunicarse entre sí.
# Todos los servicios en la misma red se resuelven por NOMBRE de servicio.
# Ejemplo: PHP-FPM se conecta a PostgreSQL usando "postgres:5432"
# (no necesita IP, Docker resuelve el nombre al contenedor).
networks:
  prestaflow-net:
    driver: bridge
    # bridge: red aislada del host. Los contenedores solo ven otros contenedores
    # en la misma red. Más seguro que "host" (comparte la red del host).

```

## migrations/Version20260910000000.php

```php
<?php
// migrations/Version20260910000000.php — Primera migración de Doctrine
//
# MIGRATION: Script SQL que modifica el esquema de la base de datos.
# Doctrine Migrations es el sistema de version control para tu esquema.
#
# ¿Por qué migraciones y no "doctrine:schema:create"?
#   1. Las migraciones son versionadas (git history del esquema)
#   2. Se pueden ejecutar en producción sin perder datos
#   3. Se pueden revertir si algo sale mal
#   4. El equipo trabaja en el mismo esquema
#
# Flujo:
#   1. Modificar entity (add property, change type, etc.)
#   2. Ejecutar: php bin/console doctrine:migrations:diff
#     Doctrine compara el entity mapping con la BD y genera la migración
#   3. Revisar la migración generada (¡nunca ejecutar sin revisar!)
#   4. Ejecutar: php bin/console doctrine:migrations:migrate

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migración: crear tabla billetera y movimientos.
 *
 * Esta migración crea el esquema inicial para el wallet-service.
 * Se genera automáticamente con doctrine:migrations:diff.
 */
final class Version20260910000000 extends AbstractMigration
{
    /**
     * Descripción de qué hace esta migración.
     * Aparece en la tabla de migraciones y en logs.
     */
    public function getDescription(): string
    {
        return 'Crear tablas billetera y movimiento para wallet-service';
    }

    /**
     * Aplicar la migración (UP).
     *
     * Este método ejecuta las queries SQL para CREAR/MODIFICAR el esquema.
     * Doctrine genera estas queries automáticamente, pero puedes editarlas.
     *
     * @param Schema $schema Esquema actual de la BD (Doctrine lo lee automáticamente)
     */
    public function up(Schema $schema): void
    {
        // ═══════════════════════════════════════════════════════════════════════
        // TABLA: billetera
        // ═══════════════════════════════════════════════════════════════════════

        // addTable(): crea una tabla nueva en el esquema.
        $billeteraTable = $schema->createTable('billetera');

        // addColumn(): agrega una columna a la tabla.
        // El primer argumento es el nombre de la columna.
        // El segundo es la definición (tipo, longitud, nullable, etc.).

        // Columna ID: primary key de la billetera.
        // VARCHAR(36): suficiente para un UUID v4.
        $billeteraTable->addColumn('id', 'string', [
            'length' => 36,
            'notnull' => true,  // La billetera SIEMPRE tiene ID
        ]);

        // Columna usuario_id: referencia al propietario.
        // En la Parte 3 crearemos una tabla usuarios y foreign key.
        $billeteraTable->addColumn('usuario_id', 'string', [
            'length' => 36,
            'notnull' => true,
        ]);

        // Columna moneda: código ISO 4217 de 3 letras.
        // ENUM en PostgreSQL: solo acepta valores definidos.
        // Doctrine genera: CHECK (moneda IN ('MXN', 'USD', 'EUR'))
        $billeteraTable->addColumn('moneda', 'string', [
            'length' => 3,
            'notnull' => true,
        ]);

        // Columna saldo_centavos: saldo actual en centavos (entero).
        // INTEGER en PostgreSQL: rango de -2^31 a 2^31-1.
        // Suficiente para montos hasta $21,474,836.47 MXN.
        $billeteraTable->addColumn('saldo_centavos', 'integer', [
            'notnull' => true,
            'default' => 0,  // Saldo inicial de cualquier billetera es 0
        ]);

        // Columna creado_en: timestamp de creación.
        // La usamos para ordenar billeteras y para auditoría.
        $billeteraTable->addColumn('creado_en', 'datetime_immutable', [
            'notnull' => true,
            // default: Doctrine generará SQL para usar la fecha actual.
            // En PostgreSQL: DEFAULT CURRENT_TIMESTAMP
        ]);

        // Primary key: la columna ID es la llave primaria.
        $billeteraTable->setPrimaryKey(['id']);

        // Index: índice para búsquedas por usuario_id.
        // Sin esto, buscar billeteras de un usuario sería O(n).
        // Con el índice, es O(log n) (~4 pasos para 10,000 billeteras).
        $billeteraTable->addIndex(['usuario_id']);

        // ═══════════════════════════════════════════════════════════════════════
        // TABLA: movimiento
        // ═══════════════════════════════════════════════════════════════════════

        $movimientoTable = $schema->createTable('movimiento');

        $movimientoTable->addColumn('id', 'string', [
            'length' => 36,
            'notnull' => true,
        ]);

        // billetera_id: referencia a la billetera padre.
        // Foreign key: garantiza que cada movimiento pertenezca a una billetera existente.
        $movimientoTable->addColumn('billetera_id', 'string', [
            'length' => 36,
            'notnull' => true,
        ]);

        // monto_centavos: monto del movimiento en centavos.
        // Puede ser positivo (depósito) o negativo (retiro).
        $movimientoTable->addColumn('monto_centavos', 'integer', [
            'notnull' => true,
        ]);

        // moneda: moneda del movimiento (misma que la billetera).
        $movimientoTable->addColumn('moneda', 'string', [
            'length' => 3,
            'notnull' => true,
        ]);

        // tipo: "deposito" o "retiro".
        $movimientoTable->addColumn('tipo', 'string', [
            'length' => 20,
            'notnull' => true,
        ]);

        // descripcion: texto legible para el usuario.
        $movimientoTable->addColumn('descripcion', 'string', [
            'length' => 255,
            'notnull' => true,
        ]);

        // creado_en: timestamp del movimiento.
        $movimientoTable->addColumn('creado_en', 'datetime_immutable', [
            'notnull' => true,
        ]);

        $movimientoTable->setPrimaryKey(['id']);

        // Índices para movimientos.
        // Un usuario consulta "todos los movimientos de billetera X" frecuentemente.
        $movimientoTable->addIndex(['billetera_id']);

        // Foreign key: billetera_id referencia billetera.id.
        // ON DELETE CASCADE: si se elimina la billetera, se eliminan sus movimientos.
        // Sin esto, tendríamos movimientos huérfanos (sin billetera padre).
        $movimientoTable->addForeignKeyConstraint(
            'billetera',            // Tabla referenciada
            ['billetera_id'],       // Columnas de esta tabla
            ['id'],                 // Columnas de la tabla referenciada
            ['onDelete' => 'CASCADE']  // Comportamiento al eliminar
        );
    }

    /**
     * Revertir la migración (DOWN).
     *
     * Este método se ejecuta con doctrine:migrations:migrate --down
     * o doctrine:migrations:rollback.
     * Es el "undo" de la migración.
     *
     * IMPORTANTE: Siempre implementar down() para poder revertir.
     * Sin esto, si la migración falla en producción, no hay forma de volver atrás.
     *
     * @param Schema $schema Esquema actual de la BD
     */
    public function down(Schema $schema): void
    {
        // dropTable(): elimina la tabla y TODOS sus datos.
        // PostgreSQL primero elimina las foreign keys que referencian esta tabla.
        $schema->dropTable('movimiento');
        $schema->dropTable('billetera');
    }
}

```

## src/Application/Service/CrearBilleteraService.php

```php
<?php
// src/Application/Service/CrearBilleteraService.php — Application Service
//
# APPLICATION SERVICE: Orquesta las operaciones de negocio.
# No contiene lógica de dominio (eso está en Billetera.php).
# No conoce la base de datos (eso está en BilleteraRepository.php).
#
# Responsabilidades:
#   1. Recibir el request del controller
#   2. Crear la entidad de dominio
#   3. Persistir a través del repository
#   4. Retornar el resultado al controller
#
# Este es el patrón "Use Case" de Clean Architecture:
#   Controller → Application Service → Domain → Repository → Database

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Billetera;
use App\Domain\Moneda;
use App\Infrastructure\Repository\BilleteraRepository;
use Ramsey\Uuid\Uuid;

/**
 * Servicio de aplicación para crear billeteras nuevas.
 *
 * Cada use case de la aplicación tiene su propio servicio.
 * Esto sigue el Principio de Responsabilidad Única (SRP):
# un servicio, un use case.
 */
final class CrearBilleteraService
{
    /**
     * @param BilleteraRepository $repository Repositorio para persistir la billetera
     */
    public function __construct(
        private readonly BilleteraRepository $repository,
    ) {
    }

    /**
     * Ejecuta el use case: crear una billetera nueva.
     *
     * @param string $usuarioId ID del propietario de la billetera
     * @param string $monedaCode Código de moneda (ej: "MXN", "USD")
     *
     * @return Billetera La billetera creada con ID asignado
     *
     * @throws \InvalidArgumentException Si la moneda no es soportada
     */
    public function ejecutar(string $usuarioId, string $monedaCode): Billetera
    {
        // 1. Validar que la moneda sea soportada.
        // Moneda::from() lanza ValueError si el código no existe.
        // Lo convertimos a InvalidArgumentException para consistencia.
        try {
            $moneda = Moneda::from(strtoupper($monedaCode));
        } catch (\ValueError $e) {
            throw new \InvalidArgumentException(
                "Moneda no soportada: '{$monedaCode}'. "
                . "Use: MXN, USD, o EUR."
            );
        }

        // 2. Verificar que el usuario no tenga ya una billetera en esta moneda.
        // En PrestaFlow, un usuario solo puede tener UNA billetera por moneda.
        $billeterasExistentes = $this->repository->findByUsuario($usuarioId);
        foreach ($billeterasExistentes as $billetera) {
            if ($billetera->moneda() === $moneda) {
                throw new \InvalidArgumentException(
                    "El usuario '{$usuarioId}' ya tiene una billetera en {$moneda->value}"
                );
            }
        }

        // 3. Generar un ID único para la billetera.
        // Uuid::uuid4() genera un UUID v4 (random).
        // Lo convertimos a string para almacenamiento.
        $id = Uuid::uuid4()->toString();

        // 4. Crear la entidad de dominio.
        // El dominio valida que el saldo inicial sea 0 (implícito al no pasar movimientos).
        $billetera = new Billetera(
            id: $id,
            usuarioId: $usuarioId,
            moneda: $moneda,
        );

        // 5. Persistir a través del repository.
        // El repository ejecuta el INSERT en PostgreSQL.
        $this->repository->save($billetera);

        // 6. Retornar la billetera creada.
        // El controller recibirá esta entidad y la convertirá a JSON.
        return $billetera;
    }
}

```

## src/Application/Service/RealizarDepositoService.php

```php
<?php
// src/Application/Service/RealizarDepositoService.php — Application Service
//
# Use case: depositar dinero en una billetera.
# Este servicio orquesta: buscar billetera → validar → depositar → persistir → retornar

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Infrastructure\Repository\BilleteraRepository;

/**
 * Servicio de aplicación para realizar depósitos.
 */
final class RealizarDepositoService
{
    public function __construct(
        private readonly BilleteraRepository $repository,
    ) {
    }

    /**
     * Ejecuta el use case: depositar dinero en una billetera.
     *
     * @param string $billeteraId ID de la billetera destino
     * @param string $monto       Monto a depositar (string decimal)
     * @param string $monedaCode  Código de moneda
     * @param string $descripcion Descripción del depósito
     *
     * @return array{billetera: Billetera, balance_anterior: string, balance_nuevo: string}
     *
     * @throws \RuntimeException Si la billetera no existe
     * @throws \InvalidArgumentException Si el monto o moneda son inválidos
     */
    public function ejecutar(
        string $billeteraId,
        string $monto,
        string $monedaCode,
        string $descripcion,
    ): array {
        // 1. Buscar la billetera en la base de datos.
        $billetera = $this->repository->findById($billeteraId);

        if ($billetera === null) {
            throw new \RuntimeException(
                "Billetera no encontrada: '{$billeteraId}'"
            );
        }

        // 2. Capturar el balance ANTES del depósito.
        // Esto es importante para la respuesta: el cliente necesita saber
        # el estado antes y después de la operación.
        $balanceAnterior = $billetera->balance();

        // 3. Validar la moneda.
        $moneda = Moneda::from(strtoupper($monedaCode));
        if ($moneda !== $billetera->moneda()) {
            throw new \InvalidArgumentException(
                "Moneda inválida: la billetera es {$billetera->moneda()->value}, "
                . "pero se solicitó {$moneda->value}"
            );
        }

        // 4. Crear el Value Object Dinero.
        $dinero = Dinero::crear($monto, $moneda);

        // 5. Ejecutar el depósito en la entidad de dominio.
        // Aquí se ejecutan TODAS las reglas de negocio:
        #   - No se puede depositar monto cero
        #   - La moneda debe coincidir
        // Si alguna regla falla, se lanza una excepción de dominio.
        $billetera->depositar($dinero, $descripcion);

        // 6. Persistir los cambios.
        // Doctrine detecta que la entidad fue modificada y ejecuta un UPDATE.
        $this->repository->save($billetera);

        // 7. Retornar el resultado con contexto.
        // El controller usará esta información para construir la respuesta HTTP.
        return [
            'billetera' => $billetera,
            'balance_anterior' => $balanceAnterior->formateadoDecimal(),
            'balance_nuevo' => $billetera->balance()->formateadoDecimal(),
        ];
    }
}

```

## src/Controller/BilleteraController.php

```php
<?php
// src/Controller/BilleteraController.php — API Controller para billeteras
//
# Controller RESTful que expone la API de billeteras.
# Cada endpoint corresponde a un use case de la aplicación.
#
# Patrón "Thin Controller":
#   1. Recibe la petición HTTP
#   2. Extrae parámetros
#   3. Llama al Application Service
#   4. Convierte la respuesta a JSON
#   5. Retorna el status code apropiado
#
# NO contiene lógica de negocio, validación de dominio, ni queries SQL.

declare(strict_types=1);

namespace App\Controller;

use App\Application\Service\CrearBilleteraService;
use App\Application\Service\RealizarDepositoService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API de billeteras para PrestaFlow.
 *
 * Endpoints:
 *   POST   /api/v1/billeteras              → Crear billetera
 *   GET    /api/v1/billeteras/{id}         → Consultar billetera
 *   POST   /api/v1/billeteras/{id}/deposito → Realizar depósito
 *   GET    /api/v1/billeteras/{id}/movimientos → Historial de movimientos
 */
class BilleteraController extends AbstractController
{
    /**
     * POST /api/v1/billeteras — Crear una billetera nueva.
     *
     * Request body:
     *   {
     *     "usuario_id": "user_001",
     *     "moneda": "MXN"
     *   }
     *
     * Response 201:
     *   {
     *     "id": "uuid",
     *     "usuario_id": "user_001",
     *     "moneda": "MXN",
     *     "saldo": "0.00",
     *     "creado_en": "2026-09-10T15:30:00+00:00"
     *   }
     *
     * Response 400 (moneda inválida):
     *   { "error": { "codigo": "MONEDA_NO_SOPORTADA", "mensaje": "..." } }
     *
     * Response 409 (ya existe billetera para esta moneda):
     *   { "error": { "codigo": "BILLETERA_DUPLICADA", "mensaje": "..." } }
     */
    #[Route('/api/v1/billeteras', name: 'api_billeteras_crear', methods: ['POST'])]
    public function crear(
        Request $request,
        CrearBilleteraService $service,
    ): JsonResponse {
        try {
            // Decodificar el body JSON de la petición.
            // request->toArray() retorna un array asociativo.
            // Si el body no es JSON válido, retorna un array vacío.
            $datos = $request->toArray();

            // Validar campos requeridos.
            // Si falta alguno, retornar 400 Bad Request.
            if (!isset($datos['usuario_id']) || !isset($datos['moneda'])) {
                return $this->json(
                    data: [
                        'error' => [
                            'codigo' => 'CAMPOS_REQUERIDOS',
                            'mensaje' => 'Los campos "usuario_id" y "moneda" son obligatorios',
                        ],
                    ],
                    status: Response::HTTP_BAD_REQUEST,
                );
            }

            // Ejecutar el use case.
            // El service valida la moneda, genera el ID, y persiste.
            $billetera = $service->ejecutar(
                usuarioId: $datos['usuario_id'],
                monedaCode: $datos['moneda'],
            );

            // Retornar 201 Created con los datos de la billetera.
            // El header Location indica la URL del recurso creado.
            return $this->json(
                data: [
                    'id' => $billetera->id(),
                    'usuario_id' => $billetera->usuarioId(),
                    'moneda' => $billetera->moneda()->value,
                    'saldo' => $billetera->balance()->formateadoDecimal(),
                    'creado_en' => $billetera->creadoEn()->format('c'),
                ],
                status: Response::HTTP_CREATED,
                headers: [
                    // Header Location: URL del recurso recién creado.
                    // El cliente puede usar esta URL para GET, PUT, DELETE.
                    'Location' => "/api/v1/billeteras/{$billetera->id()}",
                ],
            );
        } catch (\InvalidArgumentException $e) {
            // Error de validación de negocio.
            // Determinar el código de error basándose en el mensaje.
            $codigo = str_contains($e->getMessage(), 'ya tiene una billetera')
                ? 'BILLETERA_DUPLICADA'
                : 'MONEDA_NO_SOPORTADA';

            return $this->json(
                data: [
                    'error' => [
                        'codigo' => $codigo,
                        'mensaje' => $e->getMessage(),
                    ],
                ],
                status: $codigo === 'BILLETERA_DUPLICADA'
                    ? Response::HTTP_CONFLICT
                    : Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * POST /api/v1/billeteras/{id}/deposito — Realizar un depósito.
     *
     * Request body:
     *   {
     *     "monto": "1500.00",
     *     "descripcion": "Transferencia bancaria"
     *   }
     *
     * Response 200:
     *   {
     *     "transaccion": {
     *       "tipo": "deposito",
     *       "monto": "1500.00",
     *       "moneda": "MXN",
     *       "descripcion": "Transferencia bancaria"
     *     },
     *     "balance_anterior": "5000.00",
     *     "balance_nuevo": "6500.00"
     *   }
     */
    #[Route('/api/v1/billeteras/{id}/deposito', name: 'api_billeteras_deposito', methods: ['POST'])]
    public function deposito(
        string $id,
        Request $request,
        RealizarDepositoService $service,
    ): JsonResponse {
        try {
            $datos = $request->toArray();

            // Validar campos requeridos
            if (!isset($datos['monto']) || !isset($datos['descripcion'])) {
                return $this->json(
                    data: [
                        'error' => [
                            'codigo' => 'CAMPOS_REQUERIDOS',
                            'mensaje' => 'Los campos "monto" y "descripcion" son obligatorios',
                        ],
                    ],
                    status: Response::HTTP_BAD_REQUEST,
                );
            }

            // Ejecutar el depósito.
            // El service busca la billetera, valida, deposita, y persiste.
            $resultado = $service->ejecutar(
                billeteraId: $id,
                monto: $datos['monto'],
                monedaCode: $datos['moneda'] ?? $resultado['billetera']->moneda()->value,
                descripcion: $datos['descripcion'],
            );

            return $this->json(
                data: [
                    'transaccion' => [
                        'tipo' => 'deposito',
                        'monto' => $datos['monto'],
                        'moneda' => $resultado['billetera']->moneda()->value,
                        'descripcion' => $datos['descripcion'],
                    ],
                    'balance_anterior' => $resultado['balance_anterior'],
                    'balance_nuevo' => $resultado['balance_nuevo'],
                ],
                status: Response::HTTP_OK,
            );
        } catch (\RuntimeException $e) {
            return $this->json(
                data: [
                    'error' => [
                        'codigo' => 'BILLETERA_NO_ENCONTRADA',
                        'mensaje' => $e->getMessage(),
                    ],
                ],
                status: Response::HTTP_NOT_FOUND,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(
                data: [
                    'error' => [
                        'codigo' => 'DATOS_INVALIDOS',
                        'mensaje' => $e->getMessage(),
                    ],
                ],
                status: Response::HTTP_BAD_REQUEST,
            );
        }
    }

    /**
     * GET /api/v1/billeteras/{id} — Consultar billetera.
     *
     * Response 200:
     *   {
     *     "id": "uuid",
     *     "usuario_id": "user_001",
     *     "moneda": "MXN",
     *     "saldo": "6500.00",
     *     "total_movimientos": 5,
     *     "creado_en": "2026-09-10T15:30:00+00:00"
     *   }
     */
    #[Route('/api/v1/billeteras/{id}', name: 'api_billeteras_obtener', methods: ['GET'])]
    public function obtener(
        string $id,
        \App\Infrastructure\Repository\BilleteraRepository $repository,
    ): JsonResponse {
        $billetera = $repository->findById($id);

        if ($billetera === null) {
            return $this->json(
                data: [
                    'error' => [
                        'codigo' => 'BILLETERA_NO_ENCONTRADA',
                        'mensaje' => "Billetera no encontrada: '{$id}'",
                    ],
                ],
                status: Response::HTTP_NOT_FOUND,
            );
        }

        return $this->json(
            data: [
                'id' => $billetera->id(),
                'usuario_id' => $billetera->usuarioId(),
                'moneda' => $billetera->moneda()->value,
                'saldo' => $billetera->balance()->formateadoDecimal(),
                'total_movimientos' => $billetera->totalMovimientos(),
                'creado_en' => $billetera->creadoEn()->format('c'),
            ],
            status: Response::HTTP_OK,
        );
    }
}

```

## src/Infrastructure/Persistence/Doctrine/Mapping/BilleteraEntity.php

```php
<?php
// src/Domain/Billetera.orm.php — Mapping de Doctrine para Billetera Entity
//
# Doctrine Mapping: define cómo se mapea una clase PHP a una tabla SQL.
#
# Usamos "XML mapping" en lugar de atributos porque:
#   1. La lógica de dominio queda separada de la persistencia
#   2. El dominio NO importa Doctrine (clean architecture)
#   3. Es más fácil de mantener en proyectos grandes
#
# Nota: Creamos una entity separada (BilleteraEntity) en Infrastructure/
# que mapea la tabla, y el dominio Billetera se mantiene puro.
# Esto es el "Repository Pattern" en arquitectura hexagonal.

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Mapping;

use Doctrine\ORM\Mapping as ORM;
use App\Domain\Billetera;

/**
 * Mapping de Doctrine para la tabla billetera.
 *
 * Esta clase NO es una entity de dominio. Es un "wrapper" que Doctrine
 * usa para persistir la Billetera del dominio.
 *
 * Para simplificar el curso, usamos una sola entity que hereda del dominio.
 * En producción, podrías usar un Mapper dedicado.
 */
#[ORM\Entity(repositoryClass: \App\Infrastructure\Repository\BilleteraRepository::class)]
#[ORM\Table(name: 'billetera')]
class BilleteraEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 36)]
    private string $usuarioId;

    #[ORM\Column(type: 'string', length: 3)]
    private string $moneda;

    #[ORM\Column(type: 'integer')]
    private int $saldoCentavos;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $creadoEn;

    public function getId(): string
    {
        return $this->id;
    }

    public function getUsuarioId(): string
    {
        return $this->usuarioId;
    }

    public function getMoneda(): string
    {
        return $this->moneda;
    }

    public function getSaldoCentavos(): int
    {
        return $this->saldoCentavos;
    }

    public function getCreadoEn(): \DateTimeImmutable
    {
        return $this->creadoEn;
    }
}

```

## src/Infrastructure/Persistence/Doctrine/Type/UuidType.php

```php
<?php
// src/Infrastructure/Persistence/Doctrine/Type/UuidType.php — Tipo custom para Doctrine
//
# Doctrine ORM necesita tipos personalizados para manejar valores que no son
# tipos nativos de PHP/SQL (como UUIDs, Money, etc.).
#
# Este tipo convierte:
#   PHP → SQL: string UUID → string en columna VARCHAR
#   SQL → PHP: string de DB → string UUID en la entity
#
# Sin este tipo, Doctrine no sabría cómo guardar/recuperar UUIDs.

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Tipo Doctrine para identificadores UUID.
 *
 * Almacena UUID como strings en la base de datos (VARCHAR).
 * En el futuro se podría usar un tipo nativo de PostgreSQL (uuid).
 */
final class UuidType extends Type
{
    /**
     * Nombre del tipo que se usa en los #[Column] de las entidades.
     * Ejemplo: #[Column(type: 'uuid')]
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        // VARCHAR(36): espacio suficiente para un UUID v4.
        // UUID v4 tiene exactamente 36 caracteres: 8-4-4-4-12
        // Ejemplo: "550e8400-e29b-41d4-a716-446655440000"
        return $platform->getStringTypeDeclarationSQL([
            'length' => 36,
        ]);
    }

    /**
     * Nombre del tipo para Doctrine.
     * Se usa en: #[Column(type: 'uuid')]
     */
    public function getName(): string
    {
        return 'uuid';
    }

    /**
     * Convierung PHP → SQL.
     * Doctrine llama a este método antes de insertar/actualizar.
     *
     * @param string $value UUID como string en PHP
     * @param AbstractPlatform $platform Plataforma de BD
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        // Si el valor es null, retornar null (columnas nullable).
        if ($value === null) {
            return null;
        }

        // Retornar el string tal cual (ya es un UUID válido).
        // Doctrine valida que no esté vacío.
        return (string) $value;
    }

    /**
     * Convierung SQL → PHP.
     * Doctrine llama a este método al leer de la base de datos.
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Indica que este tipo es comparable.
     * Doctrine puede comparar valores de este tipo en queries DQL.
     */
    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}

```

## src/Infrastructure/Repository/BilleteraRepository.php

```php
<?php
// src/Infrastructure/Repository/BilleteraRepository.php — Repositorio de Billetera
//
# REPOSITORY: Capa de persistencia que abstrae la base de datos.
# El dominio NO sabe CÓMO se guardan los datos.
# El Repository es el "translator" entre el dominio y la BD.
#
# Patrón Repository:
#   - El dominio define la INTERFACE (qué operaciones de consulta necesitamos)
#   - La infraestructura define la IMPLEMENTACIÓN (cómo se ejecutan en PostgreSQL)
#   - El controller usa la interface (no conoce la implementación)
#
# Esto es Inversión de Dependencias: el dominio no depende de la BD,
# la BD depende del dominio.

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Billetera;
use App\Domain\Movimiento;
use App\Domain\Moneda;
use App\Domain\Dinero;
use App\Domain\TipoMovimiento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use DateTimeImmutable;

/**
 * Repositorio de Billetera con Doctrine ORM.
 *
 * Hereda de ServiceEntityRepository que provee:
 *   - find(): buscar por ID
 *   - findAll(): buscar todos
 *   - findBy(): buscar con criterios
 *   - persist(): guardar entidad nueva
 *   - flush(): persistir cambios a la BD
 *
 * @extends ServiceEntityRepository<Billetera>
 */
final class BilleteraRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry Registry de Doctrine (container de servicios)
     */
    public function __construct(ManagerRegistry $registry)
    {
        // parent::__construct: inicializa el repositorio con la entity class.
        // Doctrine sabe qué tabla usar basándose en la clase.
        parent::__construct($registry, Billetera::class);
    }

    /**
     * Guarda una billetera nueva en la base de datos.
     *
     * Doctrine workflow:
     *   1. persist(): marca la entidad para persistir (no toca la BD aún)
     *   2. flush(): ejecuta las queries pendientes (INSERT/UPDATE/DELETE)
     *
     * ¿Por qué separar persist y flush?
     *   - Puedes hacer múltiples persist() y un solo flush() (batch)
     *   - El flush es el "commit" de Doctrine
     *   - Si algo falla antes del flush, no se guardó nada
     *
     * @param Billetera $billetera La billetera a guardar
     */
    public function save(Billetera $billetera): void
    {
        // Obtener el EntityManager (el "manager" de Doctrine).
        // El EntityManager coordina todas las operaciones de persistencia.
        $entityManager = $this->getEntityManager();

        // persist(): marca la entidad para persistir.
        // Doctrine calcula automáticamente si es INSERT o UPDATE
        // basándose en si la entidad tiene ID o no.
        $entityManager->persist($billetera);

        // flush(): ejecuta las queries SQL pendientes.
        // Esto SÍ toca la base de datos.
        // Si la billetera tiene movimientos nuevos, Doctrine los guarda también
        // (cascade persist).
        $entityManager->flush();
    }

    /**
     * Busca una billetera por su ID.
     *
     * @param string $id ID de la billetera (UUID)
     * @return Billetera|null La billetera encontrada, o null si no existe
     */
    public function findById(string $id): ?Billetera
    {
        // find(): método genérico de Doctrine.
        // Primer parámetro: ID de la entidad.
        // Doctrine ejecuta: SELECT * FROM billetera WHERE id = ?
        // El resultado se hidrata automáticamente en una instancia de Billetera.
        return $this->find($id);
    }

    /**
     * Busca todas las billeteras de un usuario.
     *
     * @param string $usuarioId ID del usuario propietario
     * @return Billetera[] Array de billeteras del usuario
     */
    public function findByUsuario(string $usuarioId): array
    {
        // findBy(): busca con un criterio simple.
        // Doctrine ejecuta: SELECT * FROM billetera WHERE usuario_id = ?
        // El resultado es un array de entidades hidratadas.
        return $this->findBy(
            criteria: ['usuarioId' => $usuarioId],
            orderBy: ['creadoEn' => 'DESC'],
        );
    }

    /**
     * Cuenta el total de billeteras en el sistema.
     *
     * @return int Número total de billeteras
     */
    public function countAll(): int
    {
        // createQueryBuilder(): crea un query builder DQL.
        // DQL es como SQL pero usa nombres de entities, no tablas.
        //
        // select('COUNT(b.id)'): cuenta el número de IDs (ignora NULLs).
        // from(): desde qué entity (no tabla).
        // getSingleScalarResult(): retorna UN solo valor (el count).
        $queryBuilder = $this->createQueryBuilder('b');

        return (int) $queryBuilder
            ->select('COUNT(b.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}

```

## tests/Functional/BilleteraControllerTest.php

```php
<?php
// tests/Functional/BilleteraControllerTest.php — Tests funcionales de la API
//
# TEST FUNCIONAL: Ejecuta la aplicación COMPLETA (HTTP → Controller → Service → DB → Response).
# Simula peticiones HTTP reales y verifica la respuesta.
#
# ¿Qué lo diferencia de un test de integración?
#   - Test de integración: llama al servicio directamente (sin HTTP)
#   - Test funcional: simula una petición HTTP completa
#
# ¿Qué lo diferencia de un test E2E?
#   - Test E2E: usa un navegador real (Playwright, Selenium)
#   - Test funcional: usa WebTestCase de Symfony (sin navegador)
#
# En Symfony, usamos WebTestCase que:
#   1. Crea el kernel de la aplicación
#   2. Crea un cliente HTTP (simula requests)
#   3. Ejecuta la petición y retorna la respuesta
#   4. Nos permite asertar sobre la respuesta (status, headers, body)

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests funcionales del BilleteraController.
 *
 * Cada test simula una petición HTTP y verifica:
#   1. Código de estado HTTP (200, 201, 400, 404, etc.)
#   2. Headers de respuesta
#   3. Contenido del body JSON
#   4. Efectos secundarios (datos en la BD)
 */
final class BilleteraControllerTest extends WebTestCase
{
    /**
     * Cliente HTTP de Symfony.
     * Simula un navegador/cliente API sin abrir un socket real.
     * Se crea UNA vez por test (setUp) y se limpia después (tearDown).
     */
    private KernelBrowser $client;

    /**
     * setUp: se ejecuta ANTES de CADA test.
     * Aquí preparamos el entorno de testing.
     *
     * Cada test tiene su propia instancia del cliente.
     * Esto garantiza aislamiento: un test no afecta a otro.
     */
    protected function setUp(): void
    {
        // createClient(): crea un cliente HTTP de testing.
        // Este cliente NO abre un socket real (no necesita servidor web).
        // Ejecuta el kernel directamente en memoria.
        $this->client = static::createClient();
    }

    /**
     * Test: GET /salud retorna estado "ok".
     *
     * Este es el test más básico: verificar que el health check funciona.
     * Si este test falla, hay un problema fundamental con el servidor.
     */
    public function test_salud_returns_ok(): void
    {
        // request(): simula una petición HTTP.
        // Primer parámetro: método HTTP (GET, POST, PUT, DELETE)
        // Segundo parámetro: URI (la ruta del endpoint)
        $this->client->request('GET', '/salud');

        // Fancybox client->getResponse(): obtiene la respuesta HTTP.
        $response = $this->client->getResponse();

        // assertResponseStatusCodeSame(): verifica el código de status.
        // 200 OK: la petición fue exitosa.
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        // assertResponseHeaderSame(): verifica un header específico.
        // Content-Type debe ser application/json.
        $this->assertResponseHeaderSame(
            'content-type',
            'application/json'
        );

        // getResponseContent(): retorna el body como string.
        // json_decode(): convierte el string JSON a un array PHP.
        $contenido = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // assertArrayHasKey(): verifica que el array tenga cierta clave.
        $this->assertArrayHasKey('estado', $contenido);
        // assertEquals(): compara que el valor sea exactamente el esperado.
        $this->assertEquals('ok', $contenido['estado']);
    }

    /**
     * Test: POST /api/v1/billeteras crea una billetera nueva.
     *
     * Este test verifica el flujo completo:
     *   1. Enviar petición POST con datos
     *   2. Recibir 201 Created
     *   3. Verificar que el ID se generó
     *   4. Verificar que el saldo es 0
     */
    public function test_crear_billetera(): void
    {
        // Datos de la petición.
        // En Symfony, pasar un array como 3er argumento de request()
        // lo convierte automáticamente a JSON y agrega Content-Type header.
        $datos = [
            'usuario_id' => 'user_test_001',
            'moneda' => 'MXN',
        ];

        // POST request con JSON body
        $this->client->request(
            method: 'POST',
            uri: '/api/v1/billeteras',
            // parameters: datos del body (Symfony los serializa a JSON)
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: json_encode($datos, JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        // Verificar 201 Created
        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Verificar header Location
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $contenido = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Verificar que se generó un ID
        $this->assertArrayHasKey('id', $contenido);
        $this->assertNotEmpty($contenido['id']);

        // Verificar que el saldo es "0.00"
        $this->assertEquals('0.00', $contenido['saldo']);

        // Verificar que la moneda es MXN
        $this->assertEquals('MXN', $contenido['moneda']);

        // Verificar que el usuario_id coincide
        $this->assertEquals('user_test_001', $contenido['usuario_id']);
    }

    /**
     * Test: POST /api/v1/billeteras sin campos requeridos retorna 400.
     *
     * Verifica que la validación funcione correctamente.
     */
    public function test_crear_billetera_campos_faltantes(): void
    {
        // Enviar petición SIN campos requeridos
        $this->client->request(
            method: 'POST',
            uri: '/api/v1/billeteras',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            // Body vacío: no hay campos requeridos
            content: json_encode([], JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        // Verificar 400 Bad Request
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $contenido = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Verificar que la respuesta tiene error con código
        $this->assertArrayHasKey('error', $contenido);
        $this->assertEquals('CAMPOS_REQUERIDOS', $contenido['error']['codigo']);
    }

    /**
     * Test: GET /api/v1/billeteras/{id} retorna 404 si no existe.
     */
    public function test_obtener_billetera_no_existe(): void
    {
        // ID inexistente
        $this->client->request('GET', '/api/v1/billeteras/id_inexistente');

        $response = $this->client->getResponse();

        // Verificar 404 Not Found
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}

```

## tests/Integration/Repository/BilleteraRepositoryTest.php

```php
<?php
// tests/Integration/Repository/BilleteraRepositoryTest.php — Tests de integración
//
# TEST DE INTEGRACIÓN: Verifica que el Repository funcione con la BD real.
# A diferencia de los tests unitarios, estos tests:
#   1. Conectan a una base de datos real (SQLite en testing)
#   2. Ejecutan queries SQL reales
#   3. Persisten y recuperan datos
#   4. Verifican que el ORM funcione correctamente
#
# ¿Por qué SQLite para testing y no PostgreSQL?
#   - SQLite es IN-MEMORY: no necesita servidor, es ultrarrápido
#   - Cada test crea una BD nueva: aislamiento total
#   - La sintaxis SQL es casi idéntica para operaciones básicas
#   - Para tests de integración, la velocidad es más importante que
#     la compatibilidad exacta de PostgreSQL

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Infrastructure\Repository\BilleteraRepository;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Tests de integración del BilleteraRepository.
 *
 * KernelTestCase: crea el kernel completo de Symfony (container, services, etc.)
 * Pero NO abre un servidor web (no necesitamos HTTP).
 */
final class BilleteraRepositoryTest extends KernelTestCase
{
    private ?EntityManager $entityManager = null;
    private ?BilleteraRepository $repository = null;

    /**
     * setUp: se ejecuta ANTES de CADA test.
     *
     * Crea una base de datos SQLite en memoria y configura Doctrine
     * para usarla durante el test.
     */
    protected function setUp(): void
    {
        //bootKernel(): inicia el kernel de Symfony.
        // Esto carga el container de servicios, la configuración, etc.
        $kernel = self::bootKernel();

        // Obtener el EntityManager del container.
        // Doctrine_registry es el servicio que Doctrine provee.
        $this->entityManager = $kernel->getContainer()
            ->get('doctrine')
            ->getManager();

        // Obtener el repositorio del container.
        // Symfony crea automáticamente el repositorio porque
        // está registrado en services.yaml.
        $this->repository = $this->entityManager
            ->getRepository(Billetera::class);

        // Crear las tablas en la BD de testing.
        // SchemaTool genera el SQL desde el mapping de Doctrine.
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()
            ->getAllMetadata();
        $schemaTool->createSchema($metadata);
    }

    /**
     * tearDown: se ejecuta DESPUÉS de CADA test.
     *
     * Limpia el EntityManager para evitar datos entre tests.
     */
    protected function tearDown(): void
    {
        if ($this->entityManager !== null) {
            // close(): cierra el EntityManager y libera recursos.
            $this->entityManager->close();
        }

        // parent::tearDown(): método del padre que limpia el kernel.
        parent::tearDown();
    }

    /**
     * Test: guardar y recuperar una billetera.
     *
     * Flujo:
     *   1. Crear billetera de dominio
     *   2. Guardar con el repository
     *   3. Recuperar por ID
     *   4. Verificar que los datos son idénticos
     */
    public function test_guardar_y_recuperar_billetera(): void
    {
        // ARRANGE: crear billetera de dominio
        $billetera = new Billetera(
            id: 'test-uuid-001',
            usuarioId: 'user_test',
            moneda: Moneda::MXN,
        );

        // ACT: guardar en la base de datos
        $this->repository->save($billetera);

        // Limpiar el EntityManager para forzar lectura desde la BD.
        // Sin esto, Doctrine podría retornar la misma instancia
        // en memoria en lugar de leer de la BD.
        $this->entityManager->clear();

        // Recuperar la billetera por ID
        $recuperada = $this->repository->findById('test-uuid-001');

        // ASSERT: verificar que los datos son correctos
        $this->assertNotNull($recuperada);
        $this->assertEquals('test-uuid-001', $recuperada->id());
        $this->assertEquals('user_test', $recuperada->usuarioId());
        $this->assertEquals(Moneda::MXN, $recuperada->moneda());
        // Balance debe ser 0 (sin movimientos)
        $this->assertEquals(0, $recuperada->balance()->centavos());
    }

    /**
     * Test: guardar billetera con depósito y verificar saldo.
     *
     * Este test verifica que Doctrine persista correctamente
     * los movimientos de la billetera.
     */
    public function test_guardar_billetera_con_deposito(): void
    {
        // Crear billetera
        $billetera = new Billetera(
            id: 'test-uuid-002',
            usuarioId: 'user_test',
            moneda: Moneda::MXN,
        );

        // Realizar un depósito
        $billetera->depositar(
            Dinero::crear('500.00', Moneda::MXN),
            'Test depósito',
        );

        // Guardar
        $this->repository->save($billetera);

        // Limpiar y recuperar
        $this->entityManager->clear();
        $recuperada = $this->repository->findById('test-uuid-002');

        // Verificar que el balance se guardó correctamente
        $this->assertNotNull($recuperada);
        $this->assertEquals(50000, $recuperada->balance()->centavos());
        // Verificar que hay 1 movimiento
        $this->assertEquals(1, $recuperada->totalMovimientos());
    }

    /**
     * Test: contar billeteras.
     */
    public function test_contar_billeteras(): void
    {
        // Guardar 2 billeteras
        $this->repository->save(new Billetera(
            id: 'test-uuid-003',
            usuarioId: 'user_a',
            moneda: Moneda::MXN,
        ));
        $this->repository->save(new Billetera(
            id: 'test-uuid-004',
            usuarioId: 'user_b',
            moneda: Moneda::USD,
        ));

        // Verificar el conteo
        $total = $this->repository->countAll();
        $this->assertEquals(2, $total);
    }

    /**
     * Test: buscar billeteras por usuario.
     */
    public function test_buscar_por_usuario(): void
    {
        // Guardar 2 billeteras del mismo usuario
        $this->repository->save(new Billetera(
            id: 'test-uuid-005',
            usuarioId: 'user_multi',
            moneda: Moneda::MXN,
        ));
        $this->repository->save(new Billetera(
            id: 'test-uuid-006',
            usuarioId: 'user_multi',
            moneda: Moneda::USD,
        ));

        // Buscar por usuario
        $billeteras = $this->repository->findByUsuario('user_multi');

        // Verificar que retorna 2 billeteras
        $this->assertCount(2, $billeteras);
    }

    /**
     * Test: retorna null cuando no existe la billetera.
     */
    public function test_retorna_null_cuando_no_existe(): void
    {
        $resultado = $this->repository->findById('id_inexistente');
        $this->assertNull($resultado);
    }
}

```

