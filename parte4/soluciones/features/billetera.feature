# features/billetera.feature — Especificación ejecutable del dominio
#
# GHERKIN: lenguaje humano que se convierte en tests automáticos.
# Los keywords están en ESPAÑOL porque así habla el negocio.
#
# Reglas de oro de BDD:
#   1. Escribe el .feature ANTES del código (especificación viva)
#   2. Cada escenario es UN comportamiento observable
#   3. El lenguaje describe el NEGOCIO, no la implementación
#      (nunca escribas "cuando hago POST /deposito" — eso es detalle técnico)
#
# Glosario de keywords en español:
#   Característica:  → Feature (lo que el sistema hace)
#   Antecedentes:    → Background (precondiciones de cada escenario)
#   Escenario:       → Scenario (un caso concreto)
#   Dado / Cuando / Entonces / Y / Pero  → Given / When / Then / And / But

Característica: Depósitos en la billetera
  Como usuario de PrestaFlow
  Quiero depositar dinero en mi billetera
  Para tener saldo disponible para mis transacciones

  Antecedentes:
    Dado que existe una billetera de "ana" en moneda "MXN" con saldo "0.00"

  Escenario: Depósito exitoso incrementa el balance
    Cuando deposito "500.00" MXN con descripción "Nómina"
    Entonces el balance debe ser "500.00"
    Y el total de movimientos debe ser 1

  Escenario: Depósito pequeño también se registra
    Cuando deposito "1.00" MXN con descripción "Monedas"
    Entonces el balance debe ser "1.00"

  Escenario: Depósito en moneda distinta es rechazado
    Cuando deposito "500.00" USD con descripción "Pago"
    Entonces el sistema debe rechazar la operación
    Y el balance debe seguir siendo "0.00"

Característica: Retiros con fondo limitado
  Como usuario de PrestaFlow
  Quiero retirar dinero de mi billetera
  Pero nunca más de lo que tengo

  Antecedentes:
    Dado que existe una billetera de "bob" en moneda "MXN" con saldo "1000.00"

  Escenario: Retiro válido descuenta el balance
    Cuando retiro "400.00" MXN con descripción "Retiro ATM"
    Entonces el balance debe ser "600.00"

  Escenario: Retiro mayor al saldo es rechazado
    Cuando retiro "1500.00" MXN con descripción "Imposible"
    Entonces el sistema debe rechazar la operación
    Y el balance debe seguir siendo "1000.00"

Característica: Idempotencia de depósitos
  Como sistema financiero
  Quiero ignorar comandos duplicados
  Para no duplicar dinero en la billetera

  Antecedentes:
    Dado que existe una billetera de "carol" en moneda "MXN" con saldo "0.00"

  Escenario: El mismo comando dos veces no duplica el saldo
    Cuando deposito "300.00" MXN con idempotencia "clave-fija"
    Y deposito "300.00" MXN con idempotencia "clave-fija"
    Entonces el balance debe ser "300.00"
    Y el total de movimientos debe ser 1