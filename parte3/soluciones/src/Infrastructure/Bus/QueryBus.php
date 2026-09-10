<?php
// src/Infrastructure/Bus/QueryBus.php — Fachada del query bus con HandleTrait
//
# Misma técnica que CommandBus, para preguntas de solo lectura.
# Separar estas dos fachadas hace EXPLÍCITO el patrón CQRS en el código:
#   - ¿Quiero cambiar estado?  → inyecto CommandBus
#   - ¿Quiero leer datos?      → inyecto QueryBus
#
# Los controllers ya no dependen de "el bus mágico" sino de la fachada
# que comunica su intención en el propio nombre del parámetro.

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fachada de uso cómodo para el bus de queries.
 *
 * Uso:
 *   $datos = $queryBus->ask(new ObtenerBilleteraQuery($id));
 */
final class QueryBus
{
    use HandleTrait;

    /**
     * @param MessageBusInterface $queryBus El bus "query.bus" (autowire por nombre)
     */
    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    /**
     * Ejecuta la query y retorna el resultado del handler.
     *
     * @param object $query La query de lectura
     *
     * @return mixed Snapshot de datos (array, scalar...)
     */
    public function ask(object $query): mixed
    {
        return $this->handle($query);
    }
}