---
title: "Parte 3 — CQRS, Message Bus y Microservicios Asíncronos con RabbitMQ"
description: "Parte 3 — CQRS y Event-Driven Architecture — Curso FSC-PHP PrestaFlow"
sidebar: {"label":"Overview","order":3}
---

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

