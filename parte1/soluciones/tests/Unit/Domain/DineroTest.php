<?php
// tests/Unit/Domain/DineroTest.php — Tests unitarios del Value Object Dinero
//
// TESTS UNITARIOS: Pruebas que verifican UNA clase en aislamiento total.
// NO dependen de: base de datos, red, filesystem, ni otros servicios.
// Ejecutan en milisegundos (la mayoría < 1ms).
//
// En TDD, estos tests se escriben PRIMERO (antes del código).
// El developer ve los tests fallar (RED), escribe el código mínimo (GREEN),
// y luego limpia (REFACTOR).
//
// PHPUnit 11 usa atributos PHP 8 en lugar de anotaciones docblock:
//   #[Test]            → marca un método como test ejecutable
//   #[DataProvider('x')] → vincula un método que provee datos de prueba
//   #[CoversClass(X)]  → indica qué clase se está testeando (para cobertura)
//   #[Group('unit')]   → agrupa tests para ejecución selectiva

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Dinero;
use App\Domain\Exception\FondosInsuficientes;
use App\Domain\Moneda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests del Value Object Dinero.
 *
 * Cada test verifica UN comportamiento específico.
 * El nombre del método describe QUÉ se está testeando.
 * Convención: test_sujeto_bajo_prueba_comportamiento_esperado
 */
final class DineroTest extends TestCase
{
    // =========================================================================
    // TESTS DE CREACIÓN
    // =========================================================================

    /**
     * Verifica que Dinero::crear() funcione correctamente con un monto válido.
     *
     * Este es el "happy path": todo funciona como se espera.
     * Si este test falla, hay un problema fundamental.
     */
    #[Test]
    public function test_crear_dinero_desde_monto_decimal(): void
    {
        // ARRANGE (Preparar): crear los datos de prueba
        // Usamos 1500.00 MXN como ejemplo típico de depósito
        $monto = '1500.00';
        $moneda = Moneda::MXN;

        // ACT (Actuar): ejecutar el método que estamos testeando
        $dinero = Dinero::crear($monto, $moneda);

        // ASSERT (Verificar): comprobar que el resultado es el esperado
        // sameInt() es estricto (===): compara tipo Y valor.
        // assertSame(150000, $dinero->centavos()) fallaría si retornara "150000" (string).
        $this->assertSame(150000, $dinero->centavos());
        $this->assertSame($moneda, $dinero->moneda());
    }

    /**
     * Verifica que la creación desde centavos funcione correctamente.
     *
     * Este factory method se usa cuando el monto ya viene en centavos
     * (ej: desde una base de datos que almacena enteros).
     */
    #[Test]
    public function test_crear_dinero_desde_centavos(): void
    {
        // Crear Dinero directamente con 50000 centavos (500.00)
        $dinero = Dinero::desdeCentavos(50000, Moneda::MXN);

        // Verificar que los centavos se almacenan correctamente
        $this->assertSame(50000, $dinero->centavos());
        // Verificar que formateadoDecimal() convierte correctamente
        // 50000 centavos → "500.00"
        $this->assertSame('500.00', $dinero->formateadoDecimal());
    }

    /**
     * Verifica que crear() rechace montos negativos.
     *
     * Un depósito negativo no tiene sentido de negocio.
     * Si no validamos esto, podríamos crear billeteras con saldo negativo
     * por error.
     */
    #[Test]
    public function test_crear_dinero_monto_negativo_lanza_excepcion(): void
    {
        // PHPUnit expectException() ASSERT que se lance una excepción específica.
        // Si la función NO lanza la excepción → test FALLA.
        // Si lanza OTRA excepción → test FALLA.
        // Si lanza esta excepción → test PASA.
        $this->expectException(\InvalidArgumentException::class);

        // Intentar crear Dinero con monto negativo
        Dinero::crear('-100.00', Moneda::MXN);
    }

    /**
     * Verifica que crear() rechace montos con formato inválido.
     *
     * "abc" no es un número válido. filter_var debe rechazarlo.
     */
    #[Test]
    public function test_crear_dinero_monto_no_numerico_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dinero::crear('abc', Moneda::MXN);
    }

    // =========================================================================
    // TESTS DE OPERACIONES ARITMÉTICAS
    // =========================================================================

    /**
     * Verifica que la suma de dos Dinero de la misma moneda funcione.
     *
     * Esta es la operación más básica: 500 + 300 = 800.
     * Los centavos se suman directamente (50000 + 30000 = 80000).
     */
    #[Test]
    public function test_sumar_montos_misma_moneda(): void
    {
        // ARRANGE: dos montos en la misma moneda
        $cincoCientos = Dinero::crear('500.00', Moneda::MXN);
        $tresCientos = Dinero::crear('300.00', Moneda::MXN);

        // ACT: sumar los dos montos
        $resultado = $cincoCientos->sumar($tresCientos);

        // ASSERT: verificar el resultado
        $this->assertSame(80000, $resultado->centavos());
        $this->assertSame('800.00', $resultado->formateadoDecimal());
    }

    /**
     * Verifica que la suma rechace montos de monedas diferentes.
     *
     * Sumar MXN + USD sin conversión es un error de negocio.
     * El cliente debe convertir explícitamente antes de sumar.
     */
    #[Test]
    public function test_sumar_montos_distinta_moneda_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $mxn = Dinero::crear('500.00', Moneda::MXN);
        $usd = Dinero::crear('300.00', Moneda::USD);

        // Intentar sumar MXN + USD debe fallar
        $mxn->sumar($usd);
    }

    /**
     * Verifica que la resta funcione correctamente.
     *
     * 800 - 300 = 500 (en centavos: 80000 - 30000 = 50000)
     */
    #[Test]
    public function test_restar_montos_suficientes(): void
    {
        $ochoCientos = Dinero::crear('800.00', Moneda::MXN);
        $tresCientos = Dinero::crear('300.00', Moneda::MXN);

        $resultado = $ochoCientos->restar($tresCientos);

        $this->assertSame(50000, $resultado->centavos());
    }

    /**
     * Verifica que la resta lance FondosInsuficientes cuando no hay saldo.
     *
     * 300 - 800 = saldo negativo → excepción de dominio.
     *
     * Esta excepción carry datos (saldo actual, monto solicitado, déficit)
     * para que el controller pueda retornar una respuesta HTTP descriptiva.
     */
    #[Test]
    public function test_restar_montos_insuficientes_lanza_fondos_insuficientes(): void
    {
        // Esperar nuestra excepción de dominio, no una genérica
        $this->expectException(FondosInsuficientes::class);

        $tresCientos = Dinero::crear('300.00', Moneda::MXN);
        $ochoCientos = Dinero::crear('800.00', Moneda::MXN);

        // Intentar restar más de lo que se tiene
        $tresCientos->restar($ochoCientos);
    }

    /**
     * Verifica que se pueda restar exactamente el saldo disponible.
     *
     * 500 - 500 = 0 (caso borde: saldo exacto)
     */
    #[Test]
    public function test_restar_exactamente_el_saldo(): void
    {
        $monto = Dinero::crear('500.00', Moneda::MXN);

        $resultado = $monto->restar($monto);

        $this->assertSame(0, $resultado->centavos());
    }

    // =========================================================================
    // TESTS DE COMPARACIÓN
    // =========================================================================

    /**
     * Verifica que esIgualA() funcione correctamente.
     *
     * Dos Dinero con el mismo monto y moneda deben ser considerados iguales.
     * Sin este método, PHP compara por referencia (memoria), no por valor.
     */
    #[Test]
    public function test_dinero_igual_por_valor(): void
    {
        // Crear DOS instancias diferentes con los mismos valores
        $a = Dinero::crear('100.00', Moneda::MXN);
        $b = Dinero::crear('100.00', Moneda::MXN);

        // Verificar que esIgualA() los compara correctamente
        $this->assertTrue($a->esIgualA($b));
    }

    /**
     * Verifica que esMayorQue() funcione correctamente.
     */
    #[Test]
    public function test_dinero_mayor_que(): void
    {
        $mayor = Dinero::crear('500.00', Moneda::MXN);
        $menor = Dinero::crear('300.00', Moneda::MXN);

        $this->assertTrue($mayor->esMayorQue($menor));
        $this->assertFalse($menor->esMayorQue($mayor));
    }

    // =========================================================================
    // TESTS DE DATA PROVIDERS (tests parametrizados)
    // =========================================================================

    /**
     * Verifica la conversión de centavos a decimal con múltiples valores.
     *
     * DataProvider: PHPUnit ejecuta este test UNA VEZ por cada sub-array
     * del método montonParaCentavos(). Esto reemplaza 5 test methods
     * individuales que harían lo mismo con diferentes datos.
     *
     * Ventaja: si el patrón de test es igual pero los datos cambian,
     * un DataProvider es más limpio y mantenible.
     */
    #[Test]
    #[DataProvider('montonParaCentavos')]
    public function test_conversion_centavos_decimal(string $monto, int $centavosEsperados): void
    {
        $dinero = Dinero::crear($monto, Moneda::MXN);

        $this->assertSame($centavosEsperados, $dinero->centavos());
    }

    /**
     * DataProvider para el test anterior.
     *
     * REGLA: el método DEBE ser static (PHPUnit 11 requirement).
     * Si no fuera static, PHPUnit no podría accederlo sin instanciar la clase.
     *
     * Retorna un array de arrays: [input, expected_output]
     * Cada sub-array = un caso de prueba independiente.
     *
     * @return array<string, array{string, int}>
     */
    public static function montonParaCentavos(): array
    {
        return [
            // Nombre del caso    => [monto_input, centavos_esperados]
            'un centavo'          => ['0.01', 1],
            'diez centavos'       => ['0.10', 10],
            'un peso'             => ['1.00', 100],
            'cincocientos pesos'  => ['500.00', 50000],
            'monto sin decimales' => ['100', 10000],
            // Caso borde: el máximo centavo antes de overflow teórico
            'monto grande'        => ['999999.99', 99999999],
        ];
    }

    /**
     * Verifica que __toString() retorne la representación correcta.
     *
     * PHP llama automáticamente a __toString() cuando:
     *   - echo $objeto;
     *   - "string {$objeto}"
     *   - print_r($objeto);
     */
    #[Test]
    public function test_to_string_formateado(): void
    {
        $dinero = Dinero::crear('1500.50', Moneda::MXN);

        // Verificar que el string contiene monto + moneda
        $this->assertSame('1500.50 MXN', (string) $dinero);
    }

    /**
     * Verifica que desdeCentavos lance excepción con centavos negativos.
     */
    #[Test]
    public function test_desde_centavos_negativos_lanza_excepcion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Dinero::desdeCentavos(-100, Moneda::MXN);
    }
}
