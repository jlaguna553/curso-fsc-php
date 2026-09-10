<?php
// loan-service/src/Consumer/ConsumidorTransacciones.php — Bucle de consumo AMQP
//
# ESTE ES EL CORAZÓN DEL WORKER: un proceso que vive para consumir.
# Se conecta a RabbitMQ, escucha la cola `loan_events` y procesa
# cada mensaje con confirmación explícita (ack/nack).
#
# Flujo del mensaje:
#   wallet-service publica → exchange "prestaflow" (topic)
#     → routing key "wallet.event.transaccion"
#     → binding "loan_events" ← "wallet.event.#" (el patrón coincide)
#     → nuestro consumidor recibe el AMQPMessage
#
# Ciclo de vida del mensaje:
#   basic_ack()   → procesado OK → RabbitMQ lo elimina
#   basic_nack(requeue=false) → falló → va a la Dead Letter Queue
#   (nada)        → si el proceso muere, RabbitMQ lo re-entrega (at-least-once)

declare(strict_types=1);

namespace LoanService\Consumer;

use LoanService\Application\EvaluadorElegibilidad;
use LoanService\Infrastructure\EventosProcesadosRepository;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * Consumidor de eventos de transacción para el loan-service.
 */
final class ConsumidorTransacciones
{
    private const EXCHANGE = 'prestaflow';
    private const EXCHANGE_TYPE = 'topic';
    private const COLA = 'loan_events';
    private const BINDING_KEY = 'wallet.event.#';

    public function __construct(
        private readonly EvaluadorElegibilidad $evaluador,
        private readonly EventosProcesadosRepository $procesados,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Inicia el bucle de consumo (bloquea el proceso para siempre).
     *
     * @param string $host      Host de RabbitMQ
     * @param int    $port      Puerto AMQP (5672)
     * @param string $user      Usuario AMQP
     * @param string $password  Contraseña AMQP
     * @param string $vhost     Virtual host (por defecto "/")
     */
    public function ejecutar(
        string $host,
        int $port,
        string $user,
        string $password,
        string $vhost,
    ): void {
        // 1. Conexión TCP + canal. Un canal = una "sesión" sobre la conexión.
        $conexion = new AMQPStreamConnection($host, $port, $user, $password, $vhost);
        $canal = $conexion->channel();

        // 2. Declarar la topología (idempotente: no falla si ya existe).
        //    exchange_declare(topic, durable) — MISMO exchange que declara
        //    el wallet-service: RabbitMQ es un directorio compartido.
        $canal->exchange_declare(
            self::EXCHANGE, self::EXCHANGE_TYPE, false, true, false,
        );

        //    queue_declare(durable) — la cola sobrevive reinicios del broker.
        $canal->queue_declare(self::COLA, false, true, false, false);

        //    queue_bind(columna, exchange, binding key).
        //    "Todo lo que empiece con wallet.event. me interesa".
        $canal->queue_bind(self::COLA, self::EXCHANGE, self::BINDING_KEY);

        // 3. Prefetch: procesar UN mensaje a la vez. Sin esto, RabbitMQ
        //    nos enviaría cientos de mensajes en ráfaga y saturaríamos la BD.
        $canal->basic_qos(null, 1, null);

        // 4. Registrar el callback por mensaje.
        //    Este closure se ejecuta por CADA mensaje recibido.
        $canal->basic_consume(
            self::COLA,          // cola
            '',                  // consumer_tag (auto)
            false,               // no_local
            false,               // no_ack → necesitamos ack EXPLÍCITO
            false,               // exclusive
            false,               // no_wait
            $this->procesar(...), // callback (referencia al método)
        );

        $this->logger->info('loan-service escuchando. Cola: ' . self::COLA);

        // 5. BUCLE INFINITO: el worker nunca termina. Escucha y bloquea.
        while ($canal->is_consuming()) {
            $canal->wait(); // espera el siguiente mensaje
        }

        // 6. Limpieza (solo si algún día se rompe el bucle).
        $canal->close();
        $conexion->close();
    }

    /**
     * Callback: procesa UN mensaje de la cola.
     *
     * @param AMQPMessage $mensaje Mensaje AMQP recibido
     */
    private function procesar(AMQPMessage $mensaje): void
    {
        $canal = $mensaje->getChannel();

        try {
            // 1. Decodificar el JSON del contrato público.
            //    El wallet-service publicó el EVENTO como body puro (JSON),
            //    gracias al EventoSerializer. Cualquier lenguaje lo leería.
            $evento = json_decode(
                $mensaje->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            // 2. Extraer la clave de deduplicación.
            //    Preferimos event_id; si el evento no lo trae, usamos
            //    el transaccion_id del payload como fallback.
            $payload = $evento['payload'] ?? [];
            $eventId = (string) ($evento['event_id'] ?? $payload['transaccion_id'] ?? '');

            if ($eventId === '') {
                throw new \RuntimeException('Evento sin ID de deduplicación.');
            }

            // 3. IDEMPOTENCIA: si ya lo procesamos, ack sin re-procesar.
            if ($this->procesados->existe($eventId)) {
                $this->logger->info("Evento duplicado ignorado: {$eventId}");
                $canal->basic_ack($mensaje->getDeliveryTag());

                return;
            }

            // 4. Ejecutar la regla de negocio (puro PHP, sin infra).
            $resultado = $this->evaluador->evaluar($evento);

            // 5. Persistir resultado + marcar como procesado (misma TX lógica).
            $this->procesados->registrar(
                $eventId,
                $resultado->puntaje,
                $resultado->recomendacion,
            );

            // 6. Confirmar al broker: mensaje procesado con éxito.
            $canal->basic_ack($mensaje->getDeliveryTag());

            $this->logger->info(
                "Evento {$eventId} → {$resultado->recomendacion} "
                . "(puntaje {$resultado->puntaje})"
            );
        } catch (\Throwable $e) {
            // Cualquier fallo → nack con requeue=false → Dead Letter Queue.
            // NO reintentamos aquí: el DLQ es el destino de lo que falla.
            $this->logger->error('Fallo procesando evento: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            $canal->basic_nack($mensaje->getDeliveryTag(), false, false);
        }
    }
}