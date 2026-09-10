<?php
// src/Domain/Dinero.php — Value Object de dinero
//
// VALUE OBJECT: Objeto inmutable identificado por sus valores, no por ID.
// Dos instancias de Dinero con el mismo monto y moneda son equivalentes:
//   Dinero::crear(1500, Moneda::MXN) === Dinero::crear(1500, Moneda::MXN)
//
// ¿Por qué Value Object y no un simple array/string?
//   1. Validez garantizada: no puedes crear Dinero con monto negativo
//   2. Operaciones encapsuladas: sumar, restar, comparar
//   3. Inmutabilidad: modificar el monto crea un NUEVO objeto
//   4. Type safety: no puedes sumar Dinero MXN con Dinero EUR sin conversión
//
// Diseño: Trabajamos internamente en centavos (integer) para evitar
// errores de punto flotante. El usuario ve "1500.00", el sistema almacena 150000.

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Exception\MonedaNoSoportada;

/**
 * Value Object inmutable que representa una cantidad de dinero.
 *
 * Reglas de negocio:
 *   - El monto siempre se almacena en centavos (entero)
 *   - No puede ser negativo (usar FondosInsuficientes para esa validación)
 *   - Las monedas se validan contra el enum Moneda
 *   - La suma de dos Dinero solo funciona si tienen la misma moneda
 */
final readonly class Dinero
{
    /**
     * Constructor privado: solo se puede crear mediante los métodos estáticos
     * crear() o desdeCentavos(). Esto fuerza al consumidor a pasar por la
     * validación de dominio.
     *
     * @param int    $centavos  Cantidad en centavos (entero positivo)
     * @param Moneda $moneda    Moneda del monto
     */
    private function __construct(
        private int $centavos,
        private Moneda $moneda,
    ) {
        // El constructor es privado + validación interna = factory pattern.
        // Nadie puede crear un Dinero inválido sin pasar por crear() o desdeCentavos().
    }

    /**
     * Crea una instancia de Dinero desde un monto decimal (string).
     *
     * Recibe el monto como string para evitar problemas de float:
     *   Dinero::crear('1500.00', Moneda::MXN)  // ✅ Correcto
     *   Dinero::crear(1500.00, Moneda::MXN)     // ❌ Float: impreciso
     *
     * @param string $monto Monto en formato decimal (ej: "1500.00")
     * @param Moneda $moneda Moneda del monto
     *
     * @return self Nueva instancia de Dinero
     *
     * @throws \InvalidArgumentException Si el monto no es un número válido o es negativo
     */
    public static function crear(string $monto, Moneda $moneda): self
    {
        // filter_var valida que el string sea un número flotante válido.
        // Retorna false si no lo es (ej: "abc", "", null).
        $valor = filter_var($monto, FILTER_VALIDATE_FLOAT);

        // Si filter_var retorna false, el input es inválida.
        // La triple negación (!!false) convierte a boolean, pero aquí usamos
        // la comparación explícita para claridad.
        if ($valor === false) {
            throw new \InvalidArgumentException(
                "Monto inválido: '{$monto}'. Se esperaba un número decimal positivo."
            );
        }

        // Un depósito no puede ser negativo. Para retiros negativos,
        // usamos otro método o patrón (esto se verá en la Parte 2).
        if ($valor < 0) {
            throw new \InvalidArgumentException(
                "El monto no puede ser negativo: '{$monto}'. Use DesdeCentavos() con enteros."
            );
        }

        // Convertir a centavos usando round() para evitar:
        //   1.005 * 100 = 100.49999... → 100 (sin round) vs 100 (con round)
        // El resultado de round() es float, por eso castamos a int.
        $centavos = (int) round($valor * 100);

        return new self($centavos, $moneda);
    }

    /**
     * Crea una instancia de Dinero desde centavos (entero).
     *
     * Método alternativo cuando ya se tiene el monto en centavos.
     * Útil para reconstruir objetos desde base de datos.
     *
     * @param int    $centavos Cantidad en centavos
     * @param Moneda $moneda   Moneda del monto
     *
     * @return self Nueva instancia de Dinero
     *
     * @throws \InvalidArgumentException Si los centavos son negativos
     */
    public static function desdeCentavos(int $centavos, Moneda $moneda): self
    {
        if ($centavos < 0) {
            throw new \InvalidArgumentException(
                "Los centavos no pueden ser negativos: {$centavos}"
            );
        }

        return new self($centavos, $moneda);
    }

    /**
     * Retorna el monto en centavos (entero).
     *
     * Este es el valor almacenado internamente.
     * Para mostrar al usuario, usar formateadoDecimal().
     *
     * @return int Cantidad en centavos
     */
    public function centavos(): int
    {
        return $this->centavos;
    }

    /**
     * Retorna el monto en formato decimal (string).
     *
     * Convierte centavos a decimal para visualización:
     *   150000 centavos → "1500.00"
     *
     * ¿Por qué retorna string y no float?
     * Porque el formato "1500.00" es exacto. El float 1500.00 puede
     * ser internamente 1499.99999999... en algunos contextos.
     *
     * @return string Monto en formato decimal con 2 decimales
     */
    public function formateadoDecimal(): string
    {
        // number_format convierte 150000 → "1,500.00" con comas.
        // El segundo parámetro (2) = decimales.
        // El tercer parámetro ('') = separador de miles vacío.
        // El cuarto parámetro ('.') = separador decimal.
        // Nos quedamos solo con la parte decimal: "1500.00"
        return number_format($this->centavos / 100, 2, '.', '');
    }

    /**
     * Retorna la moneda de este dinero.
     *
     * @return Moneda Enum de la moneda
     */
    public function moneda(): Moneda
    {
        return $this->moneda;
    }

    /**
     * Suma dos instancias de Dinero.
     *
     * Solo permite sumar si tienen la misma moneda.
     * Esto es una regla de dominio: no puedes sumar MXN + USD.
     *
     * @param Dinero $otro El otro monto a sumar
     *
     * @return self Nueva instancia con la suma (inmutabilidad: no modifica el original)
     *
     * @throws \InvalidArgumentException Si las monedas no coinciden
     */
    public function sumar(Dinero $otro): self
    {
        // Verificar que ambas cantidades tengan la misma moneda.
        // Si no, lanzamos excepción. Esto previene errores silenciosos
        // como sumar 1500 MXN + 100 USD = 1600 ??? (sin conversión)
        if ($this->moneda !== $otro->moneda) {
            throw new \InvalidArgumentException(
                "No se pueden sumar montos de monedas distintas: "
                . "{$this->moneda->value} + {$otro->moneda->value}"
            );
        }

        // Crear nueva instancia (inmutabilidad).
        // El original NO se modifica. Esta es la diferencia clave
        // entre Value Objects y objetos mutables.
        return new self(
            $this->centavos + $otro->centavos,
            $this->moneda,
        );
    }

    /**
     * Resta dos instancias de Dinero.
     *
     * No permite que el resultado sea negativo (lanza FondosInsuficientes).
     * Para permitir saldo negativo, usar restarSinLimite().
     *
     * @param Dinero $otro El monto a restar
     *
     * @return self Nueva instancia con la resta
     *
     * @throws \InvalidArgumentException Si las monedas no coinciden
     * @throws Exception\FondosInsuficientes Si el resultado sería negativo
     */
    public function restar(Dinero $otro): self
    {
        if ($this->moneda !== $otro->moneda) {
            throw new \InvalidArgumentException(
                "No se pueden restar montos de monedas distintas: "
                . "{$this->moneda->value} - {$otro->moneda->value}"
            );
        }

        // Calcular la diferencia en centavos
        $diferencia = $this->centavos - $otro->centavos;

        // Si la diferencia es negativa, no hay fondos suficientes.
        // Usamos exception de dominio (no genérica) para que el
        // manejador de errores pueda distinguir este caso.
        if ($diferencia < 0) {
            throw new Exception\FondosInsuficientes(
                saldoActual: $this,
                montoSolicitado: $otro,
            );
        }

        return new self($diferencia, $this->moneda);
    }

    /**
     * Compara si este dinero es igual a otro (mismo monto y moneda).
     *
     * PHP 8.2 permite readonly classes en comparaciones de objetos.
     * Sin este método, dos Dinero con el mismo valor serían "diferentes"
     * porque PHP compara por referencia (instancia) por defecto.
     *
     * @param Dinero $otro El otro dinero a comparar
     *
     * @return bool true si ambos tienen el mismo monto y moneda
     */
    public function esIgualA(Dinero $otro): bool
    {
        return $this->centavos === $otro->centavos
            && $this->moneda === $otro->moneda;
    }

    /**
     * Compara si este dinero es mayor que otro.
     *
     * Solo compara dentro de la misma moneda.
     *
     * @param Dinero $otro El otro dinero a comparar
     *
     * @return bool true si este monto es mayor
     */
    public function esMayorQue(Dinero $otro): bool
    {
        if ($this->moneda !== $otro->moneda) {
            throw new \InvalidArgumentException(
                "No se pueden comparar montos de monedas distintas"
            );
        }

        return $this->centavos > $otro->centavos;
    }

    /**
     * Compara si este dinero es menor o igual que otro.
     *
     * @param Dinero $otro El otro dinero a comparar
     *
     * @return bool true si este monto es menor o igual
     */
    public function esMenorOIgualQue(Dinero $otro): bool
    {
        if ($this->moneda !== $otro->moneda) {
            throw new \InvalidArgumentException(
                "No se pueden comparar montos de monedas distintas"
            );
        }

        return $this->centavos <= $otro->centavos;
    }

    /**
     * Representación string del dinero.
     *
     * PHP llama automáticamente a __toString() cuando se hace echo o concatena.
     * Ejemplo: echo $dinero → "1500.00 MXN"
     *
     * @return string Representación legible del dinero
     */
    public function __toString(): string
    {
        return "{$this->formateadoDecimal()} {$this->moneda->value}";
    }
}
