<?php
// src/Message/Event/TransaccionCompletadaEvent.php — Event de dominio
//
# Evento publicado DESPUÉS de que una transacción se completó exitosamente.
# Este es el evento más importante del sistema: dispara notificaciones,
# auditoría, reconciliación, y análisis.

declare(strict_types=1);

namespace App\Message\Event;

/**
 * Event: una transacción financiera fue completada con éxito.
 *
 * Este evento propaga el hecho a todo el ecosistema:
 *   - Notification Service: envía Email/SMS al usuario
 *   - Audit Service: registra la transacción para auditoría
 *   - Loan Service: actualiza elegibilidad de préstamos
 *   - Analytics: métricas de uso
 */
final readonly class TransaccionCompletadaEvent
{
    /**
     * @param string $transaccionId ID de la transacción
     * @param string $billeteraId   ID de la billetera
     * @param string $tipo          Tipo: "deposito" o "retiro"
     * @param string $monto         Monto en formato decimal
     * @param string $moneda        Código ISO de moneda
     * @param string $balanceNuevo  Balance posterior a la transacción
     * @param string $timestamp     Timestamp ISO 8601
     * @param string $eventType     Tipo del evento (para versionamiento)
     */
    public function __construct(
        public readonly string $transaccionId,
        public readonly string $billeteraId,
        public readonly string $tipo,
        public readonly string $monto,
        public readonly string $moneda,
        public readonly string $balanceNuevo,
        public readonly string $timestamp,
        public readonly string $eventType = 'transaccion.completada',
    ) {
    }

    /**
     * Convierte el evento a JSON para RabbitMQ.
     *
     * @return string JSON string
     */
    public function toJson(): string
    {
        return json_encode([
            'event_type' => $this->eventType,
            'timestamp' => $this->timestamp,
            'payload' => [
                'transaccion_id' => $this->transaccionId,
                'billetera_id' => $this->billeteraId,
                'tipo' => $this->tipo,
                'monto' => $this->monto,
                'moneda' => $this->moneda,
                'balance_nuevo' => $this->balanceNuevo,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}