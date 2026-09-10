<?php
// loan-service/tests/EvaluadorElegibilidadTest.php — TDD de la regla de negocio
//
# El activo más valioso del loan-service es su lógica de puntaje.
# Se testea sin RabbitMQ, sin PDO, sin Docker: PHPUnit puro.

declare(strict_types=1);

namespace LoanService\Tests;

use LoanService\Application\EvaluadorElegibilidad;
use LoanService\Domain\ResultadoElegibilidad;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifica el motor de puntaje de elegibilidad.
 */
final class EvaluadorElegibilidadTest extends TestCase
{
    private EvaluadorElegibilidad $evaluador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluador = new EvaluadorElegibilidad();
    }

    #[Test]
    public function deposito_fuerte_mxn_con_balance_alto_aprueba(): void
    {
        // Evento típico: depósito de 3000 MXN, balance 8500 MXN.
        $evento = $this->eventoDeposito('3000.00', '8500.00');

        $resultado = $this->evaluador->evaluar($evento);

        // 30 (depósito alto) + 40 (balance alto) + 15 (MXN) = 85 → APROBADO
        self::assertSame(85, $resultado->puntaje);
        self::assertSame(ResultadoElegibilidad::APROBADO, $resultado->recomendacion);
        self::assertTrue($resultado->fueAprobado());
    }

    #[Test]
    public function deposito_bajo_rechaza(): void
    {
        // Depósito pequeño en USD con balance bajo.
        $evento = $this->eventoDeposito('50.00', '120.00', 'USD');

        $resultado = $this->evaluador->evaluar($evento);

        // 10 (depósito < 1000) + 0 (balance < 1000) + 0 (no MXN) = 10 → NO
        self::assertSame(10, $resultado->puntaje);
        self::assertSame(ResultadoElegibilidad::NO_ELEGIBLE, $resultado->recomendacion);
    }

    #[Test]
    public function retiro_penaliza_fuertemente(): void
    {
        // Un retiro grande destroza el puntaje aunque el balance sea alto.
        $evento = [
            'event_type' => 'transaccion.completada',
            'payload' => [
                'transaccion_id' => 'tx-retiro-1',
                'tipo' => 'retiro',
                'monto' => '2000.00',
                'moneda' => 'MXN',
                'balance_nuevo' => '4500.00',
            ],
        ];

        $resultado = $this->evaluador->evaluar($evento);

        // 0 (no es depósito) + 20 (balance 4500) + 15 (MXN) - 30 (retiro) = 5
        self::assertSame(5, $resultado->puntaje);
        self::assertSame(ResultadoElegibilidad::NO_ELEGIBLE, $resultado->recomendacion);
    }

    #[Test]
    public function evento_malformado_no_rompe(): void
    {
        // Un evento sin payload no debe tirar el worker.
        $resultado = $this->evaluador->evaluar([]);

        self::assertSame(ResultadoElegibilidad::NO_ELEGIBLE, $resultado->recomendacion);
        self::assertGreaterThanOrEqual(0, $resultado->puntaje);
        self::assertLessThanOrEqual(100, $resultado->puntaje);
    }

    #[Test]
    public function puntaje_limita_en_cien_y_cero(): void
    {
        // Evento extremo: monto y balance gigantescos nunca pasan de 100.
        $evento = $this->eventoDeposito('9999999.00', '9999999.00');

        $resultado = $this->evaluador->evaluar($evento);

        self::assertSame(100, $resultado->puntaje);

        // Y un retiro extremo nunca baja de 0.
        $eventoRetiro = [
            'payload' => [
                'tipo' => 'retiro',
                'monto' => '9999999.00',
                'moneda' => 'EUR',
                'balance_nuevo' => '0.00',
            ],
        ];

        $resultadoRetiro = $this->evaluador->evaluar($eventoRetiro);

        self::assertSame(0, $resultadoRetiro->puntaje);
    }

    /**
     * Construye un evento de depósito con los campos mínimos.
     *
     * @return array<string, mixed> Evento en formato contrato
     */
    private function eventoDeposito(string $monto, string $balance, string $moneda = 'MXN'): array
    {
        return [
            'event_id' => 'evt-' . uniqid(),
            'event_type' => 'transaccion.completada',
            'timestamp' => '2026-09-10T12:00:00Z',
            'payload' => [
                'transaccion_id' => 'tx-' . uniqid(),
                'billetera_id' => 'wal-1',
                'tipo' => 'deposito',
                'monto' => $monto,
                'moneda' => $moneda,
                'balance_nuevo' => $balance,
            ],
        ];
    }
}