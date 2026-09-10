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