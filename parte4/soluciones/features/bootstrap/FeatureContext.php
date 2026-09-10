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