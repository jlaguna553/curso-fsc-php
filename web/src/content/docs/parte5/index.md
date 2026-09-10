---
title: "Parte 5 — Observabilidad, Kubernetes y CI/CD"
description: "En esta parte lleva PrestaFlow a producción."
sidebar: {"order":5}
---


> **En esta parte lleva PrestaFlow a producción.**
> Aprendes la trifecta de la observabilidad (logs, métricas y trazas) con
> Datadog APM, creas spans personalizados en tus manejadores CQRS, empaquetas
> el servicio en una imagen de producción multi-stage, lo despliegas en
> Kubernetes (Deployment, Service, ConfigMap, Secret, HPA) y automatizas
> TODO con GitHub Actions: desde el push hasta el deploy en el clúster.

---

## Contenido

| Lección | Tema | Herramienta |
|---|---|---|
| 5.1 | Trifecta de la observabilidad | Logs, métricas, trazas |
| 5.2 | Datadog APM y spans personalizados | ddtrace, DDTrace\Span |
| 5.3 | Imagen de producción multi-stage | Docker |
| 5.4 | Kubernetes: Despliegue en clúster | Kind, kubectl |
| 5.5 | CI/CD automático | GitHub Actions |

**Ejercicios 5.1 – 5.8 · Puntos: 52 · Total acumulado: 292**

---

## 5.1 La trifecta de la observabilidad

### ¿Por qué observabilidad?

Tu código funciona en tu máquina… y en producción es OTRA cosa.
Cuando un depósito falla a las 3am con RabbitMQ caído, necesitas
responder **tres preguntas**:

```mermaid
graph TD
    subgraph K8s["Clúster Kind (nodo único local)"]
        NS["Namespace: prestaflow"]
        subgraph Pods["Pods"]
            P1["Pod wallet-service A"]
            P2["Pod wallet-service B"]
        end
        SVC["Service\nwallet-service:80 → :9000"]
        CM["ConfigMap\nAPP_ENV, DATABASE_URL"]
        SEC["Secret\nAPP_SECRET"]
        HPA["HPA\nmin:2 max:10\nCPU > 70%"]
    end
    NS --> Pods
    SVC --> Pods
    HPA --> Pods
    style NS fill:#f0fdf4,stroke:#16a34a
    style SVC fill:#dbeafe,stroke:#1a56db
    style HPA fill:#fef3c7,stroke:#d97706
```mermaid
graph LR
    subgraph CI["CI — Calidad"]
        LINT["php-lint"]
        TEST["PHPUnit + Behat"]
        STAN["PHPStan"]
        CS["CS-Fixer"]
    end
    subgraph CD["CD — Entrega"]
        BUILD["docker build\nmulti-stage"]
        PUSH["push a GHCR"]
        DEPLOY["kubectl apply\nrollout K8s"]
    end
    LINT --> TEST
    TEST --> STAN
    STAN --> CS
    CS --> BUILD
    BUILD --> PUSH
    PUSH --> DEPLOY
    style CI fill:#dbeafe,stroke:#1a56db
    style CD fill:#f0fdf4,stroke:#16a34a
```

### `ci.yml` (9 líneas de esencia)

```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres: {'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'} image: postgres:16, env: {'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'} POSTGRES_PASSWORD: test {'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'} {'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'}
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: {'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'} php-version: '8.3', tools: composer {'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'}
      - run: composer install --no-interaction
      - run: make test
```

### `cd.yml` (el deploy)

```yaml
on:
  push:
    branches: [ main ]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: docker build -t ghcr.io/${'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'}{'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'} github.repository {'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'}{'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'}/wallet-service .
      - run: docker push ghcr.io/${'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'}{'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'} github.repository {'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'}{'{'}'{'{'}'{'}'}'{'{'}'{'}'}'{'}'}'{'{'}'{'}'}'{'}'}/wallet-service
      - uses: azure/setup-kubectl@v4
      - run: kubectl set image deployment/wallet-service ...
```

> El deploy a Kind local desde Actions es posible con `kind` +
> `kubectl` remoto. En una empresa real el CD apunta a AWS/GCP/Azure;
> para el curso, GitHub Actions dispara la actualización del clúster
> Kind que ya corre en tu máquina (patrón "GitOps-lite").

---

## Autoevaluación

Responde antes de ver las soluciones:

1. ¿Qué tres preguntas responde la trifecta de observabilidad?
2. ¿Por qué los logs deben ser estructurados (clave-valor)?
3. ¿Qué tags aterrizarías en el span de `RealizarDepositoCommandHandler`?
4. ¿Por qué se usan stages separados `builder` y `runtime` en la imagen?
5. ¿Qué hace el HPA y por qué exige `resources` en el Deployment?
6. ¿Cuál es la diferencia de responsabilidad entre `ci.yml` y `cd.yml`?

---

## Ejercicios 5.1 – 5.8 (52 pts)

| Ejercicio | Tema | Pts |
|---|---|---|
| 5.1 | Logs estructurados en el CommandHandler | 5 |
| 5.2 | Span personalizado Datadog en handlers | 8 |
| 5.3 | Decorador de bus con tracing | 9 |
| 5.4 | Dockerfile multi-stage de producción | 6 |
| 5.5 | Manifiestos K8s (Namespace + Deployment + Service) | 8 |
| 5.6 | ConfigMap, Secret y HPA | 6 |
| 5.7 | Pipeline CI con GitHub Actions | 5 |
| 5.8 | Pipeline CD: build + push + deploy | 5 |
| **Total** | | **52** |

Cada enunciado está en `ejercicios/` con su verificación y puntuación.
Las soluciones aplican **encima de las de la Parte 4**.