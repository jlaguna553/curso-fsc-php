<?php
// tests/Unit/Domain/BilleteraTest.php — Tests unitarios del Aggregate Billetera
//
// Billetera es el Aggregate Root: el punto de entrada para todas las
// operaciones de la billetera. Estos tests verifican:
//   - Creación de billeteras
//   - Depósitos (happy path + edge cases)
//   - Retiros (happy path + fondos insuficientes)
//   - Balance calculado correctamente
//   - Historial de movimientos
//
// TODOS estos tests son UNITARIOS: no usan base de datos, HTTP, ni I/O.
// Son rápidos (< 100ms total) y ejecutables en cualquier entorno.

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Exception\FondosInsuficientes;
use App\Domain\Moneda;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BilleteraTest extends TestCase
{
    // =========================================================================
    // TESTS DE CREACIÓN
    // =========================================================================

    /**
     * Verifica que una billetera nueva tenga balance cero.
     *
     * Al crear una billetera sin movimientos, el balance debe ser 0.
     * Esto es el estado inicial de cualquier wallet en fintech.
     */
    #[Test]
    public function test_billetera_nueva_tiene_balance_cero(): void
    {
        // Crear billetera vacía (sin movimientos = []
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Verificar que el balance es cero
        $balance = $billetera->balance();
        $this->assertSame(0, $balance->centavos());
        $this->assertSame(Moneda::MXN, $balance->moneda());
    }

    // =========================================================================
    // TESTS DE DEPÓSITO
    // =========================================================================

    /**
     * Verifica que un depósito incremente el balance correctamente.
     *
     * Antes: balance = 0
     * Depósito: 1500.00 MXN (150000 centavos)
     * Después: balance = 1500.00 MXN
     */
    #[Test]
    public function test_depositar_incremente_balance(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        $monto = Dinero::crear('1500.00', Moneda::MXN);

        // Ejecutar el depósito
        $movimiento = $billetera->depositar($monto, 'Depósito inicial');

        // Verificar que el movimiento se creó correctamente
        $this->assertSame('Depósito inicial', $movimiento->descripcion);

        // Verificar que el balance se actualizó
        $this->assertSame(150000, $billetera->balance()->centavos());

        // Verificar que hay 1 movimiento registrado
        $this->assertSame(1, $billetera->totalMovimientos());
    }

    /**
     * Verifica que múltiples depósitos acumulen correctamente.
     *
     * Depósito 1: 1000.00
     * Depósito 2: 500.00
     * Balance esperado: 1500.00
     */
    #[Test]
    public function test_multiples_depositos_acumulan_balance(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Primer depósito
        $billetera->depositar(
            Dinero::crear('1000.00', Moneda::MXN),
            'Transferencia bancaria',
        );

        // Segundo depósito
        $billetera->depositar(
            Dinero::crear('500.00', Moneda::MXN),
            'Depósito en efectivo',
        );

        // Verificar balance acumulado
        $this->assertSame(150000, $billetera->balance()->centavos());
        // Verificar que hay 2 movimientos
        $this->assertSame(2, $billetera->totalMovimientos());
    }

    /**
     * Verifica que no se pueda depositar monto cero.
     *
     * Un depósito de $0 no tiene sentido de negocio.
     */
    #[Test]
    public function test_depositar_monto_cero_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        $billetera->depositar(
            Dinero::crear('0.00', Moneda::MXN),
            'Depósito vacío',
        );
    }

    /**
     * Verifica que no se pueda depositar moneda diferente a la billetera.
     *
     * Si la billetera es MXN, no acepta depósitos en USD.
     */
    #[Test]
    public function test_depositar_moneda_diferente_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Intentar depositar USD en billetera MXN
        $billetera->depositar(
            Dinero::crear('100.00', Moneda::USD),
            'Depósito en USD',
        );
    }

    // =========================================================================
    // TESTS DE RETIRO
    // =========================================================================

    /**
     * Verifica que un retiro decremente el balance correctamente.
     *
     * Balance: 5000.00
     * Retiro: 1500.00
     * Balance esperado: 3500.00
     */
    #[Test]
    public function test_retirar_decrementa_balance(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Primero depositar (para tener saldo)
        $billetera->depositar(
            Dinero::crear('5000.00', Moneda::MXN),
            'Depósito inicial',
        );

        // Retirar
        $billetera->retirar(
            Dinero::crear('1500.00', Moneda::MXN),
            'Pago a comercio',
        );

        // Balance: 5000 - 1500 = 3500 (350000 centavos)
        $this->assertSame(350000, $billetera->balance()->centavos());
        // 2 movimientos: 1 depósito + 1 retiro
        $this->assertSame(2, $billetera->totalMovimientos());
    }

    /**
     * Verifica que un retiro mayor al saldo lance FondosInsuficientes.
     *
     * Balance: 500.00
     * Retiro: 1000.00
     * Resultado: FondosInsuficientes con deficit de 500.00
     */
    #[Test]
    public function test_retirar_saldo_insuficiente_lanza_fondos_insuficientes(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Depositamos solo 500
        $billetera->depositar(
            Dinero::crear('500.00', Moneda::MXN),
            'Depósito inicial',
        );

        try {
            // Intentar retirar 1000 (más de lo que hay)
            $billetera->retirar(
                Dinero::crear('1000.00', Moneda::MXN),
                'Retiro excesivo',
            );

            // Si llegamos aquí, el test FALLA porque no se lanzó excepción
            $this->fail('Se esperaba FondosInsuficientes pero no se lanzó');
        } catch (FondosInsuficientes $e) {
            // Verificar que la excepción carry los datos correctos
            $this->assertSame(50000, $e->saldoActual->centavos());
            $this->assertSame(100000, $e->montoSolicitado->centavos());
            // Déficit = 100000 - 50000 = 50000 centavos
            $this->assertSame(50000, $e->deficit()->centavos());
        }
    }

    /**
     * Verifica que no se pueda retirar monto cero.
     */
    #[Test]
    public function test_retirar_monto_cero_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        $billetera->retirar(
            Dinero::crear('0.00', Moneda::MXN),
            'Retiro vacío',
        );
    }

    // =========================================================================
    // TESTS DE HISTORIAL
    // =========================================================================

    /**
     * Verifica que los movimientos se mantengan en orden cronológico.
     *
     * Los movimientos se agregan al final del array, así que el primero
     * siempre es el más viejo y el último el más reciente.
     */
    #[Test]
    public function test_movimientos_se_mantienen_en_orden(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        // Ejecutar 3 movimientos
        $billetera->depositar(Dinero::crear('100.00', Moneda::MXN), 'Depósito 1');
        $billetera->retirar(Dinero::crear('30.00', Moneda::MXN), 'Retiro 1');
        $billetera->depositar(Dinero::crear('200.00', Moneda::MXN), 'Depósito 2');

        $movimientos = $billetera->movimientos();

        // Verificar que hay 3 movimientos
        $this->assertCount(3, $movimientos);

        // Verificar orden: depósito, retiro, depósito
        $this->assertSame('Depósito 1', $movimientos[0]->descripcion);
        $this->assertSame('Retiro 1', $movimientos[1]->descripcion);
        $this->assertSame('Depósito 2', $movimientos[2]->descripcion);
    }

    /**
     * Verifica que movimientos() retorne una copia, no la referencia interna.
     *
     * Si retornara la referencia, el caller podría modificar el array
     * directamente, saltándose las validaciones de negocio.
     */
    #[Test]
    public function test_movimientos_retorna_copia(): void
    {
        $billetera = new Billetera(
            id: 'wallet_001',
            usuarioId: 'user_001',
            moneda: Moneda::MXN,
        );

        $movimientos = $billetera->movimientos();

        // Intentar agregar un movimiento a la referencia
        // (esto no debería afectar a la billetera)
        $movimientos[] = 'fake';

        // La billetera no debe tener movimientos
        $this->assertSame(0, $billetera->totalMovimientos());
    }
}
