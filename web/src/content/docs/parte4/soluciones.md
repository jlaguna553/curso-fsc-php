---
title: "Soluciones — Parte 4 — Testing Profesional"
description: "Código fuente de las soluciones de la Parte 4"
sidebar: {"label":"Soluciones","order":100}
---


> 📁 **10 archivos** de código de referencia.

## Makefile

```makefile
# Makefile — Comandos de desarrollo para wallet-service (Parte 4)
#
# MAKE: Herramienta de automatización que ejecuta "targets" (objetivos).
# ESTE Makefile REEMPLAZA al de la Parte 1: la parte 4 añade Behat,
# cobertura y mutational testing a la pipeline de calidad.
#
# Uso:
#   make test          → TODAS las suites (PHPUnit + Behat + cobertura)
#   make test-unit     → Solo PHPUnit (dominio + aplicación)
#   make test-behat    → Solo Behat (BDD / Gherkin)
#   make coverage      → Cobertura HTML + texto (PCOV)
#   make mutation      → Infection: MSI 85% (calidad de los tests)
#   make stan          → Análisis estático con PHPStan nivel 6
#   make fix           → Auto-formatea código con CS-Fixer
#   make ci            → Ejecuta todo lo anterior (como en CI)
#   make serve         → Inicia servidor de desarrollo
#
# PIRÁMIDE DE TESTING (Parte 4 Lección 4.1):
#
#        /  E2E (Behat)  \   ← pocos, lentos, caros
#       /    Integration    \  ← cuando existan (Parte 5+)
#      /========================\
#     |    Unit (PHPUnit)       | ← muchos, rápidos, baratos  ← BASE
#      \========================/
#
# La regla de oro: SI UN TEST UNITARIO FALLA, los de más arriba
# probablemente también lo harán. Ejecuta siempre de abajo hacia arriba.

# .PHONY le dice a Make que estos targets NO son archivos.
# Sin esto, Make buscaría archivos llamados "test", "stan", etc.
# y si existieran, no ejecutaría el comando.
.PHONY: test test-unit test-behat coverage mutation stan fix fix-check ci serve clean all help

# Variables — evitan repetir rutas largas en cada target.
# El $() de Make reemplaza las variables en tiempo de ejecución.
PHP = php                     # Binario de PHP
PHPUNIT = vendor/bin/phpunit  # Binario de PHPUnit (dentro de vendor/)
BEHAT = vendor/bin/behat      # Binario de Behat
INFECTION = vendor/bin/infection  # Binario de Infection
PHPSTAN = vendor/bin/phpstan  # Binario de PHPStan
FIXER = vendor/bin/php-cs-fixer  # Binario de PHP-CS-Fixer
SYMFONY = symfony             # CLI de Symfony

# ------------------------------------------------------------------------------
# TARGET: test
# EJECUTA TODA LA SUITE (Ejercicio 4.8).
#
# Orden importa (pirámide):
#   1. Unit con cobertura  → los tests rápidos y la cobertura de src/Domain
#   2. Behat               → los escenarios BDD completos (E2E)
#
# --colors=forced: muestra colores siempre (útil en CI, que no es terminal)
# --coverage-text: escribe la cobertura en consola (texto plano)
# --format=pretty: Behat en formato detallado (el progreso usa --format=progress)
# ------------------------------------------------------------------------------
test:
	$(PHP) $(PHPUNIT) --colors=forced --testsuite unit --coverage-text
	$(PHP) $(BEHAT) --format=pretty

# ------------------------------------------------------------------------------
# TARGET: test-unit
# SOLO la capa de Unit (dominio + aplicación).
# Los tests de la base de la pirámide: rápidos y sin dependencias externas.
#
# --testdox: muestra resultados en formato legible (verbose, pero claro)
#
# Ejemplo de salida:
#   App\Domain\RetiroDataProviderTest
#    ✓ Retiro exitoso descuenta saldo                        0.001s
#    ✓ Retiro sin fondos lanza excepción                     0.001s
#   Tests: 16 passed, 16 total
# ------------------------------------------------------------------------------
test-unit:
	$(PHP) $(PHPUNIT) --colors=forced --testsuite unit --testdox

# ------------------------------------------------------------------------------
# TARGET: test-behat
# SOLO Behat (BDD). Ejecuta features/billetera.feature
#
# Cada paso de Gherkin se traduce a un método del FeatureContext.
# Formato progress: . puntos por paso OK, F por fallo (deja la salida corta).
# ------------------------------------------------------------------------------
test-behat:
	$(PHP) $(BEHAT) --format=progress

# ------------------------------------------------------------------------------
# TARGET: coverage
# Cobertura de código con PCOV (Lección 4.5).
#
# --coverage-html=coverage: genera reporte interactivo navegable
# --coverage-text: además escribe el resumen en consola
#
# PCOV es más rápido que Xdebug porque solo registra líneas ejecutadas,
# sin el costo de depuración (ideal para CI).
#
# Ejemplo de salida:
#   src/Domain/Billetera.php  100.0%
#   src/Domain/Dinero.php     100.0%
#   ...                       100.0%
# ------------------------------------------------------------------------------
coverage:
	$(PHP) $(PHPUNIT) --testsuite unit --coverage-html=coverage --coverage-text

# ------------------------------------------------------------------------------
# TARGET: mutation
# Mutational testing con Infection (Lección 4.5 / Ejercicio 4.7).
#
# Infection MUTA tu código (cambia == por !=, 1 por 0, etc.) y re-ejecuta
# tus tests. Un "mutante sobreviviente" = ese cambio NO rompió nada
# = hay una ruta de código que tus tests NO cubren (o no verifican).
#
# MSI (Mutation Score Indicator): % de mutantes matados por los tests.
#   70-85%: aceptable   ·   85%+: excelente (nuestro objetivo)
#
# --show-mutations: imprime cada mutante vivo con su diff
# ------------------------------------------------------------------------------
mutation:
	$(PHP) $(INFECTION) --show-mutations

# ------------------------------------------------------------------------------
# TARGET: stan
# Análisis estático con PHPStan nivel 6.
#
# PHPStan analiza el código SIN ejecutarlo.
# Detecta: tipos incorrectos, argumentos faltantes, nulos no manejados, etc.
#
# Nivel 6 es el equilibrio entre:
#   - Nivel 0: casi nada (no vale la pena)
#   - Nivel 6: cubre la mayoría de bugs comunes (nuestro objetivo)
#   - Nivel 10: máximo, pero demasiado estricto para empezar
#
# --no-progress: no muestra barra de progreso (mejor para CI)
# --ansi: muestra colores ANSI (para terminals que los soporten)
#
# Ejemplo de salida exitosa:
#   [OK] No errors
# ------------------------------------------------------------------------------
stan:
	$(PHP) $(PHPSTAN) analyse src tests --level=6 --no-progress --ansi

# ------------------------------------------------------------------------------
# TARGET: fix
# Auto-formatea el código con PHP-CS-Fixer.
#
# PHP-CS-Fixer reescribe el código para que cumpla el estándar PSR-12.
# En CI se usa --dry-run (no modifica archivos, solo reporta).
# Aquí en local sí modificamos para convenience.
#
# --verbose: muestra qué reglas aplicó.
# --diff: muestra el diff de cada archivo modificado.
# --allow-risky=yes: permite reglas que pueden cambiar comportamiento
#   (ej: agregar return types, strict_types, etc.)
# ------------------------------------------------------------------------------
fix:
	$(PHP) $(FIXER) fix --verbose --diff --allow-risky=yes

# ------------------------------------------------------------------------------
# TARGET: fix-check
# Verifica si hay código sin formatear (sin modificar archivos).
# Usado en CI: si hay cambios pendientes, CI falla.
#
# --dry-run: NO modifica archivos, solo retorna exit code 1 si hay cambios.
# ------------------------------------------------------------------------------
fix-check:
	$(PHP) $(FIXER) fix --dry-run --diff --allow-risky=yes

# ------------------------------------------------------------------------------
# TARGET: ci
# Ejecuta TODA la pipeline de calidad de código.
# Equivalente a lo que GitHub Actions ejecuta en cada PR (Parte 5).
#
# Secuencia:
#   1. Tests completos (¿el código funciona? — PHPUnit + Behat)
#   2. Mutation Score (¿los tests son BUENOS? — Infection MSI 85%)
#   3. PHPStan (¿el código es tipo-seguro y sin bugs sutiles?)
#   4. CS-Fixer dry-run (¿el código es consistente y legible?)
#
# Si CUALQUIERA falla, `make ci` falla. No se sigue al siguiente paso.
# ------------------------------------------------------------------------------
ci: test mutation stan fix-check
	@echo ""
	@echo "══════════════════════════════════════════"
	@echo "  ✅ CI PASSED — Todos los checks verdes"
	@echo "══════════════════════════════════════════"

# ------------------------------------------------------------------------------
# TARGET: serve
# Inicia el servidor de desarrollo de Symfony.
#
# symfony serve usa PHP built-in server (para desarrollo).
# -d: directorio de document root (public/)
# --no-clean: no limpia la caché antes de iniciar
#
# El servidor escucha en https://127.0.0.1:8000
# Symfony CLI genera un certificado SSL automáticamente.
#
# IMPORTANTE: Este servidor NO es para producción.
# En producción usamos Nginx + PHP-FPM (Parte 0 Lección 0.2).
# ------------------------------------------------------------------------------
serve:
	$(SYMFONY) serve -d public --no-clean

# ------------------------------------------------------------------------------
# TARGET: clean
# Limpia caches del proyecto.
#
# cache:clear: limpia el cache de Symfony (var/cache/)
# Esto es útil después de cambiar configuración (services.yaml, etc.)
# ------------------------------------------------------------------------------
clean:
	$(SYMFONY) cache:clear

# ------------------------------------------------------------------------------
# TARGET: all
# Ejecuta todo: limpiar + calidad + servidor.
# Uso: `make all` para empezar una sesión de desarrollo limpia.
# ------------------------------------------------------------------------------
all: clean ci serve

# ------------------------------------------------------------------------------
# TARGET: help
# Muestra todos los targets disponibles con sus descripciones.
# Uso: `make help`
# ------------------------------------------------------------------------------
help:
	@echo "Comandos disponibles para wallet-service (Parte 4):"
	@echo ""
	@echo "  make test        TODAS las suites (unit + behat + cobertura)"
	@echo "  make test-unit   Solo PHPUnit (unit)"
	@echo "  make test-behat  Solo Behat (BDD)"
	@echo "  make coverage    Cobertura PCOV (HTML + texto)"
	@echo "  make mutation    Infection MSI (calidad de tests)"
	@echo "  make stan        Análisis estático con PHPStan nivel 6"
	@echo "  make fix         Auto-formatear código"
	@echo "  make ci          Pipeline completa (test + mutation + stan + fix-check)"
	@echo "  make serve       Iniciar servidor de desarrollo"
	@echo "  make clean       Limpiar cache de Symfony"
	@echo "  make all         Limpiar + CI + servidor"
	@echo ""
```

## behat.yml

```yaml
# behat.yml — Configuración de Behat para el wallet-service
#
# BEHAT: framework de BDD (Behavior Driven Development).
# Escribe los REQUISITOS como historias ejecutables:
#
#   feature:  "Los depósitos se registran en la billetera"
#   scenario: "Depositar 100 MXN a una billetera con saldo 0"
#     - Dado que existe una billetera con saldo 0
#     - Cuando deposito 100 MXN
#     - Entonces el balance debe ser 100 MXN
#
# Behat lee el .feature (Gherkin), lo traduce a pasos y ejecuta
# los métodos correspondientes del FeatureContext.

default:
    # Aplicación bajo prueba: Symfony (kernel completo).
    # Behat bootea el kernel en cada suite para tener el contenedor real.
    suites:
        billeteras:
            # Directorio de archivos .feature
            paths: [ '%paths.base%/features' ]

            # Contexto (clase con los pasos "Dado/Cuando/Entonces")
            contexts:
                - App\Tests\Behat\FeatureContext

            # Filtro de escenarios por tags (opcional)
            filters:
                tags: '~@skip'

    # Formatters: cómo se muestra el resultado en consola.
    formatters:
        progress: true      # puntos verdes/rojos por paso
        pretty: true        # además, detalle por escenario

    # Extensiones de Behat (Composer: friends-of-behat/symfony-extension)
    extensions:
        FriendsOfBehat\SymfonyExtension:
            kernel:
                class: App\Kernel
                environment: test
```

## features/billetera.feature

```gherkin
# features/billetera.feature — Especificación ejecutable del dominio
#
# GHERKIN: lenguaje humano que se convierte en tests automáticos.
# Los keywords están en ESPAÑOL porque así habla el negocio.
#
# Reglas de oro de BDD:
#   1. Escribe el .feature ANTES del código (especificación viva)
#   2. Cada escenario es UN comportamiento observable
#   3. El lenguaje describe el NEGOCIO, no la implementación
#      (nunca escribas "cuando hago POST /deposito" — eso es detalle técnico)
#
# Glosario de keywords en español:
#   Característica:  → Feature (lo que el sistema hace)
#   Antecedentes:    → Background (precondiciones de cada escenario)
#   Escenario:       → Scenario (un caso concreto)
#   Dado / Cuando / Entonces / Y / Pero  → Given / When / Then / And / But

Característica: Depósitos en la billetera
  Como usuario de PrestaFlow
  Quiero depositar dinero en mi billetera
  Para tener saldo disponible para mis transacciones

  Antecedentes:
    Dado que existe una billetera de "ana" en moneda "MXN" con saldo "0.00"

  Escenario: Depósito exitoso incrementa el balance
    Cuando deposito "500.00" MXN con descripción "Nómina"
    Entonces el balance debe ser "500.00"
    Y el total de movimientos debe ser 1

  Escenario: Depósito pequeño también se registra
    Cuando deposito "1.00" MXN con descripción "Monedas"
    Entonces el balance debe ser "1.00"

  Escenario: Depósito en moneda distinta es rechazado
    Cuando deposito "500.00" USD con descripción "Pago"
    Entonces el sistema debe rechazar la operación
    Y el balance debe seguir siendo "0.00"

Característica: Retiros con fondo limitado
  Como usuario de PrestaFlow
  Quiero retirar dinero de mi billetera
  Pero nunca más de lo que tengo

  Antecedentes:
    Dado que existe una billetera de "bob" en moneda "MXN" con saldo "1000.00"

  Escenario: Retiro válido descuenta el balance
    Cuando retiro "400.00" MXN con descripción "Retiro ATM"
    Entonces el balance debe ser "600.00"

  Escenario: Retiro mayor al saldo es rechazado
    Cuando retiro "1500.00" MXN con descripción "Imposible"
    Entonces el sistema debe rechazar la operación
    Y el balance debe seguir siendo "1000.00"

Característica: Idempotencia de depósitos
  Como sistema financiero
  Quiero ignorar comandos duplicados
  Para no duplicar dinero en la billetera

  Antecedentes:
    Dado que existe una billetera de "carol" en moneda "MXN" con saldo "0.00"

  Escenario: El mismo comando dos veces no duplica el saldo
    Cuando deposito "300.00" MXN con idempotencia "clave-fija"
    Y deposito "300.00" MXN con idempotencia "clave-fija"
    Entonces el balance debe ser "300.00"
    Y el total de movimientos debe ser 1
```

## features/bootstrap/FeatureContext.php

```php
<?php
// features/bootstrap/FeatureContext.php — Pasos Gherkin ↔ código
//
# Este contexto traduce cada línea "Dado/Cuando/Entonces" a código.
# Behat matchea los métodos por ANOTACIÓN (el patrón Gherkin en español).
#
# En lugar de tocar la BD/handlers directamente, usamos los BUSES CQRS
# de la aplicación (igual que el controller): un test BDD que ejercita
# el flujo REAL de producción.
#
# NOTA DE FIRMAS: el número de parámetros de cada método debe coincidir
# EXACTAMENTE con el número de grupos de captura "(...)" del regex.
# Los escenarios no repiten al usuario en cada paso: el usuario activo
# es el del ÚLTIMO "Dado que existe una billetera..." (ver ultimoUsuario()).

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\QueryBus;
use App\Message\Command\CrearBilleteraCommand;
use App\Message\Command\RealizarDepositoCommand;
use App\Message\Command\RealizarRetiroCommand;
use App\Message\Query\ObtenerBilleteraQuery;
use Behat\Behat\Context\Context;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Contexto de Behat: implementa los pasos en español.
 *
 * Se bootea el kernel Symfony con el entorno "test" (extensión
 * FriendsOfBehat\SymfonyExtension lo inyecta en $kernel).
 */
final class FeatureContext extends KernelTestCase implements Context
{
    private CommandBus $commandBus;
    private QueryBus $queryBus;

    /** Billeteras creadas en el escenario (id ⇒ id) */
    private array $billeteras = [];

    /** Última excepción capturada (operaciones rechazadas). */
    private ?\Throwable $ultimoError = null;

    public function __construct()
    {
        // bootKernel() estático de KernelTestCase: arranca Symfony (env test).
        static::bootKernel();

        // Obtenemos los buses reales del contenedor.
        $container = static::getContainer();
        $this->commandBus = $container->get(CommandBus::class);
        $this->queryBus = $container->get(QueryBus::class);
    }

    // =========================================================================
    // DADO (precondiciones)
    // =========================================================================

    /**
     * @Given /^que existe una billetera de "([^"]+)" en moneda "([^"]+)" con saldo "([^"]+)"$/
     */
    public function creoBilleteraConSaldo(string $usuario, string $moneda, string $saldo): void
    {
        // Despachamos el command REAL de crear billetera por el bus.
        $id = $this->commandBus->dispatch(CrearBilleteraCommand::crear(
            usuarioId: $usuario,
            moneda: $moneda,
        ));

        $this->billeteras[$usuario] = $id;

        // Si el saldo inicial no es cero, simulamos un depósito fundacional.
        // (crea 1 movimiento: los escenarios que lo cuenten lo saben).
        if ($saldo !== '0.00') {
            $this->deposito($saldo, 'Saldo inicial');
        }
    }

    // =========================================================================
    // CUANDO (acciones)
    // =========================================================================

    /**
     * @When /^deposito "([^"]+)" MXN con descripción "([^"]+)"$/
     */
    public function deposito(string $monto, string $descripcion): void
    {
        $this->depositoMoneda($this->ultimoUsuario('depositar'), $monto, 'MXN', $descripcion, (string) Uuid::v4());
    }

    /**
     * @When /^deposito "([^"]+)" MXN con idempotencia "([^"]+)"$/
     */
    public function depositoConIdempotencia(string $monto, string $clave): void
    {
        $this->depositoMoneda($this->ultimoUsuario('depositar'), $monto, 'MXN', 'Depósito BDD', $clave);
    }

    /**
     * @When /^deposito "([^"]+)" USD con descripción "([^"]+)"$/
     */
    public function depositoUsd(string $monto, string $descripcion): void
    {
        $this->depositoMoneda($this->ultimoUsuario('depositar'), $monto, 'USD', $descripcion, (string) Uuid::v4());
    }

    /**
     * @When /^retiro "([^"]+)" MXN con descripción "([^"]+)"$/
     */
    public function retiro(string $monto, string $descripcion): void
    {
        $usuario = $this->ultimoUsuario('retirar');

        $this->intentar(fn () => $this->commandBus->dispatch(
            RealizarRetiroCommand::crear(
                billeteraId: $this->billeteras[$usuario],
                monto: $monto,
                moneda: 'MXN',
                descripcion: $descripcion,
                idempotencyKey: (string) Uuid::v4(),
            ),
        ));
    }

    // =========================================================================
    // ENTONCES (verificaciones)
    // =========================================================================

    /**
     * @Then /^el balance debe ser "([^"]+)"$/
     */
    public function elBalanceDebeSer(string $esperado): void
    {
        $usuario = $this->ultimoUsuario('el balance');
        $balance = $this->balanceDe($usuario);

        if ($balance !== $esperado) {
            throw new \RuntimeException(
                "Balance esperado: {$esperado}, obtenido: {$balance}"
            );
        }
    }

    /**
     * @Then /^el balance debe seguir siendo "([^"]+)"$/
     */
    public function elBalanceSigueSiendo(string $esperado): void
    {
        // Frente a un rechazo, el balance NO debe haber cambiado.
        $this->elBalanceDebeSer($esperado);
    }

    /**
     * @Then /^el total de movimientos debe ser (\d+)$/
     */
    public function elTotalDeMovimientosDebeSer(int $esperado): void
    {
        $usuario = $this->ultimoUsuario('el total de movimientos');
        $total = $this->totalMovimientosDe($usuario);

        if ($total !== $esperado) {
            throw new \RuntimeException(
                "Movimientos esperados: {$esperado}, obtenidos: {$total}"
            );
        }
    }

    /**
     * @Then /^el sistema debe rechazar la operación$/
     */
    public function elSistemaDebeRechazar(): void
    {
        if ($this->ultimoError === null) {
            throw new \RuntimeException(
                'Se esperaba que la operación fallara, pero se completó.'
            );
        }
    }

    // =========================================================================
    // HELPERS privados
    // =========================================================================

    private function depositoMoneda(string $usuario, string $monto, string $moneda, string $descripcion, string $clave): void
    {
        $this->intentar(fn () => $this->commandBus->dispatch(
            RealizarDepositoCommand::crear(
                billeteraId: $this->billeteras[$usuario],
                monto: $monto,
                moneda: $moneda,
                descripcion: $descripcion,
                idempotencyKey: $clave,
            ),
        ));
    }

    /**
     * Ejecuta una acción capturando errores (para escenarios de rechazo).
     */
    private function intentar(callable $accion): void
    {
        $this->ultimoError = null;

        try {
            $accion();
        } catch (\Throwable $e) {
            // Guardamos el error; los pasos Entonces lo verifican.
            $this->ultimoError = $e;
        }
    }

    private function balanceDe(string $usuario): string
    {
        $snapshot = $this->queryBus->ask(new ObtenerBilleteraQuery($this->billeteras[$usuario]));

        return $snapshot['balance'];
    }

    private function totalMovimientosDe(string $usuario): int
    {
        $snapshot = $this->queryBus->ask(new ObtenerBilleteraQuery($this->billeteras[$usuario]));

        return (int) $snapshot['total_movimientos'];
    }

    private function ultimoUsuario(string $contexto): string
    {
        if ($this->billeteras === []) {
            throw new \RuntimeException(
                "No hay billetera para {$contexto}: usa 'Dado que existe...' primero."
            );
        }

        $usuarios = array_keys($this->billeteras);

        return end($usuarios);
    }
}
```

## infection.json5

```json5
{
    // infection.json5 — Configuración de Infection (mutational testing)
    //
    // INFECTION: herramienta que MUTA tu código para probar TUS TESTS.
    // Cambia operadores (== → !=), valores (1 → 0), condiciones, etc.
    // y re-ejecuta la suite. Si un test NO detecta el cambio, hay un
    // "mutante vivo" → ese código no está bien cubierto.
    //
    // MSI (Mutation Score Indicator): % de mutantes que tus tests mataron.
    //   < 70% : los tests son débiles (cualquier refactor va a romper algo)
    //   70-85%: aceptable para producción
    //   85%+  : excelente (nuestro objetivo en este curso)
    //
    // Ejecuta con: ./vendor/bin/infection --show-mutations

    // Dónde buscar código que mutar.
    // Limitamos a src/Domain: el corazón del negocio (dinero, billeteras).
    // Cuanto más código, más lenta la ejecución — Domain es el foco.
    "source": {
        "directories": ["src/Domain"]
    },

    // Logs de salida.
    // "text": escribe un reporte humano-leíble de cada mutante en infection.log
    "logs": {
        "text": "infection.log"
    },

    // Umbral mínimo del MSI global.
    // Si el MSI baja de 85%, Infection falla con exit code 1 (rompe CI).
    // Así la pipeline NO deja avanzar con tests débiles.
    "minMsi": 85,

    // Umbral mínimo sobre el código CUBIERTO (ignora los mutantes que
    // nacen en código sin tests — esos no pueden matarse por definición).
    "minCoveredMsi": 90
}
```

## src/Application/CommandHandler/RealizarRetiroCommandHandler.php

```php
<?php
// src/Application/CommandHandler/RealizarRetiroCommandHandler.php — Use case
//
# Handler del retiro: el espejo de RealizarDepositoCommandHandler.
#
# Cadena de responsabilidad idéntica:
#   1. IDEMPOTENCIA → verificar la clave antes de tocar la billetera
#   2. LÓGICA      → delegar en el agregado ($billetera->retirar())
#   3. PROPAGACIÓN → publicar TransaccionCompletadaEvent (tipo "retiro")
#
# Diferencia clave con el depósito:
#   - retirar() válida fondos suficientes y lanza FondosInsuficientes
#     SI balance <= monto (regla de dominio estricta, Parte 1).
#   - El controller traduce esa excepción a HTTP 422 (ejercicio 3.2).
#
# Este archivo es la SOLUCIÓN al ejercicio 3.2 (Handlers con HandleTrait).
# Se incluye aquí (Parte 4) porque el FeatureContext de Behat lo usa
# para probar los retiros en el flujo BDD completo.

declare(strict_types=1);

namespace App\Application\CommandHandler;

use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Domain\Repository\IdempotenciaRepository;
use App\Message\Command\RealizarRetiroCommand;
use App\Message\Event\TransaccionCompletadaEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Maneja el comando "realizar un retiro".
 *
 * @return string Balance NUEVO en formato decimal ("600.00")
 *
 * @throws BilleteraNoEncontrada    Si la billetera no existe
 * @throws \App\Domain\Exception\FondosInsuficientes Si no hay fondos
 */
final readonly class RealizarRetiroCommandHandler
{
    /**
     * @param BilleteraRepository    $billeteras  Puerto de persistencia de billeteras
     * @param IdempotenciaRepository $idempotencia Puerto de claves idempotentes
     * @param MessageBusInterface    $eventBus     Bus de eventos (event.bus)
     */
    public function __construct(
        private BilleteraRepository $billeteras,
        private IdempotenciaRepository $idempotencia,
        private MessageBusInterface $eventBus,
    ) {
    }

    /**
     * Ejecuta el retiro de forma idempotente.
     *
     * @param RealizarRetiroCommand $command El comando del bus
     *
     * @return string Balance nuevo en formato decimal ("600.00")
     */
    public function __invoke(RealizarRetiroCommand $command): string
    {
        // 1. CLAVE DE IDEMPOTENCIA: misma mecánica que el depósito.
        $clave = $command->claveUnica();

        // 2. Localizar la billetera (puerto, no implementación).
        $billetera = $this->billeteras->findById($command->billeteraId);

        if ($billetera === null) {
            throw new BilleteraNoEncontrada($command->billeteraId);
        }

        // 3. PASO IDEMPOTENTE: si la clave ya se procesó, no repetimos.
        if ($this->idempotencia->existe($clave)) {
            return $billetera->balance()->formateadoDecimal();
        }

        // 4. Construir el Dinero del retiro.
        $moneda = Moneda::desdeCodigo($command->moneda);
        $dinero = Dinero::crear($command->monto, $moneda);

        // 5. DELEGAR al agregado: retirar() valida fondos, moneda y >0.
        $movimiento = $billetera->retirar($dinero, $command->descripcion);

        // 6. Persistir el nuevo estado.
        $this->billeteras->save($billetera);

        // 7. Registrar la clave como procesada (después del flush exitoso).
        $this->idempotencia->registrar($clave);

        // 8. Publicar el evento de dominio (el ecosistema reacciona solo).
        $balanceNuevo = $billetera->balance();
        $this->eventBus->dispatch(
            new TransaccionCompletadaEvent(
                transaccionId: $movimiento->id,
                billeteraId: $billetera->id,
                tipo: 'retiro',
                monto: $dinero->formateadoDecimal(),
                moneda: $moneda->value,
                balanceNuevo: $balanceNuevo->formateadoDecimal(),
                timestamp: $movimiento->creadoEn->format('Y-m-d\TH:i:s\Z'),
            ),
        );

        // 9. Respuesta útil para el controller: balance actualizado.
        return $balanceNuevo->formateadoDecimal();
    }
}
```

## src/Message/Command/RealizarRetiroCommand.php

```php
<?php
// src/Message/Command/RealizarRetiroCommand.php — Command de CQRS
//
# Command: retirar dinero de una billetera.
# Es el espejo de RealizarDepositoCommand, con la misma estructura:
# DTO inmutable + factory crear() + clave de idempotencia.
#
# Este archivo es la SOLUCIÓN al ejercicio 3.1 (Commands y Queries).
# Se incluye aquí (Parte 4) porque el FeatureContext de Behat lo usa
# para probar los retiros en el flujo BDD completo.

declare(strict_types=1);

namespace App\Message\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Command: realizar un retiro de una billetera.
 */
final readonly class RealizarRetiroCommand
{
    /**
     * @param string $billeteraId   ID de la billetera origen
     * @param string $monto         Monto en formato decimal (ej: "1500.00")
     * @param string $moneda        Código ISO de moneda
     * @param string $descripcion   Descripción legible
     * @param string $idempotencyKey Clave única para evitar duplicados
     * @param string $commandId     ID del command (tracing)
     */
    public function __construct(
        public readonly string $billeteraId,
        public readonly string $monto,
        public readonly string $moneda,
        public readonly string $descripcion,
        public readonly string $idempotencyKey = '',
        public readonly string $commandId = '',
    ) {
    }

    /**
     * Factory method con generación automática de claves.
     *
     * @return self Command listo para despachar
     */
    public static function crear(
        string $billeteraId,
        string $monto,
        string $moneda,
        string $descripcion,
        string $idempotencyKey,
    ): self {
        return new self(
            billeteraId: $billeteraId,
            monto: $monto,
            moneda: $moneda,
            descripcion: $descripcion,
            idempotencyKey: $idempotencyKey,
            commandId: (string) Uuid::v4(),
        );
    }

    /**
     * Genera una clave de idempotencia automática si el cliente no la provee.
     *
     * @return string Clave de idempotencia
     */
    public function claveUnica(): string
    {
        return $this->idempotencyKey !== ''
            ? $this->idempotencyKey
            : $this->commandId;
    }
}
```

## tests/Unit/Application/DepositoConMocksTest.php

```php
<?php
// tests/Unit/Application/DepositoConMocksTest.php — Test Doubles (Parte 4)
//
# TEST DOUBLES: sustitutos de dependencias reales.
#   - Dummy:    se pasa pero nunca se usa (cumple la firma)
#   - Fake:     implementación simplificada (la usamos en la Parte 3)
#   - Stub:     retorna respuestas programadas
#   - Mock:     stub + verificación de llamadas (expectations)
#   - Spy:      registra llamadas para verificarlas después
#
# Aquí usamos los MOCKS nativos de PHPUnit (createMock) para el event bus
# y el repositorio: configuramos expectativas Y verificamos interacciones.

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\CommandHandler\RealizarDepositoCommandHandler;
use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Domain\Repository\IdempotenciaRepository;
use App\Message\Command\RealizarDepositoCommand;
use App\Message\Event\TransaccionCompletadaEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class DepositoConMocksTest extends TestCase
{
    #[Test]
    public function repositorio_stub_retorna_billetera_y_verifica_save(): void
    {
        // STUB del repositorio: le decimos QUÉ responder a findById().
        // PHPUnit crea SOLO el método que configuramos (los demás devuelven
        // valores por defecto: null, 0, []).
        $repositorio = $this->createMock(BilleteraRepository::class);
        $repositorio->method('findById')
            ->with($this->equalTo('wal-1'))          // exigimos el argumento exacto
            ->willReturn($this->billeteraConSaldo('100.00'));

        // MOCK del repositorio de idempotencia: verificamos que
        // registrar() se llame EXACTAMENTE una vez con la clave esperada.
        $idempotencia = $this->createMock(IdempotenciaRepository::class);
        $idempotencia->expects($this->once())          // → 1 llamada
            ->method('registrar')
            ->with($this->equalTo('clave-unica-1'));

        // MOCK del event bus: esperamos 1 dispatch de TransaccionCompletadaEvent.
        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TransaccionCompletadaEvent::class))
            ->willReturnCallback(fn ($msg, array $stamps = []) => new Envelope($msg));

        // WHEN: ejecutamos el handler con los doubles.
        $handler = new RealizarDepositoCommandHandler(
            billeteras: $repositorio,
            idempotencia: $idempotencia,
            eventBus: $eventBus,
        );

        $balance = $handler(RealizarDepositoCommand::crear(
            billeteraId: 'wal-1',
            monto: '50.00',
            moneda: 'MXN',
            descripcion: 'Test con mocks',
            idempotencyKey: 'clave-unica-1',
        ));

        // THEN: el balance resultante es el esperado.
        self::assertSame('150.00', $balance);

        // La verificación de expects() ocurre automáticamente en tearDown()
        // de PHPUnit: si registrar() no se llamó o se llamó 2 veces → test ROJO.
    }

    #[Test]
    public function idempotencia_mock_devuelve_resultado_sin_dispatch(): void
    {
        // STUB: existe() devuelve true → el handler debe salir sin tocar nada.
        $repositorio = $this->createMock(BilleteraRepository::class);
        $repositorio->method('findById')
            ->willReturn($this->billeteraConSaldo('100.00'));

        $idempotencia = $this->createMock(IdempotenciaRepository::class);
        $idempotencia->method('existe')
            ->willReturn(true);

        // MOCK: el event bus NO debe recibir NINGUNA llamada.
        // expects(never()) → si se llama, el test falla.
        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->never())
            ->method('dispatch');

        $handler = new RealizarDepositoCommandHandler(
            billeteras: $repositorio,
            idempotencia: $idempotencia,
            eventBus: $eventBus,
        );

        $balance = $handler(RealizarDepositoCommand::crear(
            billeteraId: 'wal-1',
            monto: '50.00',
            moneda: 'MXN',
            descripcion: 'Duplicado',
            idempotencyKey: 'clave-repetida',
        ));

        // THEN: se devuelve el balance actual (100.00), no 150.00.
        self::assertSame('100.00', $balance);
    }

    #[Test]
    public function mock_con_callback_devuelve_valor_dinamico(): void
    {
        // STUB con callback: la respuesta depende del argumento.
        $repositorio = $this->createMock(BilleteraRepository::class);
        $repositorio->method('findById')
            ->willReturnCallback(
                fn (string $id): ?Billetera => $id === 'wal-existe'
                    ? $this->billeteraConSaldo('10.00')
                    : null,
            );

        $idempotencia = $this->createMock(IdempotenciaRepository::class);
        $idempotencia->method('existe')->willReturn(false);
        $idempotencia->method('registrar');

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->method('dispatch')->willReturnCallback(
            fn ($msg, array $stamps = []) => new Envelope($msg),
        );

        $handler = new RealizarDepositoCommandHandler(
            billeteras: $repositorio,
            idempotencia: $idempotencia,
            eventBus: $eventBus,
        );

        // La billetera que NO existe produce un resultado distinto:
        // findById('wal-fantasma') → null → BilleteraNoEncontrada.
        $this->expectException(\App\Application\Exception\BilleteraNoEncontrada::class);

        $handler(RealizarDepositoCommand::crear(
            billeteraId: 'wal-fantasma',
            monto: '5.00',
            moneda: 'MXN',
            descripcion: 'x',
            idempotencyKey: 'k3',
        ));
    }

    /**
     * Billetera de prueba con saldo inicial.
     */
    private function billeteraConSaldo(string $saldo): Billetera
    {
        $billetera = new Billetera(
            id: (string) Uuid::v4(),
            usuarioId: 'ana',
            moneda: Moneda::MXN,
        );
        $billetera->depositar(Dinero::crear($saldo, Moneda::MXN), 'Inicial');

        return $billetera;
    }
}
```

## tests/Unit/Domain/DineroDataProviderTest.php

```php
<?php
// tests/Unit/Domain/DineroDataProviderTest.php — Data Providers (Parte 4)
//
# DATA PROVIDERS: la forma de probar UNA regla con MUCHOS casos.
# En lugar de escribir 10 tests idénticos, escribes un test con un
# provider que inyecta los datos.
#
# Ventajas:
#   1. Legibilidad: la tabla de casos se lee de un vistazo
#   2. Mantenibilidad: añadir un caso = añadir una línea
#   3. Reporte granular: PHPUnit reporta CADA caso por separado
#
# PHPUnit 11 usa el atributo #[DataProvider('nombre')].

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Dinero;
use App\Domain\Exception\MonedaNoSoportada;
use App\Domain\Moneda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DineroDataProviderTest extends TestCase
{
    // =========================================================================
    // CONVERSIÓN DECIMAL → CENTAVOS
    // =========================================================================

    /**
     * El corazón de todo sistema financiero: "1500.00" MXN == 150000 centavos.
     *
     * La conversión DEBE ser exacta. Sin floats. Sin redondeos raros.
     *
     * @return array<string, array{decimal: string, centavosEsperados: int}>
     */
    public static function proveerDecimales(): array
    {
        // Clave = descripción del caso (aparece en el reporte).
        // Valor = [decimal, centavos esperados].
        return [
            'cero' => ['decimal' => '0.00', 'centavosEsperados' => 0],
            'simple' => ['decimal' => '1.00', 'centavosEsperados' => 100],
            'sin decimales' => ['decimal' => '10', 'centavosEsperados' => 1000],
            'con milésimas redondeadas' => ['decimal' => '0.005', 'centavosEsperados' => 1],
            'cantidad grande' => ['decimal' => '1234567.89', 'centavosEsperados' => 123456789],
        ];
    }

    #[Test]
    #[DataProvider('proveerDecimales')]
    public function convierte_decimal_a_centavos(string $decimal, int $centavosEsperados): void
    {
        // WHEN: creamos Dinero desde un decimal.
        $dinero = Dinero::crear($decimal, Moneda::MXN);

        // THEN: los centavos son exactos.
        self::assertSame($centavosEsperados, $dinero->centavos());
    }

    // =========================================================================
    // MONEDAS NO SOPORTADAS
    // =========================================================================

    /**
     * @return array<string, array{moneda: string}>
     */
    public static function proveerMonedasInvalidas(): array
    {
        return [
            'cripto' => ['moneda' => 'BTC'],
            'lowercase' => ['moneda' => 'mxn'],
            'inexistente' => ['moneda' => 'XYZ'],
            'numérica' => ['moneda' => '123'],
            'vacía' => ['moneda' => ''],
        ];
    }

    #[Test]
    #[DataProvider('proveerMonedasInvalidas')]
    public function rechaza_moneda_invalida(string $moneda): void
    {
        // EXPECT: ninguno de estos códigos está en el enum Moneda.
        // tryFrom() retorna null → MonedaNoSoportada.
        $this->expectException(MonedaNoSoportada::class);

        // Dinero::crear valida la moneda implícitamente al usar el enum.
        // Pero la excepción correcta la lanza Moneda::desdeCodigo().
        $monedaEnum = Moneda::tryFrom($moneda);
        if ($monedaEnum === null) {
            throw new MonedaNoSoportada(
                codigoMoneda: $moneda,
                monedasSoportadas: ['MXN', 'USD', 'EUR'],
            );
        }

        self::fail('No debió llegar aquí: la moneda debería ser rechazada.');
    }

    // =========================================================================
    // VALIDACIÓN DE MONTO INVÁLIDO
    // =========================================================================

    /**
     * @return array<string, array{monto: string}>
     */
    public static function proveerMontosInvalidos(): array
    {
        return [
            'negativo' => ['monto' => '-5.00'],
            'texto' => ['monto' => 'abc'],
            'vacío' => ['monto' => ''],
            'especiales' => ['monto' => '1,000.00'],   // coma como separador de miles NO es válido
            'null' => ['monto' => '0.0.0'],
        ];
    }

    #[Test]
    #[DataProvider('proveerMontosInvalidos')]
    public function rechaza_monto_invalido(string $monto): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dinero::crear($monto, Moneda::MXN);
    }

    // =========================================================================
    // SUMA Y RESTA (casos límite)
    // =========================================================================

    /**
     * @return array<string, array{a: string, b: string, esperado: string}>
     */
    public static function proveerSumas(): array
    {
        return [
            'ambos positivos' => ['a' => '1.00', 'b' => '2.50', 'esperado' => '3.50'],
            'sumar cero' => ['a' => '100.00', 'b' => '0.00', 'esperado' => '100.00'],
            'caso grande' => ['a' => '999999.99', 'b' => '0.01', 'esperado' => '1000000.00'],
        ];
    }

    #[Test]
    #[DataProvider('proveerSumas')]
    public function suma_montos_sin_perder_precision(
        string $a,
        string $b,
        string $esperado,
    ): void {
        $resultado = Dinero::crear($a, Moneda::MXN)
            ->sumar(Dinero::crear($b, Moneda::MXN));

        self::assertSame($esperado, $resultado->formateadoDecimal());
    }
}
```

## tests/Unit/Domain/RetiroDataProviderTest.php

```php
<?php
// tests/Unit/Domain/RetiroDataProviderTest.php — Data Provider del agregado
//
# Retiro es una operación con REGLAS ESTRICTAS (fondos suficientes).
# Los data providers permiten cubrir toda la matriz de casos en un test.
#
# REGLA DE DOMINIO (Billetera::retirar): balance <= monto → FondosInsuficientes.
# Ojo con el borde: retirar el 100% del saldo TAMBIÉN está prohibido
# (esMenorOIgualQue incluye la igualdad). El máximo retiro permitido
# deja al menos 1 centavo en la billetera.

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Exception\FondosInsuficientes;
use App\Domain\Moneda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetiroDataProviderTest extends TestCase
{
    /**
     * Matriz de retiros EXITOSOS: el retiro SIEMPRE debe ser MENOR al saldo.
     *
     * @return array<string, array{saldo: string, retiro: string, esperado: string}>
     */
    public static function proveerRetirosExitosos(): array
    {
        return [
            'retiro que deja un centavo' => ['saldo' => '100.00', 'retiro' => '99.99', 'esperado' => '0.01'],
            'mitad del saldo' => ['saldo' => '1000.00', 'retiro' => '500.00', 'esperado' => '500.00'],
            'retiro mínimo posible' => ['saldo' => '10.00', 'retiro' => '0.01', 'esperado' => '9.99'],
            'porcentaje aproximado' => ['saldo' => '0.03', 'retiro' => '0.01', 'esperado' => '0.02'],
        ];
    }

    #[Test]
    #[DataProvider('proveerRetirosExitosos')]
    public function retiro_valido_descuenta_saldo(string $saldo, string $retiro, string $esperado): void
    {
        $billetera = $this->crearBilletera($saldo);

        // WHEN: retiro válido.
        $billetera->retirar(Dinero::crear($retiro, Moneda::MXN), 'Retiro test');

        // THEN: el balance queda en el valor esperado.
        self::assertSame($esperado, $billetera->balance()->formateadoDecimal());
    }

    /**
     * Matriz de retiros que DEBEN fallar por fondos insuficientes.
     * Incluye el CASO BORDE: retiro exacto == saldo (igualdad prohibida).
     *
     * @return array<string, array{saldo: string, retiro: string}>
     */
    public static function proveerRetirosFallidos(): array
    {
        return [
            'retiro exacto (el 100% del saldo)' => ['saldo' => '100.00', 'retiro' => '100.00'],
            'ligeramente mayor' => ['saldo' => '100.00', 'retiro' => '100.01'],
            'el doble' => ['saldo' => '100.00', 'retiro' => '200.00'],
            'sin saldo' => ['saldo' => '0.00', 'retiro' => '1.00'],
        ];
    }

    #[Test]
    #[DataProvider('proveerRetirosFallidos')]
    public function retiro_sin_fondos_lanza_excepcion(string $saldo, string $retiro): void
    {
        $billetera = $this->crearBilletera($saldo);

        // EXPECT: cada caso con saldo insuficiente lanza FondosInsuficientes.
        $this->expectException(FondosInsuficientes::class);

        $billetera->retirar(Dinero::crear($retiro, Moneda::MXN), 'Retiro imposible');
    }

    /**
     * @return Billetera Billetera con saldo inicial
     */
    private function crearBilletera(string $saldo): Billetera
    {
        $billetera = new Billetera(
            id: 'wal-retiro',
            usuarioId: 'ana',
            moneda: Moneda::MXN,
        );

        if ($saldo !== '0.00') {
            $billetera->depositar(Dinero::crear($saldo, Moneda::MXN), 'Saldo inicial');
        }

        return $billetera;
    }
}
```

