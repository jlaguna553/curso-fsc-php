<?php
// src/Message/Command/CrearBilleteraCommand.php — Command de CQRS
//
# COMMAND: Representa una INTENCIÓN de cambiar el estado del sistema.
#
# Diferencia clave entre Command y Query:
#   - Command: "Haz algo" → Cambia el estado → Retorna SOLO confirmación (void o ID)
#   - Query:   "Dame datos" → NO cambia el estado → Retorna datos
#
# Ejemplos de Commands en PrestaFlow:
#   - CrearBilleteraCommand
#   - RealizarDepositoCommand
#   - RealizarRetiroCommand
#   - AprobarPrestamoCommand
#
# ¿Por qué Commands y Queries separados?
#   1. CQRS (Command Query Responsibility Segregation)
#   2. Optimización: el query side puede usar una BD leída optimizada
#   3. Escalabilidad: el command side puede escalar horizontalmente
#   4. Historial: los commands se pueden re-ejecutar (event sourcing)
#
# IMPORTANTE: Un Command es un DTO (Data Transfer Object).
# - Es inmutable (readonly)
# - No contiene lógica
# - Solo lleva datos + metadata
# - Es serializable (se envía a través de RabbitMQ)

declare(strict_types=1);

namespace App\Message\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Command: crear una billetera nueva.
 *
 * Este mensaje se envía al Message Bus (Dispatcher).
 * El bus lo enruta al CommandHandler correspondiente.
 *
 * Ejemplo de flujo:
 *   POST /api/v1/billeteras
 *     → BilleteraController
 *     → messageBus->dispatch(new CrearBilleteraCommand(...))
 *     → MessageBus enruta a CrearBilleteraHandler
 *     → Handler ejecuta: validar moneda + crear + persistir
 *     → Retorna el ID de la billetera creada
 */
final readonly class CrearBilleteraCommand
{
    /**
     * @param string $usuarioId ID del propietario de la billetera
     * @param string $moneda    Código ISO de moneda (MXN, USD, EUR)
     * @param string $commandId ID único del command (para idempotencia/tracing)
     */
    public function __construct(
        public string $usuarioId,
        public string $moneda,
        public string $commandId = '',
    ) {
        // Si no se pasó commandId, generamos uno automáticamente.
        // Cómo asignar un default en un readonly constructor:
        // Esta es una técnica de "constructor promotion con default dinámico".
    }

    /**
     * Factory method: crea un command con commandId automático.
     *
     * @return self Command con commandId UUID v4
     */
    public static function crear(string $usuarioId, string $moneda): self
    {
        return new self(
            usuarioId: $usuarioId,
            moneda: $moneda,
            commandId: (string) Uuid::v4(),
        );
    }
}