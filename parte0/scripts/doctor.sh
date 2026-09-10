#!/usr/bin/env bash
# ==============================================================================
# doctor.sh — Verificador de entorno de desarrollo para FSC-PHP
# ==============================================================================
# Este script verifica que todas las herramientas necesarias estén instaladas
# y configuradas correctamente antes de comenzar el curso.
#
# Uso:  bash parte0/scripts/doctor.sh
# Salida: JSON legible por humanos + máquina con el estado de cada herramienta.
#
# Basado en el patrón "diagnóstico de entorno" de Full Stack Open.
# ==============================================================================

# Salir inmediatamente si cualquier comando falla (excepto donde se maneja)
set -euo pipefail

# Color para mensajes (solo si terminal lo soporta)
if [[ -t 1 ]]; then
  GREEN='\033[0;32m'
  RED='\033[0;31m'
  YELLOW='\033[0;33m'
  NC='\033[0m'  # No Color — reset
else
  GREEN=''
  RED=''
  YELLOW=''
  NC=''
fi

# Contadores para el resumen final
PASS=0
FAIL=0
WARN=0

# ==============================================================================
# Funciones auxiliares
# ==============================================================================

# print_check: imprime el resultado de una verificación
# Argumentos:
#   $1 = nombre de la herramienta (ej. "PHP")
#   $2 = "pass", "fail", o "warn"
#   $3 = mensaje con la versión o razón
print_check() {
  local name="$1"
  local status="$2"
  local message="$3"

  case "$status" in
    pass)
      echo -e "  ${GREEN}✓${NC} ${name}: ${message}"
      PASS=$((PASS + 1))
      ;;
    fail)
      echo -e "  ${RED}✗${NC} ${name}: ${message}"
      FAIL=$((FAIL + 1))
      ;;
    warn)
      echo -e "  ${YELLOW}⚠${NC} ${name}: ${message}"
      WARN=$((WARN + 1))
      ;;
  esac
}

# version_ge: compara dos versiones semánticas
# Retorna 0 si $1 >= $2, 1 en caso contrario
# Uso: version_ge "8.3.6" "8.3.0" → true
version_ge() {
  # sort -V -C comprueba si la entrada ya está en orden ascendente.
  # Para verificar $1 >= $2, el menor de los dos ($2) debe ir primero.
  printf '%s\n%s' "$2" "$1" | sort -V -C
}

# ==============================================================================
# Verificaciones
# ==============================================================================

echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║   FSC-PHP — Verificador de Entorno de Desarrollo    ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""

# --- PHP 8.3+ ---------------------------------------------------------------
# PHP es el runtime principal. Necesitamos 8.3+ por:
# - Typed class constants (8.3 feature)
# - json_validate() nativo
# - Property hooks preparación (8.4, mencionado en curso)
if command -v php &> /dev/null; then
  PHP_VERSION=$(php -r 'echo PHP_VERSION;')
  if version_ge "$PHP_VERSION" "8.3.0"; then
    print_check "PHP" "pass" "${PHP_VERSION} (cumple requisito ≥8.3)"
  else
    print_check "PHP" "fail" "${PHP_VERSION} (se requiere ≥8.3.0)"
  fi
else
  print_check "PHP" "fail" "No encontrado. Instalar: https://php.net/downloads.php"
fi

# --- Composer 2.7+ ----------------------------------------------------------
# Composer gestiona dependencias PHP. 2.7+ tiene soporte completo de
# plugins Flex y reproducibilidad con lock files.
if command -v composer &> /dev/null; then
  COMPOSER_VERSION=$(composer --version 2>/dev/null | grep -oP '[\d]+\.[\d]+\.[\d]+')
  if version_ge "$COMPOSER_VERSION" "2.7.0"; then
    print_check "Composer" "pass" "${COMPOSER_VERSION} (cumple requisito ≥2.7)"
  else
    print_check "Composer" "warn" "${COMPOSER_VERSION} (funcional, pero recomendado ≥2.7)"
  fi
else
  print_check "Composer" "fail" "No encontrado. Instalar: https://getcomposer.org"
fi

# --- Symfony CLI -------------------------------------------------------------
# Symfony CLI provee `symfony serve` (desarrollo local) y `symfony new`
# para crear proyectos. No es estrictamente necesario pero acelera el flujo.
if command -v symfony &> /dev/null; then
  SYMFONY_VERSION=$(symfony version 2>/dev/null | grep -oP '[\d]+\.[\d]+\.[\d]+')
  print_check "Symfony CLI" "pass" "${SYMFONY_VERSION}"
else
  print_check "Symfony CLI" "warn" "No encontrado. Instalar: https://symfony.com/download"
  echo "         (El curso puede usarse con solo PHP + Composer)"
fi

# --- Git ---------------------------------------------------------------------
# Git es obligatorio para el flujo de trabajo del curso (commits convencionales,
# branches, tags de checkpoint).
if command -v git &> /dev/null; then
  GIT_VERSION=$(git --version | grep -oP '[\d]+\.[\d]+\.[\d]+')
  GIT_USER=$(git config user.name 2>/dev/null || echo "")
  GIT_EMAIL=$(git config user.email 2>/dev/null || echo "")

  if [[ -n "$GIT_USER" && -n "$GIT_EMAIL" ]]; then
    print_check "Git" "pass" "${GIT_VERSION} (user: ${GIT_USER} <${GIT_EMAIL}>)"
  else
    print_check "Git" "warn" "${GIT_VERSION} (instalado pero sin configurar user.name/email)"
    echo "         Ejecuta: git config --global user.name 'Tu Nombre'"
    echo "                  git config --global user.email 'tu@email.com'"
  fi
else
  print_check "Git" "fail" "No encontrado. Instalar: https://git-scm.com"
fi

# --- Docker ------------------------------------------------------------------
# Docker se usa a partir de la Parte 0 Lección 0.2 para la infraestructura.
# Verificamos tanto la instalación como que el daemon esté corriendo.
if command -v docker &> /dev/null; then
  DOCKER_VERSION=$(docker --version 2>/dev/null | grep -oP '[\d]+\.[\d]+\.[\d]+' | head -1)

  # Verificar que el daemon Docker esté corriendo
  if docker info &> /dev/null; then
    print_check "Docker" "pass" "${DOCKER_VERSION} (daemon corriendo)"
  else
    print_check "Docker" "warn" "${DOCKER_VERSION} (instalado pero daemon no corriendo)"
    echo "         Ejecuta: sudo systemctl start docker"
  fi
else
  print_check "Docker" "fail" "No encontrado. Instalar: https://docs.docker.com/get-docker/"
fi

# --- Docker Compose ----------------------------------------------------------
# Docker Compose orquesta los contenedores. V2 viene incluido con Docker Desktop
# o como plugin `docker compose` (sin guion).
if docker compose version &> /dev/null; then
  COMPOSE_VERSION=$(docker compose version --short 2>/dev/null || echo "v2")
  print_check "Docker Compose" "pass" "${COMPOSE_VERSION}"
elif command -v docker-compose &> /dev/null; then
  COMPOSE_LEGACY=$(docker-compose --version 2>/dev/null | grep -oP '[\d]+\.[\d]+\.[\d]+')
  print_check "Docker Compose" "warn" "${COMPOSE_LEGACY} (versión legacy, usar 'docker compose' v2)"
else
  print_check "Docker Compose" "fail" "No encontrado. Incluido con Docker Desktop o como plugin."
fi

# --- Node.js (opcional, para herramientas de documentación) -------------------
# Node.js se usa opcionalmente para generar diagramas y servidor de docs.
if command -v node &> /dev/null; then
  NODE_VERSION=$(node --version 2>/dev/null)
  print_check "Node.js" "pass" "${NODE_VERSION} (opcional, para docs)"
else
  print_check "Node.js" "warn" "No encontrado (opcional, solo para documentación)"
fi

# ==============================================================================
# Resumen
# ==============================================================================

echo ""
echo "═══════════════════════════════════════════════════════"
echo "  Resumen: ${PASS} verificados | ${WARN} advertencias | ${FAIL} fallidos"
echo "═══════════════════════════════════════════════════════"

if [[ $FAIL -eq 0 ]]; then
  echo -e "  ${GREEN}Veredicto: READY${NC} — Tu entorno está listo para el curso."
  echo ""
  echo "  Siguiente paso: abre la Parte 0 y comienza la Lección 0.1"
  echo ""
  VERDICT="READY"
else
  echo -e "  ${RED}Veredicto: NOT READY${NC} — Corrige los ${FAIL} errores antes de continuar."
  echo ""
  VERDICT="NOT_READY"
fi

# ==============================================================================
# Salida JSON (para integración con CI/-scripts)
# ==============================================================================
# Este bloque genera JSON que puede ser parseado por herramientas.
# Útil para pipelines de CI que verifican el entorno automáticamente.

echo ""
echo "--- Salida JSON ---"
cat <<EOF
{
  "php": {
    "installed": $( [[ -v PHP_VERSION ]] && echo "true" || echo "false" ),
    "version": "${PHP_VERSION:-not_found}",
    "meets_requirement": $( [[ -v PHP_VERSION ]] && version_ge "$PHP_VERSION" "8.3.0" && echo "true" || echo "false" )
  },
  "composer": {
    "installed": $( command -v composer &> /dev/null && echo "true" || echo "false" ),
    "version": "${COMPOSER_VERSION:-not_found}",
    "meets_requirement": $( [[ -v COMPOSER_VERSION ]] && version_ge "$COMPOSER_VERSION" "2.7.0" && echo "true" || echo "false" )
  },
  "symfony_cli": {
    "installed": $( command -v symfony &> /dev/null && echo "true" || echo "false" ),
    "version": "${SYMFONY_VERSION:-not_found}"
  },
  "git": {
    "installed": $( command -v git &> /dev/null && echo "true" || echo "false" ),
    "version": "${GIT_VERSION:-not_found}",
    "configured": $( [[ -n "${GIT_USER:-}" && -n "${GIT_EMAIL:-}" ]] && echo "true" || echo "false" )
  },
  "docker": {
    "installed": $( command -v docker &> /dev/null && echo "true" || echo "false" ),
    "version": "${DOCKER_VERSION:-not_found}",
    "running": $( docker info &> /dev/null 2>&1 && echo "true" || echo "false" )
  },
  "docker_compose": {
    "installed": $( docker compose version &> /dev/null 2>&1 && echo "true" || echo "false" ),
    "version": "${COMPOSE_VERSION:-not_found}"
  },
  "verdict": "${VERDICT}"
}
EOF

# Exit code: 0 si todo OK, 1 si hay fallas (para CI)
if [[ $FAIL -gt 0 ]]; then
  exit 1
fi
