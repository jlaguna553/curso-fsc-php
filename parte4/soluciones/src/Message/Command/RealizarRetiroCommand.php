<?php
// src/Message/Command/RealizarRetiroCommand.php — Command de CQRS
//
# Command: retirar dinero de una billetera.
# Es el espejo de RealizarDepositoCommand, con la misma estructura:
# DTO inmutable + factory crear() + clave de idempotencia.
#
# Este archivo es la SOLUCIÓN al ejercicio 3.1 (Commands y Queries).
# Se incluye aquí (Parte 4) porque el FeatureContext de Behat lo usa
# para probar los retiros en el flujo BDD completo.

declare(strict_types=1);

namespace App\Message\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Command: realizar un retiro de una billetera.
 */
final readonly class RealizarRetiroCommand
{
    /**
     * @param string $billeteraId   ID de la billetera origen
     * @param string $monto         Monto en formato decimal (ej: "1500.00")
     * @param string $moneda        Código ISO de moneda
     * @param string $descripcion   Descripción legible
     * @param string $idempotencyKey Clave única para evitar duplicados
     * @param string $commandId     ID del command (tracing)
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
        return $this->idempotencyKey !== ''
            ? $this->idempotencyKey
            : $this->commandId;
    }
}