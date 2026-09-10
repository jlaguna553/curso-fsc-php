<?php
// src/Application/CommandHandler/CrearBilleteraCommandHandler.php — Use case handler
//
# El HANDLER de un Command es el USE CASE en su forma más pura:
# recibe un DTO (Command), ejecuta la lógica de aplicación y NO devuelve
# nada relacionado con HTTP.
#
# Flujo completo:
#   HTTP POST /api/v1/billeteras
#     → BilleteraController
#     → commandBus->dispatch(new CrearBilleteraCommand(...))   [CommandBus facade]
#     → command.bus (middlewares: validation, doctrine_transaction)
#     → CrearBilleteraCommandHandler::__invoke($command)
#     → retorna ID de la billetera (string)
#
# Reglas de capa:
#   - Solo usa puertos (interfaces), nunca implementaciones concretas
#   - Orquesta el dominio (Billetera), no duplica su lógica
#   - Publica eventos de dominio SIEMPRE después del flush

declare(strict_types=1);

namespace App\Application\CommandHandler;

use App\Domain\Billetera;
use App\Domain\Exception\MonedaNoSoportada;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Message\Command\CrearBilleteraCommand;
use App\Message\Event\BilleteraCreadaEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Maneja el comando "crear una billetera".
 *
 * La convención `__invoke` es la estándar de Symfony Messenger:
 * el bus llama al handler como si fuera una función.
 */
final readonly class CrearBilleteraCommandHandler
{
    /**
     * @param BilleteraRepository   $billeteras Puerto de persistencia (inyectado por interfaz)
     * @param MessageBusInterface   $eventBus   Bus de eventos (autowire: event.bus)
     */
    public function __construct(
        private BilleteraRepository $billeteras,
        private MessageBusInterface $eventBus,
    ) {
    }

    /**
     * Ejecuta el caso de uso.
     *
     * @param CrearBilleteraCommand $command El comando recibido del bus
     *
     * @return string ID UUID de la billetera creada
     *
     * @throws MonedaNoSoportada Si el código de moneda no existe en el sistema
     */
    public function __invoke(CrearBilleteraCommand $command): string
    {
        // 1. Convertir el código ISO del command en el enum de dominio.
        //    desdeCodigo() lanza \ValueError si no existe; lo traducimos
        //    a la excepción de dominio con el mensaje amigable.
        $moneda = self::monedaDesdeCodigo($command->moneda);

        // 2. Delegar la creación ENTERA al agregado.
        //    La Billetera es quien sabe cómo crearse a sí misma:
        //    inicia movimientos vacíos, registra timestamp.
        //    (Constructor público del dominio - Parte 1)
        $billetera = new Billetera(
            id: (string) Uuid::v4(),
            usuarioId: $command->usuarioId,
            moneda: $moneda,
        );

        // 3. Persistir a través del PUERTO (no sabemos si es Doctrine/Mongo).
        $this->billeteras->save($billetera);

        // 4. Publicar el evento de dominio DESPUÉS del flush.
        //    "Billetera creada" es un hecho consumado → va a RabbitMQ.
        //    Los consumidores (loan-service, notifications, audit) reaccionan solos.
        $evento = new BilleteraCreadaEvent(
            billeteraId: $billetera->id,
            usuarioId: $billetera->usuarioId,
            moneda: $billetera->moneda->value,
            creadoEn: $billetera->creadoEn->format('Y-m-d\TH:i:s\Z'),
            eventId: (string) Uuid::v4(),
        );

        // dispatch sobre event.bus → routing lo envía al transporte RabbitMQ
        $this->eventBus->dispatch($evento);

        // 5. Retornar el ID para que el controller responda 201 Created.
        return $billetera->id;
    }

    /**
     * Traduce \ValueError (nativo de enums) a MonedaNoSoportada (dominio).
     *
     * @param string $codigo Código ISO de moneda (ej: "MXN")
     *
     * @return Moneda Instancia del enum
     *
     * @throws MonedaNoSoportada Si el código no es soportado
     */
    private static function monedaDesdeCodigo(string $codigo): Moneda
    {
        $moneda = Moneda::tryFrom(strtoupper($codigo));

        if ($moneda === null) {
            throw new MonedaNoSoportada(
                codigoMoneda: $codigo,
                monedasSoportadas: array_column(Moneda::cases(), 'value'),
            );
        }

        return $moneda;
    }
}