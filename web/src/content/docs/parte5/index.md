---
title: "Parte 5 — Observabilidad, Kubernetes y CI/CD"
description: "Parte 5 — Observabilidad, K8s y CI/CD — Curso FSC-PHP PrestaFlow"
sidebar: {"label":"Overview","order":5}
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


## Autoevaluación

Responde antes de ver las soluciones:

1. ¿Qué tres preguntas responde la trifecta de observabilidad?
2. ¿Por qué los logs deben ser estructurados (clave-valor)?
3. ¿Qué tags aterrizarías en el span de `RealizarDepositoCommandHandler`?
4. ¿Por qué se usan stages separados `builder` y `runtime` en la imagen?
5. ¿Qué hace el HPA y por qué exige `resources` en el Deployment?
6. ¿Cuál es la diferencia de responsabilidad entre `ci.yml` y `cd.yml`?

---

