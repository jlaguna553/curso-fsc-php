# FSC-PHP: Microservicios Fintech con PHP 8.3 y Symfony 7

> Curso completo de 0 a 100 para construir ecosistemas de microservicios de producción
> enfocados en dominios de tarjetas de crédito y procesamiento de transacciones.

## Plataforma PrestaFlow

A lo largo de este curso construiremos **PrestaFlow**, una plataforma de pagos y
préstamos compuesta por múltiples microservicios. Cada parte del curso añade
capas de complejidad real sobre el mismo proyecto.

## Stack Tecnológico

| Componente | Tecnología | Versión |
|---|---|---|
| Runtime | PHP | 8.3+ |
| Framework | Symfony | 7.4 LTS |
| Base de datos | PostgreSQL | 16 |
| Mensajería | RabbitMQ | 3.13 |
| Testing | PHPUnit 11 + Behat | — |
| Containers | Docker + Compose | 24+ |
| Orquestación | Kubernetes (Kind) | 1.30+ |
| CI/CD | GitHub Actions | — |
| Observabilidad | Datadog APM | Agent 7.x |

## Estructura del Curso

```
Parte 0: Infraestructura Base y Entorno de Desarrollo
  0.1  Monorepo y estructura del proyecto
  0.2  Dockerización profesional (PHP-FPM, Nginx, Postgres, RabbitMQ, Datadog)
  0.3  Datadog APM local con ddtrace

Parte 1: PHP 8.2+ Moderno, POO Avanzada y Fundamentos
  1.1  Clases, Interfaces, DTOs inmutables, Value Objects
  1.2  Dominio rico, Principios SOLID, Excepciones de negocio

Parte 2: Symfony Framework y Arquitectura Hexagonal
Parte 3: CQRS y Event-Driven Architecture con RabbitMQ
Parte 4: Testing y BDD (PHPUnit + Behat)
Parte 5: Observabilidad, Kubernetes y CI/CD
```

## Convenciones del Curso

### Commits

Todos los commits siguen [Conventional Commits](https://www.conventionalcommits.org/):

```
tipo(alcance): descripción corta

Tipos: feat, fix, test, chore, docs, refactor, style, ci
Alcance: parte0, parte1, wallet-service, etc.
```

### Ejercicios

Cada ejercicio tiene:
- **Enunciado** claro con entregable esperado
- **Verificación** mediante comandos o assertions
- **Puntuación** en la rúbrica del final de cada parte

### Directorio del Curso

```
curso-fsc-php/
├── README.md                      # Este archivo
├── .editorconfig                  # Formato consistente
├── parte0/
│   ├── README.md                  # Lecciones de la Parte 0
│   ├── ejercicios/                # Enunciados de ejercicios
│   │   ├── 0.1-http-requests.md
│   │   ├── 0.2-sequence-diagram.md
│   │   ├── 0.3-json-contract.md
│   │   ├── 0.4-entorno-verificado.md
│   │   └── 0.5-git-workflow.md
│   └── scripts/
│       └── doctor.sh              # Verificador de entorno
├── parte1/
│   ├── README.md                  # Lecciones de la Parte 1
│   ├── ejercicios/                # Enunciados de ejercicios
│   │   ├── 1.1-symfony-skeleton.md
│   │   ├── 1.2-primera-ruta.md
│   │   ├── 1.3-web-test-case.md
│   │   ├── 1.4-tipo-sistema.md
│   │   ├── 1.5-enums-y-match.md
│   │   ├── 1.6-named-args.md
│   │   ├── 1.7-dinero-vo.md
│   │   ├── 1.8-readonly.md
│   │   ├── 1.9-excepciones.md
│   │   ├── 1.10-billetera.md
│   │   ├── 1.11-movimiento.md
│   │   ├── 1.12-data-providers.md
│   │   ├── 1.13-architecture-test.md
│   │   └── 1.14-quality-gates.md
│   └── soluciones/
│       ├── composer.json
│       ├── phpunit.xml.dist
│       ├── phpstan.neon
│       ├── .php-cs-fixer.dist.php
│       ├── Makefile
│       ├── public/index.php
│       └── src/
│           ├── Domain/
│           │   ├── Dinero.php
│           │   ├── Moneda.php
│           │   ├── Billetera.php
│           │   ├── Movimiento.php
│           │   ├── TipoMovimiento.php
│           │   └── Excepcion/
│           │       ├── FondosInsuficientes.php
│           │       └── MonedaNoSoportada.php
│           └── Controller/
│               └── SaludController.php
│       └── tests/
│           ├── Unit/
│           │   └── Domain/
│           │       ├── DineroTest.php
│           │       ├── BilleteraTest.php
│           │       └── MovimientoTest.php
│           └── Architecture/
│               └── DominioPuroTest.php
└── docs/
    └── images/
```

## Requisitos Previos

- Conocimientos básicos de programación (cualquier lenguaje)
- Terminal / línea de comandos (Linux, macOS, o WSL2 en Windows)
- Git instalado y configurado
- Un editor de código (VS Code recomendado)

## Verificación de Entorno

Antes de comenzar, ejecuta el script de verificación:

```bash
bash parte0/scripts/doctor.sh
```

Salida esperada:

```json
{
  "php": { "installed": true, "version": "8.3.x", "meets_requirement": true },
  "composer": { "installed": true, "version": "2.7.x", "meets_requirement": true },
  "symfony_cli": { "installed": true, "version": "7.x" },
  "git": { "installed": true, "version": "2.43.x", "configured": true },
  "docker": { "installed": true, "version": "24.x", "running": true },
  "verdict": "READY"
}
```

## Licencia

Material educativo. Uso libre con atribución.
