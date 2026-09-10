<?php
// src/Infrastructure/Serializer/EventoSerializer.php — Serializador de eventos (contrato)
//
# ¿POR QUÉ un serializador custom?
#
# Por defecto, Symfony Messenger serializa los mensajes con el formato
# nativo de PHP (o JSON de su serializador interno) y los envuelve en un
# ENVELOPE con headers. El mensaje en la cola se ve así:
#
#   {"body":"O:36:\"App\\Message\\Event\\...\":{...}","headers":{"type":"App\\Message\\Event\\..."}}
#
# PROBLEMA: esto acopla el contrato de la cola a clases de PHP de Symfony.
# Un consumidor en Python, Go o (como en nuestro curso) PHP plano NO puede
# leer ese formato.
#
# SOLUCIÓN CONTRACT-FIRST: el cuerpo del mensaje en RabbitMQ debe ser JSON
# puro con un schema público y documentado:
#
#   {
#     "event_id": "uuid",
#     "event_type": "billetera.creada",
#     "version": "1.0",
#     "timestamp": "2026-09-10T12:00:00Z",
#     "payload": { ... }
#   }
#
# Así CUALQUIER tecnología puede consumir nuestros eventos. El transport
# es un detalle de implementación; el CONTRATO es el JSON.

declare(strict_types=1);

namespace App\Infrastructure\Serializer;

use App\Message\Event\BilleteraCreadaEvent;
use App\Message\Event\TransaccionCompletadaEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Convierte eventos de dominio a JSON público (encode) y viceversa (decode).
 */
final class EventoSerializer implements SerializerInterface
{
    /**
     * ENCODE: transport → RabbitMQ.
     *
     * Convierte el evento en el cuerpo JSON que viajará por la cola.
     *
     * @param Envelope $envelope El envelope que contiene el evento
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        // Los eventos exponen toJson(); cualquier otro mensaje es error.
        if (!$message instanceof BilleteraCreadaEvent
            && !$message instanceof TransaccionCompletadaEvent
        ) {
            throw new \LogicException(
                'EventoSerializer solo soporta eventos de dominio. '
                . 'Recibió: ' . $message::class
            );
        }

        // headers: metadata estándar que Symfony añade al envelope.
        // El TYPE permite que decode() (y otras herramientas) sepan qué es.
        return [
            'body' => $message->toJson(),
            'headers' => [
                'type' => $message->eventType,
                'Content-Type' => 'application/json',
            ],
        ];
    }

    /**
     * DECODE: RabbitMQ → objeto PHP.
     *
     * Se usa cuando el WALLET-SERVICE consume sus propios eventos
     * (procesos internos como auditoría). El loan-service NO usa
     * este método: parsea el JSON directamente con php-amqplib.
     *
     * @param array<string, mixed> $encodedChunk El {"body","headers"} recibido
     *
     * @return Envelope Envelope con el evento reconstruido
     */
    public function decode(array $encodedChunk): Envelope
    {
        $body = json_decode($encodedChunk['body'], true, 512, JSON_THROW_ON_ERROR);
        $payload = $body['payload'] ?? [];

        // Reconstruir la clase según event_type (contrato versionado).
        return match ($body['event_type'] ?? '') {
            'billetera.creada' => new Envelope(
                new BilleteraCreadaEvent(
                    billeteraId: $payload['billetera_id'],
                    usuarioId: $payload['usuario_id'],
                    moneda: $payload['moneda'],
                    creadoEn: $body['timestamp'] ?? '',
                    eventId: $body['event_id'] ?? '',
                ),
            ),
            'transaccion.completada' => new Envelope(
                new TransaccionCompletadaEvent(
                    transaccionId: $payload['transaccion_id'],
                    billeteraId: $payload['billetera_id'],
                    tipo: $payload['tipo'],
                    monto: $payload['monto'],
                    moneda: $payload['moneda'],
                    balanceNuevo: $payload['balance_nuevo'],
                    timestamp: $body['timestamp'] ?? '',
                ),
            ),
            default => throw new \LogicException(
                'Tipo de evento desconocido: ' . ($body['event_type'] ?? 'null')
            ),
        };
    }
}