<?php
// tests/Unit/Application/ObtenerBilleteraQueryHandlerTest.php — Test del query handler
//
# Los queries NUNCA mutan estado: este test verifica que el handler
# devuelve un snapshot plan (array) y con los contratos JSON exactos.

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\CommandHandler\ObtenerBilleteraQueryHandler;
use App\Application\Exception\BilleteraNoEncontrada;
use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Domain\Repository\BilleteraRepository;
use App\Message\Query\ObtenerBilleteraQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Verifica el caso de uso "obtener billetera" (solo lectura).
 */
final class ObtenerBilleteraQueryHandlerTest extends TestCase
{
    #[Test]
    public function retorna_snapshot_con_balance_calculado(): void
    {
        // GIVEN: una billetera con un depósito de 2500 MXN.
        $billetera = new Billetera(
            id: 'wal-123',
            usuarioId: 'pepe',
            moneda: Moneda::MXN,
        );
        $billetera->depositar(Dinero::crear('2500.00', Moneda::MXN), 'Salario');

        $handler = new ObtenerBilleteraQueryHandler($this->repositorioFake($billetera));

        // WHEN: se consulta la billetera.
        $snapshot = $handler(new ObtenerBilleteraQuery('wal-123'));

        // THEN: el snapshot expone los campos del contrato API.
        self::assertSame([
            'id' => 'wal-123',
            'usuario_id' => 'pepe',
            'moneda' => 'MXN',
            'balance' => '2500.00',
            'total_movimientos' => 1,
        ], $snapshot);
    }

    #[Test]
    public function query_inexistente_lanza_excepcion(): void
    {
        $handler = new ObtenerBilleteraQueryHandler($this->repositorioFake(null));

        $this->expectException(BilleteraNoEncontrada::class);

        $handler(new ObtenerBilleteraQuery('no-existe'));
    }

    private function repositorioFake(?Billetera $billetera): BilleteraRepository
    {
        return new class($billetera) implements BilleteraRepository {
            public function __construct(private ?Billetera $billetera)
            {
            }

            public function save(Billetera $billetera): void
            {
                $this->billetera = $billetera;
            }

            public function findById(string $id): ?Billetera
            {
                return $this->billetera;
            }
        };
    }
}