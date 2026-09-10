<?php
// tests/Unit/Application/RealizarDepositoCommandHandlerTest.php — Test del use case
//
# Los tests de idempotencia son LOS MÁS importantes de un sistema financiero:
#   - Rendir el depósito DOS veces con la misma clave debe dar el MISMO resultado
#   - Rendir con clave distinta sí debe duplicar (es otra operación)

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\CommandHandler\RealizarDepositoCommandHandler;
use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Billetera;
use App\Domain\Exception\FondosInsuficientes;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Domain\Repository\IdempotenciaRepository;
use App\Message\Command\RealizarDepositoCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Verifica el caso de uso "depositar" incluyendo idempotencia.
 */
final class RealizarDepositoCommandHandlerTest extends TestCase
{
    #[Test]
    public function deposita_y_retorna_nuevo_balance(): void
    {
        $billetera = $this->billeteraConSaldo('1000.00');
        $handler = $this->handler(almacen: [$billetera]);

        // WHEN: depósito de 500 MXN.
        $balance = $handler(RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '500.00',
            moneda: 'MXN',
            descripcion: 'Nómina',
            idempotencyKey: 'clave-1',
        ));

        // THEN: balance nuevo = 1500.00.
        self::assertSame('1500.00', $balance);
    }

    #[Test]
    public function misma_clave_no_duplica_deposito(): void
    {
        $billetera = $this->billeteraConSaldo('1000.00');
        $idempotencia = $this->idempotenciaFake();
        $handler = $this->handler(
            almacen: [$billetera],
            idempotencia: $idempotencia,
        );

        $command = RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '500.00',
            moneda: 'MXN',
            descripcion: 'Nómina',
            idempotencyKey: 'misma-clave',
        );

        // WHEN: se procesa DOS veces con la misma clave.
        $primerBalance = $handler($command);
        $segundoBalance = $handler($command);

        // THEN: ambos retornan el mismo resultado (1500.00), no 2000.00.
        self::assertSame('1500.00', $primerBalance);
        self::assertSame('1500.00', $segundoBalance);

        // Y solo hay UN movimiento registrado (no dos).
        self::assertSame(1, $billetera->totalMovimientos());
    }

    #[Test]
    public function clave_distinta_si_duplica(): void
    {
        $billetera = $this->billeteraConSaldo('0.00');
        $idempotencia = $this->idempotenciaFake();
        $handler = $this->handler(
            almacen: [$billetera],
            idempotencia: $idempotencia,
        );

        // WHEN: dos depósitos legítimos con claves diferentes.
        $handler(RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '100.00',
            moneda: 'MXN',
            descripcion: 'A',
            idempotencyKey: 'clave-A',
        ));
        $handler(RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '100.00',
            moneda: 'MXN',
            descripcion: 'B',
            idempotencyKey: 'clave-B',
        ));

        // THEN: hay dos movimientos y balance 200.00.
        self::assertSame(2, $billetera->totalMovimientos());
        self::assertSame('200.00', $billetera->balance()->formateadoDecimal());
    }

    #[Test]
    public function billetera_inexistente_lanza_excepcion(): void
    {
        $handler = $this->handler(almacen: []);

        $this->expectException(BilleteraNoEncontrada::class);

        $handler(RealizarDepositoCommand::crear(
            billeteraId: 'no-existe',
            monto: '10.00',
            moneda: 'MXN',
            descripcion: 'x',
            idempotencyKey: 'k',
        ));
    }

    #[Test]
    public function publica_evento_transaccion_completada(): void
    {
        $billetera = $this->billeteraConSaldo('100.00');
        $eventBus = $this->eventBusFake();
        $handler = $this->handler(
            almacen: [$billetera],
            eventBus: $eventBus,
        );

        $handler(RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '50.00',
            moneda: 'MXN',
            descripcion: 'Test',
            idempotencyKey: 'k-evento',
        ));

        self::assertCount(1, $eventBus->despachados);
        $evento = $eventBus->despachados[0];
        self::assertInstanceOf(\App\Message\Event\TransaccionCompletadaEvent::class, $evento);
        self::assertSame('150.00', $evento->balanceNuevo);
        self::assertSame('deposito', $evento->tipo);
    }

    #[Test]
    public function evento_no_se_publica_en_reintento_idempotente(): void
    {
        $billetera = $this->billeteraConSaldo('100.00');
        $idempotencia = $this->idempotenciaFake();
        $eventBus = $this->eventBusFake();
        $handler = $this->handler(
            almacen: [$billetera],
            idempotencia: $idempotencia,
            eventBus: $eventBus,
        );

        $command = RealizarDepositoCommand::crear(
            billeteraId: $billetera->id,
            monto: '50.00',
            moneda: 'MXN',
            descripcion: 'x',
            idempotencyKey: 'k-dup',
        );

        $handler($command);
        $handler($command);

        // Solo la PRIMERA ejecución debe publicar el evento.
        self::assertCount(1, $eventBus->despachados);
    }

    /**
     * Construye el handler con dependencias configurables.
     */
    private function handler(
        array $almacen,
        ?IdempotenciaRepository $idempotencia = null,
        ?MessageBusInterface $eventBus = null,
    ): RealizarDepositoCommandHandler {
        $repositorio = $this->repositorioFake($almacen);

        return new RealizarDepositoCommandHandler(
            billeteras: $repositorio,
            idempotencia: $idempotencia ?? $this->idempotenciaFake(),
            eventBus: $eventBus ?? $this->eventBusFake(),
        );
    }

    /**
     * Billetera de prueba con saldo inicial.
     */
    private function billeteraConSaldo(string $saldo): Billetera
    {
        $billetera = new Billetera(
            id: (string) Uuid::v4(),
            usuarioId: 'ana',
            moneda: Moneda::MXN,
        );

        // Depositar el saldo inicial directamente (sin handler).
        $billetera->depositar(
            \App\Domain\Dinero::crear($saldo, Moneda::MXN),
            'Saldo inicial',
        );

        return $billetera;
    }

    /**
     * @param array<string, Billetera> $almacen
     */
    private function repositorioFake(array &$almacen): BilleteraRepository
    {
        return new class($almacen) implements BilleteraRepository {
            public function __construct(private array &$storage)
            {
            }

            public function save(Billetera $billetera): void
            {
                $this->storage[$billetera->id] = $billetera;
            }

            public function findById(string $id): ?Billetera
            {
                return $this->storage[$id] ?? null;
            }
        };
    }

    private function idempotenciaFake(): IdempotenciaRepository
    {
        return new class implements IdempotenciaRepository {
            /** @var array<string, true> */
            private array $claves = [];

            public function existe(string $clave): bool
            {
                return isset($this->claves[$clave]);
            }

            public function registrar(string $clave): void
            {
                $this->claves[$clave] = true;
            }
        };
    }

    private function eventBusFake(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            public array $despachados = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->despachados[] = $message;

                return new Envelope($message);
            }
        };
    }
}