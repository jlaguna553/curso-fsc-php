<?php
// loan-service/src/Application/EvaluadorElegibilidad.php — Regla de negocio del worker
//
# EL worker es un microservicio INDEPENDIENTE. Su única regla de negocio:
# dado el evento "transaccion.completada", calcular si el usuario es
# elegible para un préstamo.
#
# Esta clase es PURO PHP: no depende de amqplib ni de PDO. Se puede
# testear sin colas ni base de datos (TDD puro).
#
# Modelo de puntaje (simplificado para el curso):
#   +30 pts  si el depósito es >= 1000 en la moneda local
#   +10 pts  si el depósito es < 1000 pero > 0
#   +40 pts  si el balance resultante >= 5000
#   +20 pts  si el balance resultante >= 1000 pero < 5000
#   +15 pts  si la moneda es MXN (moneda base de PrestaFlow)
#   -30 pts  si es un retiro (señal de liquidez negativa)
#
# Umbrales:
#   >= 70 → APROBADO
#   50-69 → EN_REVISION (requiere intervención humana)
#   < 50  → NO_ELEGIBLE

declare(strict_types=1);

namespace LoanService\Application;

use LoanService\Domain\ResultadoElegibilidad;

/**
 * Motor de puntaje para elegibilidad de préstamos.
 */
final class EvaluadorElegibilidad
{
    private const DEPOSITO_ALTO = 1000.0;      // umbral depósito "fuerte"
    private const BALANCE_ALTO = 5000.0;        // umbral balance "sólido"
    private const BALANCE_MEDIO = 1000.0;       // umbral balance "aceptable"
    private const UMBRAL_APROBADO = 70;         // puntaje para aprobar
    private const UMBRAL_REVISION = 50;         // puntaje mínimo de revisión

    /**
     * Evalúa un evento de transacción (formato JSON del contrato).
     *
     * @param array<string, mixed> $evento Evento decodificado de la cola
     *                                     (ver contrato en lección 3.2)
     *
     * @return ResultadoElegibilidad Resultado con puntaje y recomendación
     */
    public function evaluar(array $evento): ResultadoElegibilidad
    {
        // Extraer el payload con fallbacks seguros.
        // Nunca asumimos que el evento trae todos los campos.
        $payload = $evento['payload'] ?? $evento;
        $tipo = (string) ($payload['tipo'] ?? 'desconocido');
        $monto = (float) ($payload['monto'] ?? 0.0);
        $balance = (float) ($payload['balance_nuevo'] ?? 0.0);
        $moneda = strtoupper((string) ($payload['moneda'] ?? ''));

        // Puntaje base: comienza en 0 y se acumula con reglas.
        $puntaje = 0;

        // REGLA 1: tamaño del depósito.
        if ($tipo === 'deposito' && $monto >= self::DEPOSITO_ALTO) {
            $puntaje += 30;
        } elseif ($tipo === 'deposito') {
            $puntaje += 10;
        }

        // REGLA 2: balance resultante (salud financiera).
        if ($balance >= self::BALANCE_ALTO) {
            $puntaje += 40;
        } elseif ($balance >= self::BALANCE_MEDIO) {
            $puntaje += 20;
        }

        // REGLA 3: moneda base (incentivo a operar en MXN).
        if ($moneda === 'MXN') {
            $puntaje += 15;
        }

        // REGLA 4: los retiros son señal negativa de liquidez.
        if ($tipo === 'retiro') {
            $puntaje -= 30;
        }

        // Asegurar límites inferior (0) y superior (100).
        $puntaje = max(0, min(100, $puntaje));

        // Clasificar según umbrales.
        if ($puntaje >= self::UMBRAL_APROBADO) {
            $recomendacion = ResultadoElegibilidad::APROBADO;
            $razon = "Puntaje {$puntaje}: perfil sólido, aprobación automática.";
        } elseif ($puntaje >= self::UMBRAL_REVISION) {
            $recomendacion = ResultadoElegibilidad::EN_REVISION;
            $razon = "Puntaje {$puntaje}: requiere revisión manual del analista.";
        } else {
            $recomendacion = ResultadoElegibilidad::NO_ELEGIBLE;
            $razon = "Puntaje {$puntaje}: no cumple el perfil mínimo.";
        }

        return new ResultadoElegibilidad(
            puntaje: $puntaje,
            recomendacion: $recomendacion,
            razon: $razon,
        );
    }
}