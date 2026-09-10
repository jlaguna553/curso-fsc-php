<?php
// tests/Unit/Domain/MovimientoTest.php — Tests unitarios del Value Object Movimiento
//
// Estos tests verifican que los factory methods de Movimiento
// creen instancias correctas con los tipos adecuados.

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Dinero;
use App\Domain\Moneda;
use App\Domain\Movimiento;
use App\Domain\TipoMovimiento;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MovimientoTest extends TestCase
{
    /**
     * Verifica que el factory method de depósito cree un movimiento correcto.
     *
     * El tipo debe ser DEPOSITO y el monto debe ser el especificado.
     */
    #[Test]
    public function test_factory_deposito(): void
    {
        $monto = Dinero::crear('500.00', Moneda::MXN);

        $movimiento = Movimiento::deposito(
            id: 'mov_001',
            monto: $monto,
            descripcion: 'Transferencia bancaria',
        );

        // Verificar tipo
        $this->assertSame(TipoMovimiento::DEPOSITO, $movimiento->tipo);
        // Verificar monto
        $this->assertSame(50000, $movimiento->monto->centavos());
        // Verificar descripción
        $this->assertSame('Transferencia bancaria', $movimiento->descripcion);
        // Verificar que tiene timestamp
        $this->assertNotNull($movimiento->creadoEn);
    }

    /**
     * Verifica que el factory method de retiro cree un movimiento correcto.
     */
    #[Test]
    public function test_factory_retiro(): void
    {
        $monto = Dinero::crear('200.00', Moneda::MXN);

        $movimiento = Movimiento::retiro(
            id: 'mov_002',
            monto: $monto,
            descripcion: 'Pago a comercio',
        );

        // Verificar tipo
        $this->assertSame(TipoMovimiento::RETIRO, $movimiento->tipo);
        // Verificar monto
        $this->assertSame(20000, $movimiento->monto->centavos());
        // Verificar descripción
        $this->assertSame('Pago a comercio', $movimiento->descripcion);
    }

    /**
     * Verifica que TipoMovimiento::esAcreditacion() funcione correctamente.
     *
     * DEPOSITO es acreditación (suma dinero).
     * RETIRO no es acreditación (resta dinero).
     */
    #[Test]
    public function test_tipo_movimiento_acreditacion(): void
    {
        $this->assertTrue(TipoMovimiento::DEPOSITO->esAcreditacion());
        $this->assertFalse(TipoMovimiento::RETIRO->esAcreditacion());
    }

    /**
     * Verifica que TipoMovimiento::etiqueta() retorne strings legibles.
     *
     * "Depósito" y "Retiro" son para interfaces de usuario,
     * no para comparaciones de código.
     */
    #[Test]
    public function test_tipo_movimiento_etiquetas(): void
    {
        $this->assertSame('Depósito', TipoMovimiento::DEPOSITO->etiqueta());
        $this->assertSame('Retiro', TipoMovimiento::RETIRO->etiqueta());
    }
}
