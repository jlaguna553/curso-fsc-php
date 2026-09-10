---
title: "Soluciones — Parte 5 — Observabilidad, K8s y CI/CD"
description: "Código fuente de las soluciones de la Parte 5"
sidebar: {"label":"Soluciones","order":100}
---


> 📁 **8 archivos** de código de referencia.

## docker/php-fpm/Dockerfile.prod

```prod
# docker/php-fpm/Dockerfile.prod — Imagen de PRODUCCIÓN multi-stage
#
# MULTI-STAGE (Lección 5.3): dos builds en un mismo Dockerfile.
#
#   Stage 1 "builder":  tiene composer → instala vendor/ → SE DESCARTAN
#   Stage 2 "runtime":  NO tiene composer ni tooling → solo copia
#                       vendor/ ya resuelto + el código fuente.
#
# Resultado: imagen ~130MB (vs ~500MB de la de desarrollo), sin
# superficie de ataque (no hay git, ni xdebug, ni shell extra).
#
# Construir:
#   docker build -f docker/php-fpm/Dockerfile.prod -t wallet-service:prod .

# ══════════════════════════════════════════════════════════════════
# STAGE 1 — BUILDER: aquí vive composer (se descarta al final)
# ══════════════════════════════════════════════════════════════════
FROM composer:2 AS builder
WORKDIR /app

# Copiamos SOLO los manifiestos primero: Docker cachea esta capa, así
# composer install solo se re-ejecuta cuando cambian las dependencias.
COPY composer.json composer.lock* ./

# --no-dev:      producción NO lleva dev-dependencies (phpunit, behat…)
# --no-scripts:  evita disparar comandos del proyecto antes de tener src/
# --prefer-dist: descarga archivos comprimidos (más rápido que git)
# --no-progress: salida limpia en CI
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-scripts \
    --no-progress

# ══════════════════════════════════════════════════════════════════
# STAGE 2 — RUNTIME: PHP-FPM Alpine, SOLO lo necesario
# ══════════════════════════════════════════════════════════════════
FROM php:8.3-fpm-alpine AS runtime
WORKDIR /app

# Instalamos SOLO las extensiones que el runtime necesita:
#   pdo_pgsql     → PostgreSQL (Parte 2)
#   opcache       → caché de bytecode (rendimiento)
#   pcntl         → control de procesos (workers de mensajería)
#   sockets       → conexiones AMQP a RabbitMQ (Parte 3)
RUN docker-php-ext-install pdo_pgsql opcache pcntl sockets

# Configuración opcache de PRODUCCIÓN (no la de desarrollo):
#   validate_timestamps=0 → no re-chequear archivos (¡más rápido!)
#   opcache.max_accelerated_files=20000 → sobra para nuestra app
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Copiamos las dependencias YA resueltas desde el stage builder
COPY --from=builder /app/vendor /app/vendor

# Copiamos el código fuente de la aplicación
COPY config/ config/
COPY src/ src/
COPY migrations/ migrations/
COPY public/ public/

# php-fpm corre en foreground (-F) y escucha en el puerto 9000
EXPOSE 9000
CMD ["php-fpm", "-F"]
```

## k8s/configmap.yaml

```yaml
# k8s/configmap.yaml — Config NO sensible del servicio
#
# CONFIGMAP: separa la configuración del código (Doce-Factor Apps).
# Aquí va TODO lo que no es secreto: URLs, flags, defaults.
#
# Verlo aplicado:
#   kubectl get cm wallet-config -n prestaflow -o yaml
#
# Aplicar:
#   kubectl apply -f k8s/configmap.yaml -n prestaflow
apiVersion: v1
kind: ConfigMap
metadata:
  name: wallet-config
  namespace: prestaflow
data:
  # Entorno de ejecución
  APP_ENV: prod
  # BD: "postgres" es el DNS del servicio postgres DENTRO del clúster
  DATABASE_URL: postgresql://prestaflow:prestaflow_segura@postgres:5432/prestaflow
  # Broker: "rabbitmq" es el DNS del servicio de mensajería
  RABBITMQ_URL: amqp://prestaflow:prestaflow_segura@rabbitmq:5672
  # Default de negocio del dominio
  MONEDA_DEFAULT: MXN
```

## k8s/deployment.yaml

```yaml
# k8s/deployment.yaml — Estado deseado del servicio de billeteras
#
# DEPLOYMENT: declara CÓMO debe verse el servicio: imagen, réplicas,
# recursos, healthchecks. Kubernetes converge el clúster a este estado.
#
# Puntos clave (Lección 5.4):
#   - readinessProbe: no manda tráfico al pod hasta que responde /api/salud
#   - livenessProbe:  si el pod se cuelga, K8s lo reinicia
#   - resources:      SIN recursos el HPA no puede escalar y un pod
#                     puede comerse todo el nodo
#   - envFrom:        la config NO sensible viene del ConfigMap
#   - secretKeyRef:   APP_SECRET viene del Secret creado con kubectl
#
# Aplicar:
#   kubectl apply -f k8s/deployment.yaml -n prestaflow
apiVersion: apps/v1
kind: Deployment
metadata:
  name: wallet-service
  namespace: prestaflow
  labels:
    app: wallet-service
spec:
  # Réplicas MÍNIMAS. El HPA (hpa.yaml) escala de 2 a 10 según CPU.
  replicas: 2
  selector:
    matchLabels:
      app: wallet-service
  template:
    metadata:
      labels:
        app: wallet-service
    spec:
      containers:
        - name: wallet
          image: wallet-service:prod
          # Local: no volver a bajar la imagen si ya está en el nodo.
          # En CI/CD real se usa Always (siempre la última de GHCR).
          imagePullPolicy: IfNotPresent
          ports:
            - containerPort: 9000   # puerto interno de php-fpm
          env:
            # Secret → se inyecta SOLO cuando existe (kubectl create secret)
            - name: APP_SECRET
              valueFrom:
                secretKeyRef:
                  name: wallet-secrets
                  key: APP_SECRET
          # Config no sensible → viene del ConfigMap completo
          envFrom:
            - configMapRef:
                name: wallet-config
          # Límites de recursos: obligatorios para que el HPA funcione
          resources:
            requests:
              cpu: 100m
              memory: 128Mi
            limits:
              cpu: 500m
              memory: 256Mi
          # Healthchecks sobre el endpoint de salud (Parte 1)
          readinessProbe:
            httpGet:
              path: /api/salud
              port: 9000
            initialDelaySeconds: 3
            periodSeconds: 5
          livenessProbe:
            httpGet:
              path: /api/salud
              port: 9000
            initialDelaySeconds: 10
            periodSeconds: 15
```

## k8s/hpa.yaml

```yaml
# k8s/hpa.yaml — Autoescalado horizontal
#
# HPA (HorizontalPodAutoscaler): observa las métricas de los pods y
# ajusta el número de réplicas entre min y max.
#
#   CPU > 70% promedio → escala HACIA ARRIBA (hasta 10)
#   CPU < 70% sostenido → escala HACIA ABAJO (mínimo 2)
#
# REQUISITO: el Deployment DEBE tener resources (lo tiene en
# deployment.yaml) — sin metrics-server o sin requests el HPA no escala.
#
# Probar el escalado (terminal 2):
#   kubectl run load --image=busybox --rm -it -n prestaflow \
#     -- sh -c "while true; do wget -q -O- http://wallet-service/api/salud; done"
#
# Aplicar:
#   kubectl apply -f k8s/hpa.yaml -n prestaflow
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: wallet-service
  namespace: prestaflow
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: wallet-service
  minReplicas: 2
  maxReplicas: 10
  metrics:
    - type: Resource
      resource:
        name: cpu
        target:
          type: Utilization
          averageUtilization: 70
```

## k8s/namespace.yaml

```yaml
# k8s/namespace.yaml — Aislamiento del ambiente PrestaFlow
#
# NAMESPACE: divide un clúster Kubernetes en ambientes lógicos.
# Todo lo de PrestaFlow vive en "prestaflow": deployments, services,
# configmaps, secrets… sin mezclarse con otras apps del clúster.
#
# Aplicar:
#   kubectl apply -f k8s/namespace.yaml
apiVersion: v1
kind: Namespace
metadata:
  name: prestaflow
  labels:
    app: prestaflow
    environment: production
```

## k8s/service.yaml

```yaml
# k8s/service.yaml — Ruta estable hacia los pods
#
# SERVICE: da una IP/DNS estable a un conjunto de pods que cambian
# (los pods mueren y renacen; el Service no cambia).
#
#   Pod A (10.0.0.1:9000) ─┐
#   Pod B (10.0.0.2:9000) ─┤→ Service wallet-service:80 → balancea
#   Pod C (10.0.0.3:9000) ─┘
#
# Otros servicios del clúster lo llaman por DNS: http://wallet-service
#
# Aplicar:
#   kubectl apply -f k8s/service.yaml -n prestaflow
apiVersion: v1
kind: Service
metadata:
  name: wallet-service
  namespace: prestaflow
spec:
  # Selector → el Service enruta SOLO a los pods con este label
  # (los mismos del Deployment).
  selector:
    app: wallet-service
  ports:
    - name: http
      port: 80            # puerto que escuchan los demás servicios
      targetPort: 9000    # puerto real del contenedor (php-fpm)
```

## src/Infrastructure/Tracing/TraceableCommandBus.php

```php
<?php
// src/Infrastructure/Tracing/TraceableCommandBus.php — Decorador del bus con Datadog
//
# PROBLEMA (Lección 5.2): si cada handler abre su propio span, el trace
# queda lleno de spans "deposito.procesar", "retiro.procesar", ... sin
# una vista común. Además duplicamos el boilerplate.
#
# SOLUCIÓN: un DECORADOR alrededor del bus. Cualquier comando que pase
# por él queda envuelto en UN span con el nombre real de la clase.
#
#   CommandBus real (HandleTrait) ←─ TraceableCommandBus (este archivo)
#        ↑                                   ↑
#   lo inyecta cualquiera           los controllers inyectan ESTE
#
# El decorador compone el bus real (no lo extiende) y añade el span:
#   - resource:  FQCN del comando (ej: RealizarDepositoCommand)
#   - meta:      comando, billetera, usuario (si el mensaje los expone)
#   - resultado: exito | error
#
# La extensión ddtrace es opcional en runtime: si DD_TRACE_ENABLED=false
# las llamadas DD\trace_start_span() son no-op → los tests unitarios
# (que no tienen la extensión) siguen funcionando.
#
# Uso (services.yaml):
#   App\Infrastructure\Bus\CommandBus:
#       class: App\Infrastructure\Tracing\TraceableCommandBus
#       arguments: [ '@App\Infrastructure\Bus\CommandBus.inner' ]
#   (o con decoración de bus mediante named autowiring)

declare(strict_types=1);

namespace App\Infrastructure\Tracing;

use App\Infrastructure\Bus\CommandBus;

/**
 * Envuelve el command bus con un span Datadog por operación.
 *
 * Uso:
 *   $resultado = $traceableBus->dispatch(new RealizarDepositoCommand(...));
 */
final class TraceableCommandBus
{
    public function __construct(private readonly CommandBus $inner)
    {
    }

    /**
     * Despacha el comando dentro de un span Datadog.
     *
     * @param object $comando El comando a ejecutar
     *
     * @return mixed Resultado del handler
     */
    public function dispatch(object $comando): mixed
    {
        $span = DD\trace_start_span();
        $span->name = 'bus.dispatch.command';
        $span->resource = $comando::class;
        $span->meta['comando'] = $comando::class;

        // Si el comando expone billeteraId()/usuarioId(), los aterrizamos
        // en el span para filtrar traces por entidad.
        if (method_exists($comando, 'billeteraId')) {
            $span->meta['billetera'] = (string) $comando->billeteraId();
        }
        if (method_exists($comando, 'usuarioId')) {
            $span->meta['usuario'] = (string) $comando->usuarioId();
        }

        try {
            $resultado = $this->inner->dispatch($comando);
            $span->meta['resultado'] = 'exito';

            return $resultado;
        } catch (\Throwable $e) {
            $span->meta['resultado'] = 'error';
            $span->meta['exception'] = $e->getMessage();
            throw $e;
        } finally {
            // finally: el span se cierra SIEMPRE, incluso al relanzar
            DD\trace_close_span();
        }
    }
}
```

## src/Infrastructure/Tracing/TraceableQueryBus.php

```php
<?php
// src/Infrastructure/Tracing/TraceableQueryBus.php — Decorador del query bus
//
# Misma idea que TraceableCommandBus pero para LECTURAS.
# Separar command/query traceables mantiene el patrón CQRS explícito:
#   - ¿Cambio estado?  → TraceableCommandBus
#   - ¿Leo datos?      → TraceableQueryBus
#
# resource = FQCN de la query (ej: ObtenerBilleteraQuery)
# → puedes filtrar en Datadog: resource:ObtenerBilleteraQuery

declare(strict_types=1);

namespace App\Infrastructure\Tracing;

use App\Infrastructure\Bus\QueryBus;

/**
 * Envuelve el query bus con un span Datadog por lectura.
 *
 * Uso:
 *   $snapshot = $traceableBus->ask(new ObtenerBilleteraQuery($id));
 */
final class TraceableQueryBus
{
    public function __construct(private readonly QueryBus $inner)
    {
    }

    /**
     * Ejecuta la query dentro de un span Datadog.
     *
     * @param object $query La query de lectura
     *
     * @return mixed Snapshot de datos
     */
    public function ask(object $query): mixed
    {
        $span = DD\trace_start_span();
        $span->name = 'bus.dispatch.query';
        $span->resource = $query::class;
        $span->meta['query'] = $query::class;

        if (method_exists($query, 'billeteraId')) {
            $span->meta['billetera'] = (string) $query->billeteraId();
        }

        try {
            $resultado = $this->inner->ask($query);
            $span->meta['resultado'] = 'exito';

            return $resultado;
        } catch (\Throwable $e) {
            $span->meta['resultado'] = 'error';
            $span->meta['exception'] = $e->getMessage();
            throw $e;
        } finally {
            DD\trace_close_span();
        }
    }
}
```

