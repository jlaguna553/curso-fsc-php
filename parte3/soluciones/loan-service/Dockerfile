# loan-service/Dockerfile — Imagen del worker asíncrono
#
# Diferencia clave con el Dockerfile del wallet-service:
#   - NO lleva Nginx ni PHP-FPM: es un proceso CLI que corre para siempre
#   - Base: php:8.3-cli-alpine (la versión CLI, no la FPM)
#   - Multi-stage: stage de build (composer) + stage final mínimo

# ---------- STAGE 1: dependencias ----------
FROM composer:2 AS dependencias

WORKDIR /app

# Copiar SOLO composer.json/lock primero para cachear las capas de Composer.
# Si no cambian, Docker reutiliza el cache y el build es instantáneo.
COPY composer.json ./

# Instalar dependencias de producción (sin dev) con optimización de autoload.
RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# ---------- STAGE 2: imagen final ----------
FROM php:8.3-cli-alpine

# Extensión PDO_PGSQL: para hablar con PostgreSQL (cliente nativo).
RUN docker-php-ext-install pdo_pgsql

WORKDIR /app

# Copiar SOLO lo necesario: vendor + código + entrada.
# Nunca copiamos composer.json de nuevo; el runtime no compila.
COPY --from=dependencias /app/vendor ./vendor
COPY bin ./bin
COPY src ./src
COPY config ./config

# El worker es un proceso de larga vida: arranca y consume para siempre.
# ENTRYPOINT en forma exec (no shell) para recibir señales SIGTERM limpias
# (importante para Kubernetes: parada graciosa).
ENTRYPOINT ["php", "bin/consumir.php"]