# Parte 4 — Testing Profesional: PHPUnit, Behat y Cobertura

> **En esta parte elevas la calidad de tu código a nivel profesional.**
> Aprendes la pirámide de testing, dominas PHPUnit 11 (data providers,
> mocks, fakes, spies), escribes especificaciones ejecutables con Behat
> + Gherkin en español, mides cobertura con PCOV y aplicas mutation
> testing para encontrar los tests que no prueban nada.

---

## Contenido

| Lección | Tema | Herramienta |
|---|---|---|
| 4.1 | La pirámide de testing | Teoría aplicada a PrestaFlow |
| 4.2 | PHPUnit avanzado | Data providers, assertions profundas |
| 4.3 | Test Doubles | Fakes, stubs, mocks, spies |
| 4.4 | Behat + Gherkin | BDD con especificaciones en español |
| 4.5 | Cobertura y Mutational Testing | PCOV, Infection |

**Ejercicios 4.1 – 4.8 · Puntos: 52 · Total acumulado: 240**

---

## 4.1 La pirámide de testing

### Filosofía de la pirámide

```
         ╱╲          E2E / UI (pocos, lentos, caros)
        ╱  ╲         Navegador real, usuario completo
       ╱────╲
      ╱      ╲       Funcionales / Integración (algunos, medianos)
     ╱────────╲      HTTP simulado, BD real
    ╱          ╲
   ╱────────────╲    Unitarios (muchos, rápidos, baratos)
  ╱──────────────╲   Dominio puro, sin I/O
```

| Capa | Velocidad por test | Ejecuta en CI | ¿Qué derriba? |
|---|---|---|---|
| Unit | ~5 ms | cientos en <1s | una regla de negocio |
| Integration | ~50 ms | decenas en <2s | una query o mapper |
| Functional | ~200 ms | decenas en <5s | un flujo HTTP completo |
| E2E / Behat | ~1 s | pocos en <30s | una historia de usuario |

### La regla de oro: el 80% de tus tests deben ser unitarios

¿Por qué tanta insistencia en los unit?

```
Costo de mantenimiento por test (relativo):

  Unit        █                   ← barato, se escribe en minutos
  Integration ████                ← necesita BD real, fixtures
  Functional  ████████            ← necesita HTTP, servicio completo
  E2E         ██████████████      ← frágil, lento, caro

Beneficio de cada unit test: te dice EXACTAMENTE qué se rompió
en 5 milisegundos y sin levantar infraestructura.
```

### Nuestra capa de dominio es un regalo para la pirámide

El dominio de PrestaFlow (`Dinero`, `Billetera`, `Moneda`) es **puro PHP**:
no depende de Symfony, Doctrine ni HTTP. Esto significa que puedes
testearlo con corredores en memoria, sin levantarse Docker.

```
┌──────────────────────────────────────────────────┐
│  test unit de Dinero:                             │
│  $dineros → Dinero::crear(...) → probar resultados │
│  ✅ 0 ms, sin BD, sin framework                    │
└──────────────────────────────────────────────────┘
```

---

## 4.2 PHPUnit avanzado: Data Providers

### El problema: repetir el mismo test con datos distintos

```php
// ❌ MAL: un test para cada caso, duplicando lógica
public function test_deposito_de_100(): void { ... }
public function test_deposito_de_500(): void { ... }
public function test_deposito_de_1000(): void { ... }
```

### La solución: Data Providers

Un **data provider** es un método estático que retorna un array de casos.
PHPUnit ejecuta el test UNA VEZ POR CADA CASO, con los parámetros que
definiste. El nombre del caso aparece en el reporte.

```php
// ✅ BIEN: un test, N casos

/**
 * Cada clave del array es un "caso" con nombre descriptivo.
 * Cada valor es la lista de argumentos que recibirá el test.
 *
 * @return array<string, array{monto: string, esperado: string}>
 */
public static function proveerDepositos(): array
{
    return [
        'depósito pequeño' => ['monto' => '1.00', 'esperado' => '1.00'],
        'depósito mediano' => ['monto' => '500.00', 'esperado' => '500.00'],
        'depósito grande' => ['monto' => '100000.00', 'esperado' => '100000.00'],
    ];
}

#[Test]
#[DataProvider('proveerDepositos')]
public function deposito_incrementa_balance(string $monto, string $esperado): void
{
    // PHPUnit ejecuta este método 3 veces, una por cada caso.
    $billetera = new Billetera('w1', 'u1', Moneda::MXN);
    $billetera->depositar(Dinero::crear($monto, Moneda::MXN), 'test');

    self::assertSame($esperado, $billetera->balance()->formateadoDecimal());
}
```

PHPUnit muestra en el reporte:

```
✅ deposito_incrementa_balance[depósito pequeño] (1.00 → 1.00)
✅ deposito_incrementa_balance[depósito mediano] (500.00 → 500.00)
✅ deposito_incrementa_balance[depósito grande] (100000.00 → 100000.00)
```

Casos límite que TODO data provider de dinero debe incluir:

```php
'con centavos exactos' => ['monto' => '0.05', 'esperado' => '0.05'],
'con muchos decimales' => ['monto' => '1.999', 'esperado' => '2.00'],  // redondeo
'sin decimales' => ['monto' => '10', 'esperado' => '10.00'],
'negativo (debe fallar)' => ['monto' => '-5.00', 'esperado' => 'invalid'],
'cero (debe fallar)' => ['monto' => '0.00', 'esperado' => 'invalid'],
```

---

## 4.3 Test Doubles: Fakes, Stubs, Mocks y Spies

### ¿Qué es un Test Double?

Es un "doble de acción" que sustituye a una dependencia real del sistema
(BD, HTTP externo, cola de mensajes) durante un test. La clasificación
clásica (Gerard Meszaros, *xUnit Test Patterns*):

| Doble | Qué hace | Cómo se crea |
|---|---|---|
| **Dummy** | Cumple la firma pero nunca se usa | `new LoggerNull()` |
| **Fake** | Implementación funcional simplificada | `InMemoryRepository` |
| **Stub** | Retorna datos programados ante llamadas | `$mock->method(...)->willReturn(...)` |
| **Mock** | Verifica que SE LLAMÓ con ciertos parámetros | `$mock->expects($this->once())` |
| **Spy** | Registra llamadas para verificarlas después | `$spy->getInvocations()` |

### Fakes (los ya hiciste en la Parte 3)

```php
// tests/Unit/Application/RealizarDepositoCommandHandlerTest.php
private function idempotenciaFake(): IdempotenciaRepository
{
    return new class implements IdempotenciaRepository {
        private array $claves = [];

        public function existe(string $clave): bool
        {
            return isset($this->claves[$clave]);
        }

        public function registrar(string $clave): void
        {
            $this->claves[$clave] = true;
        }
    };
}
```

**Cuándo usar fakes:** cuando quieres testear el COMPORTAMIENTO del
handler con una implementación mínima pero realista de la dependencia.

### Stubs y Mocks (PHPUnit nativo)

```php
// Stub: preprograma una respuesta sin verificar llamadas
$repositorio = $this->createMock(BilleteraRepository::class);
$repositorio->method('findById')
    ->willReturn($billetera);        // → siempre devuelve la billetera

// Mock: stub + verificación de llamadas
$eventBus = $this->createMock(MessageBusInterface::class);
$eventBus->expects($this->once())                    // MATcher de llamadas
    ->method('dispatch')
    ->with($this->isInstanceOf(TransaccionCompletadaEvent::class))
    ->willReturnCallback(fn ($msg) => new Envelope($msg));
```

Los **matchers** más usados:

```php
$this->once()                                  // exactamente 1 llamada
$this->never()                                 // ninguna llamada
$this->exactly(3)                              // exactamente 3
$this->atLeastOnce()                           // 1 o más
$this->equalTo($valor)                         // argumento igual
$this->isInstanceOf(Clase::class)              // argumento de una clase
$this->callback(fn ($arg) => $arg > 0)         // predicado personalizado
```

### Cuándo NO usar mocks

```php
// ❌ INNEcesario: mockear una clase que no tiene lado externo
$dinero = $this->createMock(Dinero::class);   // NO — Dinero es un VO puro

// ✅ Mejor: usa el objeto real
$dinero = Dinero::crear('100.00', Moneda::MXN);
```

Regla práctica: **mockea solo lo que cruza una frontera del sistema**
(BD, HTTP, cola, tiempo, UUID). El dominio puro se testea real.

---

## 4.4 Behat + Gherkin en español

### Qué es BDD y por qué

BDD (Behavior Driven Development) describe el sistema en **lenguaje de
negocio**: "el usuario deposita dinero y ve su balance". Behat ejecuta
esas descripciones como tests automáticos.

### El archivo .feature (Gherkin)

Los tests BDD viven en archivos `.feature` escritos en **Gherkin**:
un lenguaje casi-natural que Behat puede ejecutar.

```
features/billetera.feature
```

```gherkin
Característica: Depósitos en la billetera
  Como usuario de PrestaFlow
  Quiero depositar dinero en mi billetera
  Para tener saldo disponible para mis transacciones

  Antecedentes:                                   # ← precondiciones de TODOS los escenarios
    Dado que existe una billetera de "ana" en moneda "MXN" con saldo "0.00"

  Escenario: Depósito exitoso incrementa el balance
    Cuando deposito "500.00" MXN con descripción "Nómina"
    Entonces el balance debe ser "500.00"
    Y el total de movimientos debe ser 1
```

### Las palabras mágicas (Gherkin en español)

| Español | Inglés | Significado |
|---|---|---|
| `Característica:` | `Feature:` | Un comportamiento completo |
| `Antecedentes:` | `Background:` | Pasos que se ejecutan antes de cada escenario |
| `Escenario:` | `Scenario:` | Un caso de prueba concreto |
| `Dado` | `Given` | Estado inicial (precondición) |
| `Cuando` | `When` | La acción que se prueba |
| `Entonces` | `Then` | La verificación (assert) |
| `Y` / `Pero` | `And` / `But` | Conjunción del paso anterior |

### El FeatureContext: unir Gherkin con PHP

Behat necesita traducir cada línea de Gherkin a un método PHP. El
`FeatureContext` hace ese puente con **expresiones regulares**:

```php
// features/bootstrap/FeatureContext.php

// La anotación @Given matchea la línea "Dado que existe..."
// (.*?) captura los valores entre comillas.
/**
 * @Given /^que existe una billetera de "([^"]+)" en moneda "([^"]+)" con saldo "([^"]+)"$/
 */
public function creoBilleteraConSaldo(string $usuario, string $moneda, string $saldo): void
{
    // Aquí se ejecuta la LÓGICA: usamos los buses CQRS reales.
    $id = $this->commandBus->dispatch(CrearBilleteraCommand::crear(
        usuarioId: $usuario,
        moneda: $moneda,
    ));
    $this->billeteras[$usuario] = $id;

    if ($saldo !== '0.00') {
        $this->deposito($usuario, $saldo, 'Saldo inicial');
    }
}
```

### ¿Por qué usar el bus real en Behat y no el repositorio?

```
      Gherkin (lenguaje de negocio)
              │
              ▼
   FeatureContext (pasos PHP)
              │
              ▼
   CommandBus/QueryBus  ←──── ESTO ES LO IMPORTANTE
              │
              ▼
   Handlers + Dominio + Repositories (flujo REAL)
```

Si el feature usa los buses, está probando **la cadena completa de
producción** (decidir + ejecutar + persistir + leer) tal como la usa
el API. Eso es un test E2E de verdad, sin HTTP.

---

## 4.5 Cobertura y Mutational Testing

### Cobertura de código (PCOV)

El **coverage** mide cuántas líneas de tu `src/` se ejecutaron durante
los tests:

```
Line:   .........XXXXXXXXXXXXXXXXXXXXXXX............................
        0%                                             100%
        │                                              │
   nada cubierto                              todo cubierto
```

PCOV es el driver de cobertura preferido para PHPUnit 11: rápido y
sin afectar el rendimiento de los tests.

```xml
<!-- phpunit.xml.dist (Parte 4) -->
<source>
    <include>
        <directory>src</directory>
    </include>
</source>

<coverage>
    <report>
        <html outputDirectory="coverage"/>              <!-- reporte visual -->
        <text outputFile="php://stdout" showUncoveredFiles="true"/>
    </report>
</coverage>
```

```bash
./vendor/bin/phpunit --testsuite unit --coverage-text
# ...
# src/Domain/Dinero.php      100.0%
# src/Domain/Billetera.php    94.1%
# src/Domain/Moneda.php       83.3%   ← hay métodos sin probar
```

### La trampa del 100%: el Mutational Testing

Cobertura al 100% NO significa tests buenos. Este test cubre la línea
pero no la verifica:

```php
// ❌ Cubre la línea, no la prueba
public function test_deposito(): void
{
    $billetera = new Billetera('w1', 'u1', Moneda::MXN);
    $billetera->depositar(Dinero::crear('100.00', Moneda::MXN), 'x');
    // ¡sin assert!
}
```

**Mutational Testing** (con Infection) resuelve esto: **muta** tu código
(rompiéndolo a propósito) y comprueba que tus tests DETECTAN la
mutación. Si un test no falla tras mutar el código, ese test no sirve.

```
Código original:    if ($monto->centavos() === 0) throw ...
Código mutado:      if ($monto->centavos() !== 0) throw ...   ← mutante

¿Los tests detectan el cambio? 
  → SÍ (test rojo):  ✅ mutante muerto (el test vale algo)
  → NO (test verde): ☠️ mutante vivo (laguna en los tests)
```

```bash
# Configuración mínima (infection.json5)
{
    "source": { "directories": ["src/Domain"] },
    "minMsi": 70,
    "minCoveredMsi": 75
}
```

```bash
./vendor/bin/infection --show-mutations

# Métrica clave:
#   MSI (Mutation Score Indicator): % de mutantes MUERTOS.
#   MSI 85% → 15% del código puede romperse sin que lo detectes.
```

### Meta de calidad del curso

| Métrica | Umbral |
|---|---|
| Cobertura de `src/Domain` | 100% |
| Cobertura de `src/Application` | ≥ 90% |
| MSI de Infection | ≥ 80% |
| Tests unitarios | todos verdes en < 2s |

---

## Resumen de la Parte 4

| Herramienta | Archivo | Propósito |
|---|---|---|
| Data providers | `tests/Unit/Domain/DineroDataProviderTest.php` | Probar N casos con 1 test |
| Test Doubles | `tests/Unit/Application/DepositoConMocksTest.php` | Stubs, mocks, matchers |
| Gherkin | `features/billetera.feature` | Especificaciones en español |
| FeatureContext | `features/bootstrap/FeatureContext.php` | Pasos Gherkin ↔ buses CQRS |
| behat.yml | `behat.yml` | Configuración del runner |
| Coverage | `phpunit.xml.dist` | HTML + texto con PCOV |

## Comandos de la Parte 4

```bash
# Ejecutar tests unitarios con reporte de cobertura
./vendor/bin/phpunit --testsuite unit --coverage-text

# Generar reporte HTML de cobertura
./vendor/bin/phpunit --testsuite unit --coverage-html coverage

# Ejecutar Behat (especificaciones en español)
./vendor/bin/behat --format=pretty

# Ejecutar Infection (mutational testing)
./vendor/bin/infection --show-mutations
```