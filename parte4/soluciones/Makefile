# Makefile — Comandos de desarrollo para wallet-service (Parte 4)
#
# MAKE: Herramienta de automatización que ejecuta "targets" (objetivos).
# ESTE Makefile REEMPLAZA al de la Parte 1: la parte 4 añade Behat,
# cobertura y mutational testing a la pipeline de calidad.
#
# Uso:
#   make test          → TODAS las suites (PHPUnit + Behat + cobertura)
#   make test-unit     → Solo PHPUnit (dominio + aplicación)
#   make test-behat    → Solo Behat (BDD / Gherkin)
#   make coverage      → Cobertura HTML + texto (PCOV)
#   make mutation      → Infection: MSI 85% (calidad de los tests)
#   make stan          → Análisis estático con PHPStan nivel 6
#   make fix           → Auto-formatea código con CS-Fixer
#   make ci            → Ejecuta todo lo anterior (como en CI)
#   make serve         → Inicia servidor de desarrollo
#
# PIRÁMIDE DE TESTING (Parte 4 Lección 4.1):
#
#        /  E2E (Behat)  \   ← pocos, lentos, caros
#       /    Integration    \  ← cuando existan (Parte 5+)
#      /========================\
#     |    Unit (PHPUnit)       | ← muchos, rápidos, baratos  ← BASE
#      \========================/
#
# La regla de oro: SI UN TEST UNITARIO FALLA, los de más arriba
# probablemente también lo harán. Ejecuta siempre de abajo hacia arriba.

# .PHONY le dice a Make que estos targets NO son archivos.
# Sin esto, Make buscaría archivos llamados "test", "stan", etc.
# y si existieran, no ejecutaría el comando.
.PHONY: test test-unit test-behat coverage mutation stan fix fix-check ci serve clean all help

# Variables — evitan repetir rutas largas en cada target.
# El $() de Make reemplaza las variables en tiempo de ejecución.
PHP = php                     # Binario de PHP
PHPUNIT = vendor/bin/phpunit  # Binario de PHPUnit (dentro de vendor/)
BEHAT = vendor/bin/behat      # Binario de Behat
INFECTION = vendor/bin/infection  # Binario de Infection
PHPSTAN = vendor/bin/phpstan  # Binario de PHPStan
FIXER = vendor/bin/php-cs-fixer  # Binario de PHP-CS-Fixer
SYMFONY = symfony             # CLI de Symfony

# ------------------------------------------------------------------------------
# TARGET: test
# EJECUTA TODA LA SUITE (Ejercicio 4.8).
#
# Orden importa (pirámide):
#   1. Unit con cobertura  → los tests rápidos y la cobertura de src/Domain
#   2. Behat               → los escenarios BDD completos (E2E)
#
# --colors=forced: muestra colores siempre (útil en CI, que no es terminal)
# --coverage-text: escribe la cobertura en consola (texto plano)
# --format=pretty: Behat en formato detallado (el progreso usa --format=progress)
# ------------------------------------------------------------------------------
test:
	$(PHP) $(PHPUNIT) --colors=forced --testsuite unit --coverage-text
	$(PHP) $(BEHAT) --format=pretty

# ------------------------------------------------------------------------------
# TARGET: test-unit
# SOLO la capa de Unit (dominio + aplicación).
# Los tests de la base de la pirámide: rápidos y sin dependencias externas.
#
# --testdox: muestra resultados en formato legible (verbose, pero claro)
#
# Ejemplo de salida:
#   App\Domain\RetiroDataProviderTest
#    ✓ Retiro exitoso descuenta saldo                        0.001s
#    ✓ Retiro sin fondos lanza excepción                     0.001s
#   Tests: 16 passed, 16 total
# ------------------------------------------------------------------------------
test-unit:
	$(PHP) $(PHPUNIT) --colors=forced --testsuite unit --testdox

# ------------------------------------------------------------------------------
# TARGET: test-behat
# SOLO Behat (BDD). Ejecuta features/billetera.feature
#
# Cada paso de Gherkin se traduce a un método del FeatureContext.
# Formato progress: . puntos por paso OK, F por fallo (deja la salida corta).
# ------------------------------------------------------------------------------
test-behat:
	$(PHP) $(BEHAT) --format=progress

# ------------------------------------------------------------------------------
# TARGET: coverage
# Cobertura de código con PCOV (Lección 4.5).
#
# --coverage-html=coverage: genera reporte interactivo navegable
# --coverage-text: además escribe el resumen en consola
#
# PCOV es más rápido que Xdebug porque solo registra líneas ejecutadas,
# sin el costo de depuración (ideal para CI).
#
# Ejemplo de salida:
#   src/Domain/Billetera.php  100.0%
#   src/Domain/Dinero.php     100.0%
#   ...                       100.0%
# ------------------------------------------------------------------------------
coverage:
	$(PHP) $(PHPUNIT) --testsuite unit --coverage-html=coverage --coverage-text

# ------------------------------------------------------------------------------
# TARGET: mutation
# Mutational testing con Infection (Lección 4.5 / Ejercicio 4.7).
#
# Infection MUTA tu código (cambia == por !=, 1 por 0, etc.) y re-ejecuta
# tus tests. Un "mutante sobreviviente" = ese cambio NO rompió nada
# = hay una ruta de código que tus tests NO cubren (o no verifican).
#
# MSI (Mutation Score Indicator): % de mutantes matados por los tests.
#   70-85%: aceptable   ·   85%+: excelente (nuestro objetivo)
#
# --show-mutations: imprime cada mutante vivo con su diff
# ------------------------------------------------------------------------------
mutation:
	$(PHP) $(INFECTION) --show-mutations

# ------------------------------------------------------------------------------
# TARGET: stan
# Análisis estático con PHPStan nivel 6.
#
# PHPStan analiza el código SIN ejecutarlo.
# Detecta: tipos incorrectos, argumentos faltantes, nulos no manejados, etc.
#
# Nivel 6 es el equilibrio entre:
#   - Nivel 0: casi nada (no vale la pena)
#   - Nivel 6: cubre la mayoría de bugs comunes (nuestro objetivo)
#   - Nivel 10: máximo, pero demasiado estricto para empezar
#
# --no-progress: no muestra barra de progreso (mejor para CI)
# --ansi: muestra colores ANSI (para terminals que los soporten)
#
# Ejemplo de salida exitosa:
#   [OK] No errors
# ------------------------------------------------------------------------------
stan:
	$(PHP) $(PHPSTAN) analyse src tests --level=6 --no-progress --ansi

# ------------------------------------------------------------------------------
# TARGET: fix
# Auto-formatea el código con PHP-CS-Fixer.
#
# PHP-CS-Fixer reescribe el código para que cumpla el estándar PSR-12.
# En CI se usa --dry-run (no modifica archivos, solo reporta).
# Aquí en local sí modificamos para convenience.
#
# --verbose: muestra qué reglas aplicó.
# --diff: muestra el diff de cada archivo modificado.
# --allow-risky=yes: permite reglas que pueden cambiar comportamiento
#   (ej: agregar return types, strict_types, etc.)
# ------------------------------------------------------------------------------
fix:
	$(PHP) $(FIXER) fix --verbose --diff --allow-risky=yes

# ------------------------------------------------------------------------------
# TARGET: fix-check
# Verifica si hay código sin formatear (sin modificar archivos).
# Usado en CI: si hay cambios pendientes, CI falla.
#
# --dry-run: NO modifica archivos, solo retorna exit code 1 si hay cambios.
# ------------------------------------------------------------------------------
fix-check:
	$(PHP) $(FIXER) fix --dry-run --diff --allow-risky=yes

# ------------------------------------------------------------------------------
# TARGET: ci
# Ejecuta TODA la pipeline de calidad de código.
# Equivalente a lo que GitHub Actions ejecuta en cada PR (Parte 5).
#
# Secuencia:
#   1. Tests completos (¿el código funciona? — PHPUnit + Behat)
#   2. Mutation Score (¿los tests son BUENOS? — Infection MSI 85%)
#   3. PHPStan (¿el código es tipo-seguro y sin bugs sutiles?)
#   4. CS-Fixer dry-run (¿el código es consistente y legible?)
#
# Si CUALQUIERA falla, `make ci` falla. No se sigue al siguiente paso.
# ------------------------------------------------------------------------------
ci: test mutation stan fix-check
	@echo ""
	@echo "══════════════════════════════════════════"
	@echo "  ✅ CI PASSED — Todos los checks verdes"
	@echo "══════════════════════════════════════════"

# ------------------------------------------------------------------------------
# TARGET: serve
# Inicia el servidor de desarrollo de Symfony.
#
# symfony serve usa PHP built-in server (para desarrollo).
# -d: directorio de document root (public/)
# --no-clean: no limpia la caché antes de iniciar
#
# El servidor escucha en https://127.0.0.1:8000
# Symfony CLI genera un certificado SSL automáticamente.
#
# IMPORTANTE: Este servidor NO es para producción.
# En producción usamos Nginx + PHP-FPM (Parte 0 Lección 0.2).
# ------------------------------------------------------------------------------
serve:
	$(SYMFONY) serve -d public --no-clean

# ------------------------------------------------------------------------------
# TARGET: clean
# Limpia caches del proyecto.
#
# cache:clear: limpia el cache de Symfony (var/cache/)
# Esto es útil después de cambiar configuración (services.yaml, etc.)
# ------------------------------------------------------------------------------
clean:
	$(SYMFONY) cache:clear

# ------------------------------------------------------------------------------
# TARGET: all
# Ejecuta todo: limpiar + calidad + servidor.
# Uso: `make all` para empezar una sesión de desarrollo limpia.
# ------------------------------------------------------------------------------
all: clean ci serve

# ------------------------------------------------------------------------------
# TARGET: help
# Muestra todos los targets disponibles con sus descripciones.
# Uso: `make help`
# ------------------------------------------------------------------------------
help:
	@echo "Comandos disponibles para wallet-service (Parte 4):"
	@echo ""
	@echo "  make test        TODAS las suites (unit + behat + cobertura)"
	@echo "  make test-unit   Solo PHPUnit (unit)"
	@echo "  make test-behat  Solo Behat (BDD)"
	@echo "  make coverage    Cobertura PCOV (HTML + texto)"
	@echo "  make mutation    Infection MSI (calidad de tests)"
	@echo "  make stan        Análisis estático con PHPStan nivel 6"
	@echo "  make fix         Auto-formatear código"
	@echo "  make ci          Pipeline completa (test + mutation + stan + fix-check)"
	@echo "  make serve       Iniciar servidor de desarrollo"
	@echo "  make clean       Limpiar cache de Symfony"
	@echo "  make all         Limpiar + CI + servidor"
	@echo ""