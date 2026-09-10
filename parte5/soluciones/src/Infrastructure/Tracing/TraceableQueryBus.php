<?php
// src/Infrastructure/Tracing/TraceableQueryBus.php — Decorador del query bus
//
# Misma idea que TraceableCommandBus pero para LECTURAS.
# Separar command/query traceables mantiene el patrón CQRS explícito:
#   - ¿Cambio estado?  → TraceableCommandBus
#   - ¿Leo datos?      → TraceableQueryBus
#
# resource = FQCN de la query (ej: ObtenerBilleteraQuery)
# → puedes filtrar en Datadog: resource:ObtenerBilleteraQuery

declare(strict_types=1);

namespace App\Infrastructure\Tracing;

use App\Infrastructure\Bus\QueryBus;

/**
 * Envuelve el query bus con un span Datadog por lectura.
 *
 * Uso:
 *   $snapshot = $traceableBus->ask(new ObtenerBilleteraQuery($id));
 */
final class TraceableQueryBus
{
    public function __construct(private readonly QueryBus $inner)
    {
    }

    /**
     * Ejecuta la query dentro de un span Datadog.
     *
     * @param object $query La query de lectura
     *
     * @return mixed Snapshot de datos
     */
    public function ask(object $query): mixed
    {
        $span = DD\trace_start_span();
        $span->name = 'bus.dispatch.query';
        $span->resource = $query::class;
        $span->meta['query'] = $query::class;

        if (method_exists($query, 'billeteraId')) {
            $span->meta['billetera'] = (string) $query->billeteraId();
        }

        try {
            $resultado = $this->inner->ask($query);
            $span->meta['resultado'] = 'exito';

            return $resultado;
        } catch (\Throwable $e) {
            $span->meta['resultado'] = 'error';
            $span->meta['exception'] = $e->getMessage();
            throw $e;
        } finally {
            DD\trace_close_span();
        }
    }
}