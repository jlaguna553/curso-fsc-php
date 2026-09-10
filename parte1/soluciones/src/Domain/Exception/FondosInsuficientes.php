<?php
// src/Domain/Exception/FondosInsuficientes.php — Excepción de dominio
//
// EXCEPCIÓN DE DOMINIO: Error que representa una violación de una regla de negocio.
//
// ¿Por qué no usar \RuntimeException o \InvalidArgumentException genéricas?
// Porque en un sistema fintech, necesitamos distinguir:
//   - Error de validación (campo faltante) → InvalidArgumentException
//   - Fondos insuficientes (regla de negocio) → FondosInsuficientes
//   - Error técnico (base de datos caída) → RuntimeException
//
// Cada tipo de excepción se maneja diferente:
//   - FondosInsuficientes → 422 Unprocessable Entity + notificación al usuario
//   - RuntimeException → 500 Internal Server Error + alerta a DevOps
//
// Las excepciones de dominio NO deben contener lógica de presentación.
// Solo datos relevantes para que el manejador de errores tome decisiones.

declare(strict_types=1);

namespace App\Domain\Exception;

use App\Domain\Dinero;

/**
 * Se lanza cuando se intenta debitar un monto mayor al saldo disponible.
 *
 * Carry datos del contexto para que el controller/service pueda:
 *   1. Retornar una respuesta HTTP descriptiva (422)
 *   2. Loggear el intento fallido
 *   3. Notificar al usuario del saldo disponible
 */
final class FondosInsuficientes extends \DomainException
{
    /**
     * @param Dinero $saldoActual     Saldo actual de la billetera
     * @param Dinero $montoSolicitado Monto que se intentó debitar
     */
    public function __construct(
        public readonly Dinero $saldoActual,
        public readonly Dinero $montoSolicitado,
    ) {
        // Calcular el déficit para incluirlo en el mensaje de error.
        // Esto ayuda al desarrollador a entender qué pasó sin tener que
        // inspeccionar el debugger.
        $deficit = $montoSolicitado->centavos() - $saldoActual->centavos();

        // El mensaje de la excepción padre (\DomainException) es el que
        // aparece en logs y mensajes de error. Debe ser descriptivo.
        parent::__construct(
            "Fondos insuficientes: solicitado {$montoSolicitado}, "
            . "disponible {$saldoActual}. "
            . "Déficit: {$deficit} centavos."
        );
    }

    /**
     * Calcula el déficit exacto entre lo solicitado y lo disponible.
     *
     * @return Dinero Diferencia que falta para completar la transacción
     */
    public function deficit(): Dinero
    {
        // Crear un Dinero con la diferencia en centavos.
        // Usamos la misma moneda que el monto solicitado.
        return Dinero::desdeCentavos(
            $this->montoSolicitado->centavos() - $this->saldoActual->centavos(),
            $this->montoSolicitado->moneda(),
        );
    }
}
