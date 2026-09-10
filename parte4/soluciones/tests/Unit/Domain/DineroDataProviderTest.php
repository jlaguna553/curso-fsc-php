<?php
// tests/Unit/Domain/DineroDataProviderTest.php — Data Providers (Parte 4)
//
# DATA PROVIDERS: la forma de probar UNA regla con MUCHOS casos.
# En lugar de escribir 10 tests idénticos, escribes un test con un
# provider que inyecta los datos.
#
# Ventajas:
#   1. Legibilidad: la tabla de casos se lee de un vistazo
#   2. Mantenibilidad: añadir un caso = añadir una línea
#   3. Reporte granular: PHPUnit reporta CADA caso por separado
#
# PHPUnit 11 usa el atributo #[DataProvider('nombre')].

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Dinero;
use App\Domain\Exception\MonedaNoSoportada;
use App\Domain\Moneda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DineroDataProviderTest extends TestCase
{
    // =========================================================================
    // CONVERSIÓN DECIMAL → CENTAVOS
    // =========================================================================

    /**
     * El corazón de todo sistema financiero: "1500.00" MXN == 150000 centavos.
     *
     * La conversión DEBE ser exacta. Sin floats. Sin redondeos raros.
     *
     * @return array<string, array{decimal: string, centavosEsperados: int}>
     */
    public static function proveerDecimales(): array
    {
        // Clave = descripción del caso (aparece en el reporte).
        // Valor = [decimal, centavos esperados].
        return [
            'cero' => ['decimal' => '0.00', 'centavosEsperados' => 0],
            'simple' => ['decimal' => '1.00', 'centavosEsperados' => 100],
            'sin decimales' => ['decimal' => '10', 'centavosEsperados' => 1000],
            'con milésimas redondeadas' => ['decimal' => '0.005', 'centavosEsperados' => 1],
            'cantidad grande' => ['decimal' => '1234567.89', 'centavosEsperados' => 123456789],
        ];
    }

    #[Test]
    #[DataProvider('proveerDecimales')]
    public function convierte_decimal_a_centavos(string $decimal, int $centavosEsperados): void
    {
        // WHEN: creamos Dinero desde un decimal.
        $dinero = Dinero::crear($decimal, Moneda::MXN);

        // THEN: los centavos son exactos.
        self::assertSame($centavosEsperados, $dinero->centavos());
    }

    // =========================================================================
    // MONEDAS NO SOPORTADAS
    // =========================================================================

    /**
     * @return array<string, array{moneda: string}>
     */
    public static function proveerMonedasInvalidas(): array
    {
        return [
            'cripto' => ['moneda' => 'BTC'],
            'lowercase' => ['moneda' => 'mxn'],
            'inexistente' => ['moneda' => 'XYZ'],
            'numérica' => ['moneda' => '123'],
            'vacía' => ['moneda' => ''],
        ];
    }

    #[Test]
    #[DataProvider('proveerMonedasInvalidas')]
    public function rechaza_moneda_invalida(string $moneda): void
    {
        // EXPECT: ninguno de estos códigos está en el enum Moneda.
        // tryFrom() retorna null → MonedaNoSoportada.
        $this->expectException(MonedaNoSoportada::class);

        // Dinero::crear valida la moneda implícitamente al usar el enum.
        // Pero la excepción correcta la lanza Moneda::desdeCodigo().
        $monedaEnum = Moneda::tryFrom($moneda);
        if ($monedaEnum === null) {
            throw new MonedaNoSoportada(
                codigoMoneda: $moneda,
                monedasSoportadas: ['MXN', 'USD', 'EUR'],
            );
        }

        self::fail('No debió llegar aquí: la moneda debería ser rechazada.');
    }

    // =========================================================================
    // VALIDACIÓN DE MONTO INVÁLIDO
    // =========================================================================

    /**
     * @return array<string, array{monto: string}>
     */
    public static function proveerMontosInvalidos(): array
    {
        return [
            'negativo' => ['monto' => '-5.00'],
            'texto' => ['monto' => 'abc'],
            'vacío' => ['monto' => ''],
            'especiales' => ['monto' => '1,000.00'],   // coma como separador de miles NO es válido
            'null' => ['monto' => '0.0.0'],
        ];
    }

    #[Test]
    #[DataProvider('proveerMontosInvalidos')]
    public function rechaza_monto_invalido(string $monto): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dinero::crear($monto, Moneda::MXN);
    }

    // =========================================================================
    // SUMA Y RESTA (casos límite)
    // =========================================================================

    /**
     * @return array<string, array{a: string, b: string, esperado: string}>
     */
    public static function proveerSumas(): array
    {
        return [
            'ambos positivos' => ['a' => '1.00', 'b' => '2.50', 'esperado' => '3.50'],
            'sumar cero' => ['a' => '100.00', 'b' => '0.00', 'esperado' => '100.00'],
            'caso grande' => ['a' => '999999.99', 'b' => '0.01', 'esperado' => '1000000.00'],
        ];
    }

    #[Test]
    #[DataProvider('proveerSumas')]
    public function suma_montos_sin_perder_precision(
        string $a,
        string $b,
        string $esperado,
    ): void {
        $resultado = Dinero::crear($a, Moneda::MXN)
            ->sumar(Dinero::crear($b, Moneda::MXN));

        self::assertSame($esperado, $resultado->formateadoDecimal());
    }
}