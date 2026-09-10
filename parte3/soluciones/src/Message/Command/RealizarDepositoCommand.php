<?php
// src/Message/Command/RealizarDepositoCommand.php — Command de CQRS
//
# Command: registrar un depósito en una billetera.

declare(strict_types=1);

namespace App\Message\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Command: realizar un depósito en una billetera.
 *
 * Este command lleva TODA la información necesaria para procesar el depósito:
 *   - Qué billetera (id)
 *   - Cuánto (monto)
 *   - En qué moneda
 *   - Idempotencia (idempotencyKey)
 */
final readonly class RealizarDepositoCommand
{
    /**
     * @param string      $billeteraId   ID de la billetera destino
     * @param string      $monto         Monto en formato decimal (ej: "1500.00")
     * @param string      $moneda        Código ISO de moneda
     * @param string      $descripcion   Descripción legible
     * @param string      $idempotencyKey Clave única para evitar duplicados
     * @param string      $commandId     ID del command (tracing)
     */
    public function __construct(
        public readonly string $billeteraId,
        public readonly string $monto,
        public readonly string $moneda,
        public readonly string $descripcion,
        public readonly string $idempotencyKey = '',
        public readonly string $commandId = '',
    ) {
    }

    /**
     * Factory method con generación automática de claves.
     *
     * @param string $idempotencyKey Clave de idempotencia del cliente
     * @return self Command listo para despachar
     */
    public static function crear(
        string $billeteraId,
        string $monto,
        string $moneda,
        string $descripcion,
        string $idempotencyKey,
    ): self {
        return new self(
            billeteraId: $billeteraId,
            monto: $monto,
            moneda: $moneda,
            descripcion: $descripcion,
            idempotencyKey: $idempotencyKey,
            commandId: (string) Uuid::v4(),
        );
    }

    /**
     * Genera una clave de idempotencia automática si el cliente no la provee.
     *
     * @return string Clave de idempotencia
     */
    public function claveUnica(): string
    {
        // Si el cliente no envió idempotency_key, generamos una
        // basada en el commandId (siempre es único).
        return $this->idempotencyKey !== ''
            ? $this->idempotencyKey
            : $this->commandId;
    }
}