<?php
// src/Infrastructure/Bus/CommandBus.php — Fachada del command bus con HandleTrait
//
# PROBLEMA: MessageBus::dispatch() retorna siempre un Envelope, no el
# resultado del handler. El controller tendría que extraer el HandledStamp.
#
# SOLUCIÓN: Implementamos una fachada con HandleTrait. Symfony incluye
# este trait justamente para este caso:
#
#   HandleTrait::handle($message)
#     → dispatch($message)
#     → espera el HandledStamp que deja HandleMessageMiddleware
#     → retorna el VALOR DE RETORNO del handler (string, array, ...)
#
# Así el controller puede hacer:
#   $id = $commandBus->dispatch(new CrearBilleteraCommand(...));
# y recibe la ID directamente, sin desglosar envelopes.

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fachada de uso cómodo para el bus de comandos.
 *
 * Uso:
 *   $id = $commandBus->dispatch(new CrearBilleteraCommand(...));
 */
final class CommandBus
{
    use HandleTrait;

    /**
     * @param MessageBusInterface $commandBus El bus "command.bus" (autowire por nombre)
     */
    public function __construct(MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;
    }

    /**
     * Despacha el command y retorna el valor del handler.
     *
     * @param object $command El command a ejecutar
     *
     * @return mixed Resultado del handler (string, array, void...)
     */
    public function dispatch(object $command): mixed
    {
        return $this->handle($command);
    }
}