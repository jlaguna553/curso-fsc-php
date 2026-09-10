# docker/php-fpm/Dockerfile — PHP-FPM 8.3 para PrestaFlow
#
# PHP-FPM (FastCGI Process Manager) es el runtime que ejecuta código PHP
# en producción. A diferencia de `php serve` (desarrollo), FPM:
#   - Gestiona un pool de procesos PHP (múltiples requests en paralelo)
#   - Se comunica con Nginx via FastCGI (protocolo binario, rápido)
#   - Soporta opcache para performance
#   - Permite configurar límites de memoria y tiempo de ejecución
#
# Multi-stage build: usamos 2 etapas para reducir el tamaño de la imagen final.
# Etapa 1 (build): instala dependencias de compilación
# Etapa 2 (runtime): solo lo necesario para ejecutar

# ═══════════════════════════════════════════════════════════════════════════════
# ETAPA 1: Build — Instalación de dependencias y compilación
# ═══════════════════════════════════════════════════════════════════════════════

# FROM define la imagen base. php:8.3-fpm-alpine usa Alpine Linux
# (6MB vs 800MB de Debian). Esto reduce el tamaño de la imagen final.
# -fpm: incluye PHP-FPM (no solo CLI)
# -alpine: usa musl libc en lugar de glibc (más ligero)
FROM php:8.3-fpm-alpine AS build

# LABEL son metadatos de la imagen (como un README embebido).
# docker inspect mostrará estos campos.
LABEL maintainer="PrestaFlow Team"
LABEL description="PHP-FPM 8.3 runtime para wallet-service"

# Instalar dependencias del sistema necesarias para:
#   - Compilar extensiones PHP (gcc, g++, make)
#   - git: para Composer (clonar repos privados)
#   - unzip: para extraer archivos .zip de Composer
#   - libzip-dev: para ext-zip (requerida por Composer)
#   - icu-dev: para ext-intl (formato de fechas internacionales)
#   - libpq-dev: para ext-pgsql (conexión a PostgreSQL)
#   - oniguruma-dev: para ext-mbstring (strings multibyte, requerido por Symfony)
#
# APK es el gestor de paquetes de Alpine (como apt en Debian).
# --no-cache: no guardar el índice de paquetes (reduce tamaño de imagen).
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    icu-dev \
    libpq-dev \
    oniguruma-dev \
    linux-headers \
    && rm -rf /var/cache/apk/*

# Instalar extensiones PHP necesarias.
# docker-php-ext-install es un script de la imagen base que compila
# extensiones sin necesidad de PECL.
RUN docker-php-ext-install \
    opcache \
    intl \
    mbstring \
    zip \
    bcmath \
    pdo \
    pdo_pgsql \
    pgsql

# Instalar extensión apcu (APCu User Cache).
# APCu almacena datos en memoria del servidor (más rápido que Redis/Memcached).
# Symfony lo usa como "metadata cache" para Doctrine.
# docker-php-ext-install no soporta APCu, así que usamos PECL.
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install apcu \
    && docker-php-ext-enable apcu

# Instalar extensión xdebug (solo para desarrollo).
# En producción se deshabilita con XDEBUG_MODE=off.
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

# Configurar OPcache para desarrollo.
# OPcache almacena bytecode compilado en memoria.
# Sin OPcache, PHP recompila cada archivo en cada request (lento).
#
# opcache.enable=1: activar OPcache
# opcache.validate_timestamps=1: recompilar si el archivo cambió (dev)
#   En producción: 0 (nunca recompilar, solo al desplegar)
# opcache.memory_consumption=128: 128MB de memoria para el cache
RUN echo "opcache.enable=1" > /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.validate_timestamps=1" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.memory_consumption=128" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.interned_strings_buffer=16" >> /usr/local/etc/php/conf.d/opcache.ini \
    && echo "opcache.max_accelerated_files=10000" >> /usr/local/etc/php/conf.d/opcache.ini

# Configurar Xdebug para desarrollo remoto.
# xdebug.mode=develop,debug: habilitar desarrollo y debugging
# xdebug.client_host=host.docker.internal: IP del host (tu máquina)
#   Docker Desktop provee este hostname automáticamente.
#   En Linux, usar la IP de la red Docker (172.17.0.1).
# xdebug.client_port=9003: puerto estándar de Xdebug 3
RUN echo "xdebug.mode=develop,debug" > /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.start_with_request=trigger" >> /usr/local/etc/php/conf.d/xdebug.ini \
    && echo "xdebug.discover_client_host=true" >> /usr/local/etc/php/conf.d/xdebug.ini

# Crear directorio de la aplicación dentro del contenedor.
# /app es la convención estándar para el working directory.
WORKDIR /app

# Copiar el instalador de Composer.
# COPY单个文件 es más eficiente que COPY de todo el directorio.
# Si el composer.json no cambia, Docker reutiliza esta capa de caché.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ═══════════════════════════════════════════════════════════════════════════════
# ETAPA 2: Runtime — Solo lo necesario para ejecutar
# ═══════════════════════════════════════════════════════════════════════════════

# Copiar SOLO las extensiones compiladas de la etapa anterior.
# Esto reduce la imagen de ~800MB a ~200MB.
COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Copiar el binario de Composer (solo para ejecutar en runtime si es necesario).
COPY --from=build /usr/bin/composer /usr/bin/composer

# Crear el usuario del contenedor.
# Nunca ejecutar la aplicación como root en producción.
# WWW-DATA es el usuario estándar de PHP-FPM.
RUN addgroup -g 1000 -S appgroup \
    && adduser -u 1000 -S appuser -G appgroup

# Cambiar ownership del directorio de trabajo.
RUN chown -R appuser:appgroup /app

# Copiar el archivo de configuración de PHP-FPM.
# pool.conf define cómo FPM acepta conexiones de Nginx.
COPY php-fpm-pool.conf /usr/local/etc/php-fpm.d/www.conf

# Puerto que PHP-FPM escucha (FastCGI, no HTTP).
# Nginx se conecta a este puerto para enviar requests PHP.
EXPOSE 9000

# El comando que ejecuta el contenedor.
# php-fpm: inicia el proceso manager.
# --nodaemonize: ejecutar en foreground (Docker requiere que el proceso
#   principal nunca termine, o el contenedor se detiene).
# -y: ruta al archivo de configuración.
CMD ["php-fpm", "--nodaemonize", "--fpm-config", "/usr/local/etc/php-fpm.d/www.conf"]
