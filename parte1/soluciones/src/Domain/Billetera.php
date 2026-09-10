<?php
// src/Domain/Billetera.php — Aggregate Root del dominio de billeteras
//
// AGGREGATE ROOT: Objeto que encapsula un conjunto de entidades/Value Objects
// y garantiza la consistencia de los datos dentro de él.
//
// La Billetera es el Aggregate Root porque:
//   1. Contiene la lista de Movimientos (Value Objects)
//   2. Calcula el balance basándose en los movimientos
//   3. Enforce las reglas de negocio (fondos suficientes, moneda válida)
//   4. Solo se puede acceder/modificar a través de la Billetera
//
// Reglas de negocio implementadas:
//   - No se puede depositar monto cero
//   - No se puede retirar más que el saldo actual
//   - Todos los movimientos deben ser en la misma moneda
//   - El balance se calcula recursivamente desde los movimientos

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\FondosInsuficientes;
use DateTimeImmutable;

/**
 * Billetera digital que mantiene un registro de movimientos financieros.
 *
 * Diseño hexagonal:
 *   - Esta clase NO depende de Symfony, Doctrine, ni ningún framework
 *   - Es puro PHP: se puede testear sin base de datos, HTTP, ni containers
 *   - Se puede copiar a otro proyecto sin dependencias externas
 *
 * Esto es lo que significa "Dominio Rico": la lógica de negocio vive aquí,
 * no en controllers ni en repositorios.
 */
final class Billetera
{
    /**
     * @param string              $id         ID único de la billetera (UUID)
     * @param string              $usuarioId  ID del propietario
     * @param Moneda              $moneda     Moneda de la billetera
     * @param array<Movimiento>   $movimientos Lista de movimientos (registros históricos)
     * @param DateTimeImmutable   $creadoEn   Timestamp de creación
     */
    public function __construct(
        public readonly string $id,
        public readonly string $usuarioId,
        public readonly Moneda $moneda,
        private array $movimientos = [],
        public readonly DateTimeImmutable $creadoEn = new DateTimeImmutable(),
    ) {
        // El array de movimientos se pasa como parámetro con valor default [].
        // Esto permite crear billeteras nuevas (sin movimientos) o reconstruirlas
        // desde base de datos (con movimientos existentes).
        //
        // NOTA: El parámetro $movimientos es private, no readonly.
        // ¿Por qué? Porque necesitamos agregar movimientos internamente.
        // Pero como es private, NINGÚN código externo puede modificarlo
        // directamente. Solo se modifica a través de depositar() y retirar().
    }

    /**
     * Calcula el balance actual de la billetera.
     *
     * El balance se deriva de los movimientos (no se almacena separadamente).
     * Esto garantiza consistencia: si los movimientos cambian, el balance cambia.
     *
     * Fórmula: balance = Σ(depositos) - Σ(retiros)
     *
     * @return Dinero Balance actual de la billetera
     */
    public function balance(): Dinero
    {
        // Empezamos con cero centavos en la moneda de la billetera.
        // Este es nuestro "punto de partida" para la suma.
        $totalCentavos = 0;

        // Iteramos sobre cada movimiento usando un foreach.
        // Cada movimiento puede sumar (depósito) o restar (retiro) al total.
        foreach ($this->movimientos as $movimiento) {
            // tipoMovimiento().esAcreditacion() retorna true para depósitos.
            // Si es depósito, sumamos centavos. Si es retiro, restamos.
            if ($movimiento->tipo->esAcreditacion()) {
                $totalCentavos += $movimiento->monto->centavos();
            } else {
                $totalCentavos -= $movimiento->monto->centavos();
            }
        }

        // Crear un Dinero con el total calculado.
        // NOTA: Si el total es negativo, algo salió mal en las validaciones
        // de retiro. Esto es un "fail fast" adicional.
        return Dinero::desdeCentavos($totalCentavos, $this->moneda);
    }

    /**
     * Registra un depósito en la billetera.
     *
     * Este es un COMMAND METHOD: ejecuta una acción de negocio.
     * Valida reglas de negocio y modifica el estado interno.
     *
     * @param Dinero $monto       Monto a depositar (debe ser positivo)
     * @param string $descripcion Descripción legible del depósito
     *
     * @return Movimiento El movimiento registrado (para auditoría/log)
     *
     * @throws \InvalidArgumentException Si el monto es cero o de moneda incorrecta
     */
    public function depositar(Dinero $monto, string $descripcion): Movimiento
    {
        // REGLA 1: No permitir depósitos de monto cero.
        // Un depósito de $0 no tiene sentido de negocio y puede indicar error.
        if ($monto->centavos() === 0) {
            throw new \InvalidArgumentException(
                "No se puede depositar un monto de cero. Use un monto mayor a cero."
            );
        }

        // REGLA 2: El depósito debe ser en la misma moneda que la billetera.
        // Esto previene errores como depositar USD en una billetera MXN.
        if ($monto->moneda() !== $this->moneda) {
            throw new \InvalidArgumentException(
                "No se puede depositar {$monto->moneda()->value} "
                . "en una billetera de {$this->moneda->value}"
            );
        }

        // Crear el movimiento de depósito.
        // El factory method deposito() establece el tipo automáticamente.
        $movimiento = Movimiento::deposito(
            // uniqid() genera un ID pseudo-único. En producción usaríamos
            // ramsey/uuid o Symfony UID, pero para el curso es suficiente.
            id: uniqid('mov_', more_entropy: true),
            monto: $monto,
            descripcion: $descripcion,
        );

        // Agregar el movimiento al array interno.
        // array_push() agrega al final del array.
        // Como $this->movimientos es private, este es el ÚNICO lugar
        // donde se modifican los movimientos (Single Responsibility).
        $this->movimientos[] = $movimiento;

        // Retornar el movimiento creado para que el caller pueda
        // hacer logging, notificaciones, etc.
        return $movimiento;
    }

    /**
     * Registra un retiro en la billetera.
     *
     * Valida que haya fondos suficientes antes de procesar.
     *
     * @param Dinero $monto       Monto a retirar (debe ser positivo)
     * @param string $descripcion Descripción legible del retiro
     *
     * @return Movimiento El movimiento registrado
     *
     * @throws FondosInsuficientes Si el saldo es menor al monto solicitado
     * @throws \InvalidArgumentException Si el monto es cero o de moneda incorrecta
     */
    public function retirar(Dinero $monto, string $descripcion): Movimiento
    {
        // REGLA 1: No permitir retiros de monto cero.
        if ($monto->centavos() === 0) {
            throw new \InvalidArgumentException(
                "No se puede retirar un monto de cero."
            );
        }

        // REGLA 2: El retiro debe ser en la misma moneda que la billetera.
        if ($monto->moneda() !== $this->moneda) {
            throw new \InvalidArgumentException(
                "No se puede retirar {$monto->moneda()->value} "
                . "de una billetera de {$this->moneda->value}"
            );
        }

        // REGLA 3: Verificar fondos suficientes.
        // Calculamos el balance actual y comparamos con el monto solicitado.
        // Si el balance es menor, lanzamos FondosInsuficientes (excepción de dominio).
        // Esta excepción carry datos (saldo actual, monto solicitado, déficit)
        // para que el controller pueda retornar una respuesta descriptiva.
        $balanceActual = $this->balance();
        if ($balanceActual->esMenorOIgualQue($monto)) {
            throw new FondosInsuficientes(
                saldoActual: $balanceActual,
                montoSolicitado: $monto,
            );
        }

        // Crear el movimiento de retiro.
        $movimiento = Movimiento::retiro(
            id: uniqid('mov_', more_entropy: true),
            monto: $monto,
            descripcion: $descripcion,
        );

        // Agregar al historial.
        $this->movimientos[] = $movimiento;

        return $movimiento;
    }

    /**
     * Retorna el historial completo de movimientos.
     *
     * @return array<Movimiento> Copia del array de movimientos
     */
    public function movimientos(): array
    {
        // Retornamos una copia del array para evitar que el caller
        // modifique el array interno directamente.
        // Sin esto, $billetera->movimientos()[] = $movimiento
        // modificaría el array interno sin pasar por las validaciones.
        return $this->movimientos;
    }

    /**
     * Cuenta el total de movimientos registrados.
     *
     * @return int Número de movimientos
     */
    public function totalMovimientos(): int
    {
        return count($this->movimientos);
    }
}
