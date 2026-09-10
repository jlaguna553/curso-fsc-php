<?php
// tests/Unit/Domain/RetiroDataProviderTest.php — Data Provider del agregado
//
# Retiro es una operación con REGLAS ESTRICTAS (fondos suficientes).
# Los data providers permiten cubrir toda la matriz de casos en un test.
#
# REGLA DE DOMINIO (Billetera::retirar): balance <= monto → FondosInsuficientes.
# Ojo con el borde: retirar el 100% del saldo TAMBIÉN está prohibido
# (esMenorOIgualQue incluye la igualdad). El máximo retiro permitido
# deja al menos 1 centavo en la billetera.

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Billetera;
use App\Domain\Dinero;
use App\Domain\Exception\FondosInsuficientes;
use App\Domain\Moneda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetiroDataProviderTest extends TestCase
{
    /**
     * Matriz de retiros EXITOSOS: el retiro SIEMPRE debe ser MENOR al saldo.
     *
     * @return array<string, array{saldo: string, retiro: string, esperado: string}>
     */
    public static function proveerRetirosExitosos(): array
    {
        return [
            'retiro que deja un centavo' => ['saldo' => '100.00', 'retiro' => '99.99', 'esperado' => '0.01'],
            'mitad del saldo' => ['saldo' => '1000.00', 'retiro' => '500.00', 'esperado' => '500.00'],
            'retiro mínimo posible' => ['saldo' => '10.00', 'retiro' => '0.01', 'esperado' => '9.99'],
            'porcentaje aproximado' => ['saldo' => '0.03', 'retiro' => '0.01', 'esperado' => '0.02'],
        ];
    }

    #[Test]
    #[DataProvider('proveerRetirosExitosos')]
    public function retiro_valido_descuenta_saldo(string $saldo, string $retiro, string $esperado): void
    {
        $billetera = $this->crearBilletera($saldo);

        // WHEN: retiro válido.
        $billetera->retirar(Dinero::crear($retiro, Moneda::MXN), 'Retiro test');

        // THEN: el balance queda en el valor esperado.
        self::assertSame($esperado, $billetera->balance()->formateadoDecimal());
    }

    /**
     * Matriz de retiros que DEBEN fallar por fondos insuficientes.
     * Incluye el CASO BORDE: retiro exacto == saldo (igualdad prohibida).
     *
     * @return array<string, array{saldo: string, retiro: string}>
     */
    public static function proveerRetirosFallidos(): array
    {
        return [
            'retiro exacto (el 100% del saldo)' => ['saldo' => '100.00', 'retiro' => '100.00'],
            'ligeramente mayor' => ['saldo' => '100.00', 'retiro' => '100.01'],
            'el doble' => ['saldo' => '100.00', 'retiro' => '200.00'],
            'sin saldo' => ['saldo' => '0.00', 'retiro' => '1.00'],
        ];
    }

    #[Test]
    #[DataProvider('proveerRetirosFallidos')]
    public function retiro_sin_fondos_lanza_excepcion(string $saldo, string $retiro): void
    {
        $billetera = $this->crearBilletera($saldo);

        // EXPECT: cada caso con saldo insuficiente lanza FondosInsuficientes.
        $this->expectException(FondosInsuficientes::class);

        $billetera->retirar(Dinero::crear($retiro, Moneda::MXN), 'Retiro imposible');
    }

    /**
     * @return Billetera Billetera con saldo inicial
     */
    private function crearBilletera(string $saldo): Billetera
    {
        $billetera = new Billetera(
            id: 'wal-retiro',
            usuarioId: 'ana',
            moneda: Moneda::MXN,
        );

        if ($saldo !== '0.00') {
            $billetera->depositar(Dinero::crear($saldo, Moneda::MXN), 'Saldo inicial');
        }

        return $billetera;
    }
}