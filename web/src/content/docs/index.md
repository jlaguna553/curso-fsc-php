---
title: 'FSC-PHP: Microservicios Fintech con PHP 8.3 y Symfony 7'
description: 'Curso completo de 0 a producción para construir ecosistemas de microservicios fintech enfocados en procesamiento de transacciones y créditos.'
sidebar:
  label: Inicio
  order: 0
---

<div class="hero-section">
  <h1>🚀 FSC-PHP · PrestaFlow</h1>
  <p class="tagline">Curso completo de PHP 8.3 + Symfony 7.4. De 0 a producción: Docker, DDD, CQRS, Event Sourcing, RabbitMQ, testing profesional, Kubernetes y CI/CD.</p>
  <p><strong>6 partes</strong> · <strong>42 ejercicios</strong> · <strong>292 puntos</strong></p>
</div>

## Plataforma PrestaFlow

A lo largo de este curso construimos **PrestaFlow**, una plataforma de pagos y
préstamos compuesta por múltiples microservicios fintech. Cada parte añade
capas de complejidad real sobre el mismo proyecto.

```mermaid
graph TD
    APi["API Gateway<br/>Nginx"] --> WS["wallet-service<br/>PHP 8.3 / Symfony 7"]
    WS --> LS["loan-service<br/>PHP 8.3"]
    WS --> PG1[("PostgreSQL<br/>wallet_db")]
    LS --> PG2[("PostgreSQL<br/>loan_db")]
    WS --- RMQ["RabbitMQ Event Bus<br/>transactions · loans · notifications"]
    RMQ --- LS
    WS --- DD["Datadog APM<br/>Traces · Metrics · Logs"]
    LS --- DD
    style WS fill:#dbeafe,stroke:#1a56db,stroke-width:2px
    style APi fill:#f0fdf4,stroke:#16a34a,stroke-width:2px
    style LS fill:#fef3c7,stroke:#d97706,stroke-width:2px
    style RMQ fill:#fce7f3,stroke:#db2777,stroke-width:2px
    style DD fill:#ede9fe,stroke:#7c3aed,stroke-width:2px
```

## Stack tecnológico

| Componente | Tecnología | Versión |
|---|---|---|
| Runtime | PHP | 8.3+ |
| Framework | Symfony | 7.4 LTS |
| Base de datos | PostgreSQL | 16 |
| Mensajería | RabbitMQ | 3.13 |
| Testing | PHPUnit 11 + Behat + Infection | — |
| Containers | Docker + Compose | 24+ |
| Orquestación | Kubernetes (Kind) | 1.30+ |
| CI/CD | GitHub Actions | — |
| Observabilidad | Datadog APM | Agent 7.x |

---

## El recorrido del curso

<div class="part-card">
  <h3><a href="/parte0/">Parte 0 — Infraestructura Base y Entorno de Desarrollo</a> <span class="badge">27 pts</span></h3>
  <p>Docker, Docker Compose, estructura del monorepo, Datadog APM local, el ciclo HTTP completo desde curl hasta PHP.</p>
  <p><em>Resultado: un entorno verificado y una comprensión sólida de HTTP/JSON aplicada al dominio fintech.</em></p>
  <p><a href="/parte0/">Ver ejercicios →</a></p>
</div>

<div class="part-card">
  <h3><a href="/parte1/">Parte 1 — PHP 8.3 Moderno, POO y DDD</a> <span class="badge">57 pts</span></h3>
  <p>Enums, Value Objects inmutables (Dinero, Moneda), dominio rico, SOLID aplicado, tests unitarios de dominio puro.</p>
  <p><em>Resultado: un kernel de dominio con Billetera, Dinero y Movimiento — sin framework, sin BD.</em></p>
  <p><a href="/parte1/">Ver ejercicios →</a></p>
</div>

<div class="part-card">
  <h3><a href="/parte2/">Parte 2 — Symfony y Arquitectura Hexagonal</a> <span class="badge">52 pts</span></h3>
  <p>Symfony 7.4, Doctrine ORM, migraciones, API REST, servicios de aplicación, arquitectura hexagonal, tests de integración.</p>
  <p><em>Resultado: una API REST de billeteras con CRUD, persistencia y tests funcional/integración.</em></p>
  <p><a href="/parte2/">Ver ejercicios →</a></p>
</div>

<div class="part-card">
  <h3><a href="/parte3/">Parte 3 — CQRS y Event-Driven Architecture</a> <span class="badge">52 pts</span></h3>
  <p>Command/Query bus, handlers, RabbitMQ, topología de colas, eventos, idempotencia, loan-service separado.</p>
  <p><em>Resultado: comunicación async entre wallet y loan-service con eventos y msg idempotencia.</em></p>
  <p><a href="/parte3/">Ver ejercicios →</a></p>
</div>

<div class="part-card">
  <h3><a href="/parte4/">Parte 4 — Testing Profesional</a> <span class="badge">52 pts</span></h3>
  <p>Pirámide de testing, PHPUnit 11 (data providers, mocks), Behat + Gherkin en español, cobertura PCOV, Infection (mutational testing).</p>
  <p><em>Resultado: suite completa con tests unitarios, BDD, cobertura 100% en dominio y MSI 85%.</em></p>
  <p><a href="/parte4/">Ver ejercicios →</a></p>
</div>

<div class="part-card">
  <h3><a href="/parte5/">Parte 5 — Observabilidad, Kubernetes y CI/CD</a> <span class="badge">52 pts</span></h3>
  <p>Logs estructurados, Datadog APM spans personalizados, Docker multi-stage, Kubernetes (Deployment, Service, ConfigMap, HPA), GitHub Actions CI/CD.</p>
  <p><em>Resultado: PrestaFlow desplegado y autoescalable, con pipeline automática.</em></p>
  <p><a href="/parte5/">Ver ejercicios →</a></p>
</div>

---

## Stack visual del curso

```mermaid
graph LR
    subgraph "Parte 0-1: Fundamentos"
        A[Docker] --> B[PHP 8.3]
        B --> C[DDD]
    end
    subgraph "Parte 2-3: Aplicación"
        C --> D[Symfony 7]
        D --> E[CQRS]
        E --> F[RabbitMQ]
    end
    subgraph "Parte 4-5: Producción"
        F --> G[Testing]
        G --> H[K8s + CI/CD]
    end
    style A fill:#dbeafe,stroke:#1a56db
    style C fill:#f0fdf4,stroke:#16a34a
    style F fill:#fef3c7,stroke:#d97706
    style H fill:#fce7f3,stroke:#db2777
```