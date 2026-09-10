<?php
// src/Domain/Exception/MonedaNoSoportada.php — Excepción de dominio
//
// Se lanza cuando se intenta usar una moneda que PrestaFlow no soporta.
// Ejemplo: someone intenta depositar en Bitcoin cuando solo soportamos fiat.

declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * Excepción lanzada al intentar operar con una moneda no soportada.
 */
final class MonedaNoSoportada extends \DomainException
{
    /**
     * @param string $codigoMoneda Código de moneda intentada (ej: "BTC")
     * @param array<string> $monedasSoportadas Lista de monedas válidas
     */
    public function __construct(
        public readonly string $codigoMoneda,
        public readonly array $monedasSoportadas,
    ) {
        // Implantamos la lista de monedas soportadas en el mensaje
        // para que el desarrollador sepa inmediatamente qué valores usar.
        $lista = implode(', ', $this->monedasSoportadas);

        parent::__construct(
            "Moneda no soportada: '{$codigoMoneda}'. "
            . "Monedas disponibles: [{$lista}]"
        );
    }
}
