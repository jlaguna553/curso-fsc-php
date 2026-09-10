<?php
// src/Domain/Movimiento.php — Value Object que representa un movimiento financiero
//
// MOVIMIENTO: Registro inmutable de una transacción en la billetera.
// Cada movimiento es un evento financiero con:
//   - Un monto (Dinero)
//   - Un tipo (depósito o retiro)
//   - Un timestamp (cuándo ocurrió)
//   - Una descripción (para el usuario)
//
// ¿Por qué es un Value Object y no una Entity?
// Porque dos movimientos con los mismos datos son intercambiables.
// No necesitamos un ID para distinguirlos (aunque sí lo almacenamos para referencia).

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Registro inmutable de un movimiento financiero.
 *
 * Design patterns aplicados:
 *   - Value Object: identificado por sus valores, no por ID
 *   - Immutable: readonly properties + constructor privado
 *   - Self-Documenting: cada propiedad tiene un tipo explícito
 */
final readonly class Movimiento
{
    /**
     * @param string          $id          ID único del movimiento (UUID)
     * @param Dinero          $monto       Monto del movimiento
     * @param TipoMovimiento  $tipo        Tipo (depósito o retiro)
     * @param string          $descripcion Descripción legible para el usuario
     * @param DateTimeImmutable $creadoEn  Timestamp de creación
     */
    public function __construct(
        public string $id,
        public Dinero $monto,
        public TipoMovimiento $tipo,
        public string $descripcion,
        public DateTimeImmutable $creadoEn,
    ) {
        // Constructor público: el controller/service crea instancias directamente.
        // La validación de negocio ocurre en Billetera::depositar() y ::retirar(),
        // no en el constructor de Movimiento (que es un simple registro).
    }

    /**
     * Crea un movimiento de depósito.
     *
     * Factory method que simplifica la creación de depósitos.
     * El tipo se establece automáticamente como DEPOSITO.
     *
     * @param string $id          ID único (UUID)
     * @param Dinero $monto       Monto a depositar
     * @param string $descripcion Descripción del depósito
     *
     * @return self Nuevo movimiento de depósito
     */
    public static function deposito(
        string $id,
        Dinero $monto,
        string $descripcion,
    ): self {
        return new self(
            id: $id,
            monto: $monto,
            tipo: TipoMovimiento::DEPOSITO,
            descripcion: $descripcion,
            // immutable(): retorna DateTimeImmutable con la fecha/hora actual.
            // Usamos immutable en lugar de new DateTimeImmutable() para brevedad.
            // DateTimeImmutable es preferido sobre DateTime porque:
            //   1. Los métodos modificadores retornan NUEVO objeto
            //   2. No se puede modificar accidentalmente después de creado
            //   3. Seguro para concurrent access (sin bloqueos)
            creadoEn: new DateTimeImmutable(),
        );
    }

    /**
     * Crea un movimiento de retiro.
     *
     * Factory method que simplifica la creación de retiros.
     *
     * @param string $id          ID único (UUID)
     * @param Dinero $monto       Monto a retirar
     * @param string $descripcion Descripción del retiro
     *
     * @return self Nuevo movimiento de retiro
     */
    public static function retiro(
        string $id,
        Dinero $monto,
        string $descripcion,
    ): self {
        return new self(
            id: $id,
            monto: $monto,
            tipo: TipoMovimiento::RETIRO,
            descripcion: $descripcion,
            creadoEn: new DateTimeImmutable(),
        );
    }
}
