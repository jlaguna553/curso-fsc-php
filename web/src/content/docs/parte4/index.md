---
title: "Parte 4 — Testing Profesional: PHPUnit, Behat y Cobertura"
description: "Parte 4 — Testing Profesional — Curso FSC-PHP PrestaFlow"
sidebar: {"label":"Overview","order":4}
---

> **En esta parte elevas la calidad de tu código a nivel profesional.**
> Aprendes la pirámide de testing, dominas PHPUnit 11 (data providers,
> mocks, fakes, spies), escribes especificaciones ejecutables con Behat
> + Gherkin en español, mides cobertura con PCOV y aplicas mutation
> testing para encontrar los tests que no prueban nada.

---

## Contenido

| Lección | Tema | Herramienta |
|---|---|---|
| 4.1 | La pirámide de testing | Teoría aplicada a PrestaFlow |
| 4.2 | PHPUnit avanzado | Data providers, assertions profundas |
| 4.3 | Test Doubles | Fakes, stubs, mocks, spies |
| 4.4 | Behat + Gherkin | BDD con especificaciones en español |
| 4.5 | Cobertura y Mutational Testing | PCOV, Infection |

**Ejercicios 4.1 – 4.8 · Puntos: 52 · Total acumulado: 240**

---


## Resumen de la Parte 4

| Herramienta | Archivo | Propósito |
|---|---|---|
| Data providers | `tests/Unit/Domain/DineroDataProviderTest.php` | Probar N casos con 1 test |
| Test Doubles | `tests/Unit/Application/DepositoConMocksTest.php` | Stubs, mocks, matchers |
| Gherkin | `features/billetera.feature` | Especificaciones en español |
| FeatureContext | `features/bootstrap/FeatureContext.php` | Pasos Gherkin ↔ buses CQRS |
| behat.yml | `behat.yml` | Configuración del runner |
| Coverage | `phpunit.xml.dist` | HTML + texto con PCOV |


## Comandos de la Parte 4

```bash
# Ejecutar tests unitarios con reporte de cobertura
./vendor/bin/phpunit --testsuite unit --coverage-text

# Generar reporte HTML de cobertura
./vendor/bin/phpunit --testsuite unit --coverage-html coverage

# Ejecutar Behat (especificaciones en español)
./vendor/bin/behat --format=pretty

# Ejecutar Infection (mutational testing)
./vendor/bin/infection --show-mutations
```
