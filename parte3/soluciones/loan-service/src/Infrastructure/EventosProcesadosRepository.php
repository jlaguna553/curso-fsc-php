<?php
// loan-service/src/Infrastructure/EventosProcesadosRepository.php — Idempotencia del worker
//
# At-least-once delivery: RabbitMQ puede ENTREGAR el mismo evento dos veces
# (reconexiones, reintentos, fallos de ack). Si el worker procesara dos
# veces, duplicaría la evaluación. La tabla `eventos_procesados` es la
# memoria del worker contra duplicados.
#
# Clave de deduplicación: event_id (o transaccion_id como fallback).
# La UNIQUE CONSTRAINT en la BD es la garantía final (no solo el código).

declare(strict_types=1);

namespace LoanService\Infrastructure;

use PDO;

/**
 * Registro de eventos ya procesados (idempotencia del consumidor).
 */
final class EventosProcesadosRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * ¿Este evento ya fue procesado?
     *
     * @param string $eventId ID único del evento
     *
     * @return bool true si ya está registrado
     */
    public function existe(string $eventId): bool
    {
        // Prepared statement: parámetro :eventId previene SQL injection.
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM eventos_procesados WHERE event_id = :eventId'
        );
        $stmt->execute(['eventId' => $eventId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Registra un evento como procesado (junto con su resultado).
     *
     * @param string $eventId        ID del evento
     * @param int    $puntaje        Puntaje calculado
     * @param string $recomendacion  Recomendación (APROBADO/EN_REVISION/NO_ELEGIBLE)
     */
    public function registrar(string $eventId, int $puntaje, string $recomendacion): void
    {
        // INSERT ... ON CONFLICT DO NOTHING: si otro worker (o un reintento)
        // ya insertó esta clave, esta ejecución es un no-op.
        $stmt = $this->pdo->prepare(
            'INSERT INTO eventos_procesados (event_id, puntaje, recomendacion, procesado_en)
             VALUES (:eventId, :puntaje, :recomendacion, NOW())
             ON CONFLICT (event_id) DO NOTHING'
        );
        $stmt->execute([
            'eventId' => $eventId,
            'puntaje' => $puntaje,
            'recomendacion' => $recomendacion,
        ]);
    }
}