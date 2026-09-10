<?php
// loan-service/src/Domain/ResultadoElegibilidad.php — Value Object de salida
//
# El worker no devuelve "sí/no"; devuelve un VALOR DE DOMINIO con:
#   - Un puntaje (score 0-100)
#   - Una recomendación (APROBADO / EN_REVISION / NO_ELEGIBLE)
#   - Una razón legible (para apoyo al negocio)
#
# Inmutable y auto-documentado: imposible crear un resultado inconsistente.

declare(strict_types=1);

namespace LoanService\Domain;

/**
 * Resultado de la evaluación de elegibilidad para un préstamo.
 */
final readonly class ResultadoElegibilidad
{
    public const APROBADO = 'APROBADO';
    public const EN_REVISION = 'EN_REVISION';
    public const NO_ELEGIBLE = 'NO_ELEGIBLE';

    /**
     * @param int    $puntaje        Puntaje calculado (0-100)
     * @param string $recomendacion  Una de las constantes APROBADO/EN_REVISION/NO_ELEGIBLE
     * @param string $razon          Explicación legible del resultado
     */
    public function __construct(
        public readonly int $puntaje,
        public readonly string $recomendacion,
        public readonly string $razon,
    ) {
        // Validación de invariantes: el puntaje vive en el rango 0-100.
        if ($puntaje < 0 || $puntaje > 100) {
            throw new \InvalidArgumentException(
                "Puntaje fuera de rango: {$puntaje}. Debe estar entre 0 y 100."
            );
        }

        // La recomendación solo puede tomar los 3 valores definidos.
        $validas = [self::APROBADO, self::EN_REVISION, self::NO_ELEGIBLE];
        if (!in_array($recomendacion, $validas, true)) {
            throw new \InvalidArgumentException(
                "Recomendación inválida: '{$recomendacion}'."
            );
        }
    }

    /**
     * ¿El préstamo fue aprobado automáticamente?
     *
     * @return bool true si la recomendación es APROBADO
     */
    public function fueAprobado(): bool
    {
        return $this->recomendacion === self::APROBADO;
    }

    /**
     * Representación JSON del resultado (lo que guarda el worker).
     *
     * @return array<string, string|int> Array serializable
     */
    public function toArray(): array
    {
        return [
            'puntaje' => $this->puntaje,
            'recomendacion' => $this->recomendacion,
            'razon' => $this->razon,
        ];
    }
}