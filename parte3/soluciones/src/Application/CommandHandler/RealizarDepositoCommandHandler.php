<?php
// src/Application/CommandHandler/RealizarDepositoCommandHandler.php — Use case handler
//
# Este handler muestra las 3 responsabilidades de un use case financiero:
#   1. IDEMPOTENCIA → verificar que el comando no se haya procesado antes
#   2. LÓGICA      → delegar en el agregado (depositar)
#   3. PROPAGACIÓN → publicar el evento de dominio a la cola
#
# Orden crítico de operaciones:
#   a. Verificar idempotencia ANTES de tocar la billetera (fail fast)
#   b. Persistir y luego publicar el evento (el evento lleva el balance FINAL)

declare(strict_types=1);

namespace App\Application\CommandHandler;

use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Domain\Repository\IdempotenciaRepository;
use App\Message\Command\RealizarDepositoCommand;
use App\Message\Event\TransaccionCompletadaEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Maneja el comando "realizar un depósito".
 *
 * Retorna el balance NUEVO como string decimal ("1550.00") para que
 * la respuesta HTTP sea útil de inmediato (sin query adicional).
 */
final readonly class RealizarDepositoCommandHandler
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
     * Ejecuta el depósito de forma idempotente.
     *
     * @param RealizarDepositoCommand $command El comando del bus
     *
     * @return string Balance nuevo en formato decimal ("1550.00")
     *
     * @throws BilleteraNoEncontrada Si la billetera no existe
     */
    public function __invoke(RealizarDepositoCommand $command): string
    {
        // 1. CLAVE DE IDEMPOTENCIA: única por operación de negocio.
        //    Si el cliente reenvía el mismo comando (timeout, reintento),
        //    la clave se repite → detectamos y NO duplicamos el depósito.
        $clave = $command->claveUnica();

        // 2. Localizar la billetera.
        $billetera = $this->billeteras->findById($command->billeteraId);

        // 2b. Si no existe, el caso de uso no puede continuar.
        if ($billetera === null) {
            throw new BilleteraNoEncontrada($command->billeteraId);
        }

        // 3. PASO IDEMPOTENTE: si esta clave ya se procesó, retornamos
        //    el balance actual SIN ejecutar nada. Así el cliente obtiene
        //    exactamente la misma respuesta que la primera vez.
        if ($this->idempotencia->existe($clave)) {
            return $billetera->balance()->formateadoDecimal();
        }

        // 4. Construir el Value Object Dinero (valida formato y negatividad).
        //    La moneda la tomamos del command; depositar() verifica que
        //    coincida con la moneda de la billetera (regla de dominio).
        $moneda = Moneda::desdeCodigo($command->moneda);
        $dinero = Dinero::crear($command->monto, $moneda);

        // 5. Delegar la mutación al agregado.
        //    Billetera::depositar() valida: monto > 0, misma moneda,
        //    y registra el movimiento en su historial.
        $movimiento = $billetera->depositar($dinero, $command->descripcion);

        // 6. Persistir el nuevo estado (INSERT movimiento + UPDATE billetera).
        $this->billeteras->save($billetera);

        // 7. Registrar la clave como procesada (después del flush exitoso).
        //    Si el flush fallara, la clave NO se marca → reintento posible.
        $this->idempotencia->registrar($clave);

        // 8. Publicar el evento de dominio. Todo el ecosistema se entera:
        //    loan-service actualiza elegibilidad, auditoría registra,
        //    analytics mide. El wallet-service no conoce a ninguno.
        $balanceNuevo = $billetera->balance();
        $this->eventBus->dispatch(
            new TransaccionCompletadaEvent(
                transaccionId: $movimiento->id,
                billeteraId: $billetera->id,
                tipo: 'deposito',
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