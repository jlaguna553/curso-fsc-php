<?php
// src/Message/Event/BilleteraCreadaEvent.php — Event de dominio
//
# EVENT: Algo que YA OCURRIÓ en el sistema.
#
# Diferencia clave entre Command y Event:
#   - Command: "Haz esto" (intención, puede fallar)
#   - Event: "Esto pasó" (hecho consumado, no puede fallar)
#
# La arquitectura orientada a eventos (EDA):
#
#   ┌──────────────┐  publica   ┌─────────┐  consume  ┌──────────────────┐
#   │ wallet-service│ ─────────▶ │ RabbitMQ│ ────────▶ │ loan-service      │
#   │ (productor)  │  evento    │ (broker)│           │ (consumidor)     │
#   └──────────────┘            └─────────┘           └──────────────────┘
#
#   El productor NO sabe quién consume. Solo publica.
#   El consumidor NO sabe quién publicó. Solo recibe.
#   Esto desacopla los microservicios por completo.

declare(strict_types=1);

namespace App\Message\Event;

/**
 * Event: una billetera fue creada exitosamente.
 *
 * Este evento se publica en RabbitMQ para que OTROS servicios
 * reaccionen (notifications, audit, analytics).
 *
 * El event payload debe ser AUTOSUFICIENTE:
 *   - No debe requerir que el consumidor haga llamadas extra
 *   - Debe incluir todos los datos relevantes
 *   - El consumidor puede actuar sin consultar la BD del productor
 */
final readonly class BilleteraCreadaEvent
{
    /**
     * @param string      $billeteraId ID de la billetera creada
     * @param string      $usuarioId   ID del propietario
     * @param string      $moneda      Moneda de la billetera
     * @param string      $creadoEn    Timestamp ISO 8601 de creación
     * @param string      $eventId     ID único del evento (para deduplicación)
     * @param string      $eventType   Tipo del evento (versionamiento)
     * @param string      $version     Versión del schema del evento
     */
    public function __construct(
        public readonly string $billeteraId,
        public readonly string $usuarioId,
        public readonly string $moneda,
        public readonly string $creadoEn,
        public readonly string $eventId = '',
        public readonly string $eventType = 'billetera.creada',
        public readonly string $version = '1.0',
    ) {
    }

    /**
     * Convierte el evento a formato JSON (para RabbitMQ).
     *
     * @return string JSON string del evento
     */
    public function toJson(): string
    {
        // json_encode: convierte el array asociativo a JSON string.
        // JSON_THROW_ON_ERROR: si falla, lanza excepción (nunca falla silenciosamente).
        return json_encode([
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'version' => $this->version,
            'timestamp' => $this->creadoEn,
            'payload' => [
                'billetera_id' => $this->billeteraId,
                'usuario_id' => $this->usuarioId,
                'moneda' => $this->moneda,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}