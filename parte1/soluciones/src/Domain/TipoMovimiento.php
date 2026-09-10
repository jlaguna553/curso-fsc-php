<?php
// src/Domain/TipoMovimiento.php — Enum de tipos de movimiento financiero
//
// Este enum categoriza las transacciones en la billetera.
// Cada tipo tiene reglas de negocio diferentes:
//   - Depósito: incrementa el saldo
//   - Retiro: decrementa el saldo (verificar fondos)
//   - Transferencia: decrementa origen, incrementa destino (próximamente)

declare(strict_types=1);

namespace App\Domain;

/**
 * Tipos de movimiento que puede tener una billetera.
 *
 * Cada caso define si el movimiento afecta positiva o negativamente
 * el saldo de la billetera.
 */
enum TipoMovimiento: string
{
    /**
     * Depósito: incrementa el saldo de la billetera.
     * Ejemplo: transferencia bancaria recibida, depósito en efectivo.
     */
    case DEPOSITO = 'deposito';

    /**
     * Retiro: decrementa el saldo de la billetera.
     * Ejemplo: retiro en cajero, pago a comercio.
     */
    case RETIRO = 'retiro';

    /**
     * Retorna si este tipo de movimiento incrementa el saldo.
     *
     * @return bool true si el movimiento suma dinero a la billetera
     */
    public function esAcreditacion(): bool
    {
        // match como expresión: retorna el valor del caso coincidente.
        // Solo DEPOSITO es acreditación. RETIRO es débito.
        return match ($this) {
            self::DEPOSITO => true,
            self::RETIRO => false,
        };
    }

    /**
     * Retorna la etiqueta legible del tipo de movimiento.
     *
     * @return string Nombre en español para interfaces de usuario
     */
    public function etiqueta(): string
    {
        return match ($this) {
            self::DEPOSITO => 'Depósito',
            self::RETIRO => 'Retiro',
        };
    }
}
