-- loan-service/config/schema.sql — Esquema de la BD de resultados del loan-service
--
-- El loan-service solo persiste DOS cosas:
--   1. Los eventos ya procesados (idempotencia)
--   2. Los resultados de evaluación (puntaje + recomendación)
--
-- Se ejecuta una sola vez:
--   docker compose exec loan-db psql -U prestaflow -d prestaflow_loan -f /schema.sql
--
-- O mejor: la crea el propio worker al arrancar (ver nota en bin/consumir.php).

-- Tabla de idempotencia: la UNIQUE CONSTRAINT es la garantía real
-- contra duplicados, incluso si dos instancias del worker procesan
-- el mismo evento simultáneamente.
CREATE TABLE IF NOT EXISTS eventos_procesados (
    event_id       VARCHAR(64) PRIMARY KEY,  -- ID del evento (event_id o transaccion_id)
    puntaje        INT         NOT NULL,     -- puntaje 0-100
    recomendacion  VARCHAR(20) NOT NULL,     -- APROBADO | EN_REVISION | NO_ELEGIBLE
    procesado_en   TIMESTAMP   NOT NULL DEFAULT NOW()
);

-- Índice para consultas por recomendación (análisis/BI).
CREATE INDEX IF NOT EXISTS idx_eventos_recomendacion
    ON eventos_procesados (recomendacion);