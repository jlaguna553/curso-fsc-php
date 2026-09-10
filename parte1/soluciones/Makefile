# Makefile — Comandos de desarrollo para wallet-service
#
# MAKE: Herramienta de automatización que ejecuta "targets" (objetivos).
# Cada target es un comando que puedes ejecutar con `make <target>`.
#
# ¿Por qué Makefile?
# Porque memorizar comandos largos de PHPUnit, PHPStan, etc. es propenso a errores.
# Con Make, solo necesitas: make test, make stan, make ci.
#
# Uso:
#   make test          → Ejecuta tests unitarios
#   make stan          → Análisis estático con PHPStan
#   make fix           → Auto-formatea código con CS-Fixer
#   make ci            → Ejecuta todo lo anterior (como en CI)
#   make serve         → Inicia servidor de desarrollo
#   make all           → limpiar + ci + serve

# .PHONY le dice a Make que estos targets NO son archivos.
# Sin esto, Make buscaría archivos llamados "test", "stan", etc.
# y si existieran, no ejecutaría el comando.
.PHONY: test stan fix ci serve clean all help

# Variables — evitan repetir rutas largas en cada target.
# El $() de Make reemplaza las variables en tiempo de ejecución.
PHP = php                     # Binario de PHP
PHPUNIT = vendor/bin/phpunit  # Binario de PHPUnit (dentro de vendor/)
PHPSTAN = vendor/bin/phpstan  # Binario de PHPStan
FIXER = vendor/bin/php-cs-fixer  # Binario de PHP-CS-Fixer
SYMFONY = symfony             # CLI de Symfony

# ------------------------------------------------------------------------------
# TARGET: test
# Ejecuta todos los tests del proyecto.
#
# --colors=forced: muestra colores siempre (útil en CI, que no es terminal)
# --testsuite unit: solo ejecuta la suite "unit" (los más rápidos)
# --testdox: muestra resultados en formato legible (verbose, pero claro)
#
# Ejemplo de salida:
#   App\Domain\DineroTest
#    ✓ Money creation from cents                        0.002s
#    ✓ Money addition same currency                     0.001s
#    ✓ Money subtraction insufficient funds             0.001s
#   Tests: 15 passed, 15 total
# ------------------------------------------------------------------------------
test:
	$(PHP) $(PHPUNIT) --colors=forced --testsuite unit --testdox

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
#
# Ejemplo de salida con errores:
#   ------ --------------------------------------------------------------- 
#   Line   src/Domain/Billetera.php
#   ------ --------------------------------------------------------------- 
#   42     Method Billetera::balance() should return Dinero but returns int.
#   ------ --------------------------------------------------------------- 
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
# Equivalente a lo que GitHub Actions ejecuta en cada PR.
#
# Secuencia:
#   1. Tests (la cosa más importante: ¿el código funciona?)
#   2. PHPStan (¿el código es类型安全 y no tiene bugs sutiles?)
#   3. CS-Fixer dry-run (¿el código es consistente y legible?)
#
# Si CUALQUIERA falla, `make ci` falla. No se sigue al siguiente paso.
# ------------------------------------------------------------------------------
ci: test stan fix-check
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
	@echo "Comandos disponibles para wallet-service:"
	@echo ""
	@echo "  make test        Ejecutar tests unitarios"
	@echo "  make stan        Análisis estático con PHPStan nivel 6"
	@echo "  make fix         Auto-formatear código"
	@echo "  make ci          Pipeline completa (test + stan + fix-check)"
	@echo "  make serve       Iniciar servidor de desarrollo"
	@echo "  make clean       Limpiar cache de Symfony"
	@echo "  make all         Limpiar + CI + servidor"
	@echo ""
