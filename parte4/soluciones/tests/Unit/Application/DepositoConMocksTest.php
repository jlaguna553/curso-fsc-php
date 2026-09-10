<?php
// tests/Unit/Application/DepositoConMocksTest.php — Test Doubles (Parte 4)
//
# TEST DOUBLES: sustitutos de dependencias reales.
#   - Dummy:    se pasa pero nunca se usa (cumple la firma)
#   - Fake:     implementación simplificada (la usamos en la Parte 3)
#   - Stub:     retorna respuestas programadas
#   - Mock:     stub + verificación de llamadas (expectations)
#   - Spy:      registra llamadas para verificarlas después
#
# Aquí usamos los MOCKS nativos de PHPUnit (createMock) para el event bus
# y el repositorio: configuramos expectativas Y verificamos interacciones.

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\CommandHandler\RealizarDepositoCommandHandler;
use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Domain\Repository\IdempotenciaRepository;
use App\Message\Command\RealizarDepositoCommand;
use App\Message\Event\TransaccionCompletadaEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class DepositoConMocksTest extends TestCase
{
    #[Test]
    public function repositorio_stub_retorna_billetera_y_verifica_save(): void
    {
        // STUB del repositorio: le decimos QUÉ responder a findById().
        // PHPUnit crea SOLO el método que configuramos (los demás devuelven
        // valores por defecto: null, 0, []).
        $repositorio = $this->createMock(BilleteraRepository::class);
        $repositorio->method('findById')
            ->with($this->equalTo('wal-1'))          // exigimos el argumento exacto
            ->willReturn($this->billeteraConSaldo('100.00'));

        // MOCK del repositorio de idempotencia: verificamos que
        // registrar() se llame EXACTAMENTE una vez con la clave esperada.
        $idempotencia = $this->createMock(IdempotenciaRepository::class);
        $idempotencia->expects($this->once())          // → 1 llamada
            ->method('registrar')
            ->with($this->equalTo('clave-unica-1'));

        // MOCK del event bus: esperamos 1 dispatch de TransaccionCompletadaEvent.
        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(TransaccionCompletadaEvent::class))
            ->willReturnCallback(fn ($msg, array $stamps = []) => new Envelope($msg));

        // WHEN: ejecutamos el handler con los doubles.
        $handler = new RealizarDepositoCommandHandler(
            billeteras: $repositorio,
            idempotencia: $idempotencia,
            eventBus: $eventBus,
        );

        $balance = $handler(RealizarDepositoCommand::crear(
            billeteraId: 'wal-1',
            monto: '50.00',
            moneda: 'MXN',
            descripcion: 'Test con mocks',
            idempotencyKey: 'clave-unica-1',
        ));

        // THEN: el balance resultante es el esperado.
        self::assertSame('150.00', $balance);

        // La verificación de expects() ocurre automáticamente en tearDown()
        // de PHPUnit: si registrar() no se llamó o se llamó 2 veces → test ROJO.
    }

    #[Test]
    public function idempotencia_mock_devuelve_resultado_sin_dispatch(): void
    {
        // STUB: existe() devuelve true → el handler debe salir sin tocar nada.
        $repositorio = $this->createMock(BilleteraRepository::class);
        $repositorio->method('findById')
            ->willReturn($this->billeteraConSaldo('100.00'));

        $idempotencia = $this->createMock(IdempotenciaRepository::class);
        $idempotencia->method('existe')
            ->willReturn(true);

        // MOCK: el event bus NO debe recibir NINGUNA llamada.
        // expects(never()) → si se llama, el test falla.
        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects($this->never())
            ->method('dispatch');

        $handler = new RealizarDepositoCommandHandler(
            billeteras: $repositorio,
            idempotencia: $idempotencia,
            eventBus: $eventBus,
        );

        $balance = $handler(RealizarDepositoCommand::crear(
            billeteraId: 'wal-1',
            monto: '50.00',
            moneda: 'MXN',
            descripcion: 'Duplicado',
            idempotencyKey: 'clave-repetida',
        ));

        // THEN: se devuelve el balance actual (100.00), no 150.00.
        self::assertSame('100.00', $balance);
    }

    #[Test]
    public function mock_con_callback_devuelve_valor_dinamico(): void
    {
        // STUB con callback: la respuesta depende del argumento.
        $repositorio = $this->createMock(BilleteraRepository::class);
        $repositorio->method('findById')
            ->willReturnCallback(
                fn (string $id): ?Billetera => $id === 'wal-existe'
                    ? $this->billeteraConSaldo('10.00')
                    : null,
            );

        $idempotencia = $this->createMock(IdempotenciaRepository::class);
        $idempotencia->method('existe')->willReturn(false);
        $idempotencia->method('registrar');

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->method('dispatch')->willReturnCallback(
            fn ($msg, array $stamps = []) => new Envelope($msg),
        );

        $handler = new RealizarDepositoCommandHandler(
            billeteras: $repositorio,
            idempotencia: $idempotencia,
            eventBus: $eventBus,
        );

        // La billetera que NO existe produce un resultado distinto:
        // findById('wal-fantasma') → null → BilleteraNoEncontrada.
        $this->expectException(\App\Application\Exception\BilleteraNoEncontrada::class);

        $handler(RealizarDepositoCommand::crear(
            billeteraId: 'wal-fantasma',
            monto: '5.00',
            moneda: 'MXN',
            descripcion: 'x',
            idempotencyKey: 'k3',
        ));
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
        $billetera->depositar(Dinero::crear($saldo, Moneda::MXN), 'Inicial');

        return $billetera;
    }
}