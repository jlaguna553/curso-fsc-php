#!/usr/bin/env node
/**
 * scripts/migrate.mjs
 *
 * Migra el contenido del curso (parte0–5) a formato Starlight.
 * - Lee parteN/README.md → genera src/content/docs/parteN/index.md
 * - Lee parteN/ejercicios/*.md → genera src/content/docs/parteN/X-name.mdx
 * - Reemplaza diagramas ASCII por bloques ```mermaid
 * - Añade frontmatter compatible con Starlight
 */

import { readFileSync, writeFileSync, mkdirSync, readdirSync, existsSync } from 'node:fs';
import { join, basename, dirname, extname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = join(__dirname, '..');
const SRC = join(ROOT, 'src/content/docs');
const CURSO = join(ROOT, '..');

// ─── Diagramas Mermaid manuales ───
// Cada entrada reemplaza un patrón de código ASCII por un bloque mermaid.
// Los keys son substrings únicos dentro del bloque ASCII que identifican el diagrama.

const MERMAID_MAP = [
  {
    // Parte 0: Ecosistema PrestaFlow
    search: 'PrestaFlow Ecosystem',
    mermaid: `graph TD
    APi["API Gateway\\nNginx"] --> WS["wallet-service\\nPHP 8.3 / Symfony 7"]
    WS --> LS["loan-service\\nPHP 8.3"]
    WS --> PG1[("PostgreSQL\\nwallet_db")]
    LS --> PG2[("PostgreSQL\\nloan_db")]
    WS --- RMQ["RabbitMQ Event Bus\\ntransactions · loans · notifications"]
    RMQ --- LS
    WS --- DD["Datadog APM\\nTraces · Metrics · Logs"]
    style WS fill:#dbeafe,stroke:#1a56db,stroke-width:2px
    style APi fill:#f0fdf4,stroke:#16a34a,stroke-width:2px
    style LS fill:#fef3c7,stroke:#d97706,stroke-width:2px
    style RMQ fill:#fce7f3,stroke:#db2777,stroke-width:2px
    style DD fill:#ede9fe,stroke:#7c3aed,stroke-width:2px`
  },
  {
    // Parte 0: Ciclo HTTP completo
    search: 'Resuelve',
    mermaid: `sequenceDiagram
    participant C as Cliente (curl)
    participant DNS as DNS
    participant TLS as TCP/TLS
    participant S as Servidor (PHP)

    C->>DNS: 1. Resuelve "api.prestaflow"
    DNS-->>C: 2. Retorna IP
    C->>TLS: 3. Handshake TCP + TLS
    TLS-->>C: Conexión segura
    C->>S: 4. POST /api/v1/transacciones
    Note over S: Router → Controller → Domain → DB
    S-->>C: 5. HTTP 201 Created`
  },
  {
    // Parte 1: Pirámide de testing (va en parte 4 pero mapeo por contexto)
    search: 'E2E / UI (pocos, lentos, caros)',
    mermaid: `graph TB
    A["E2E / UI<br/>pocos, lentos, caros<br/>Navegador real, usuario completo"]
    B["Funcionales / Integración<br/>algunos, medianos<br/>HTTP simulado, BD real"]
    C["Unitarios<br/>muchos, rápidos, baratos<br/>Dominio puro, sin I/O"]
    A --- B
    B --- C
    style A fill:#fce7f3,stroke:#db2777,stroke-width:2px
    style B fill:#fef3c7,stroke:#d97706,stroke-width:2px
    style C fill:#dbeafe,stroke:#1a56db,stroke-width:2px`
  },
  {
    // Parte 2: Arquitectura hexagonal
    search: 'Application Service',
    mermaid: `graph LR
    subgraph Exterior["Exterior"]
        API["REST API"]
        CLI["CLI"]
        MQ["RabbitMQ"]
    end
    subgraph Puertos["Puertos (Interfaces)"]
        CP["Command Port"]
        QP["Query Port"]
    end
    subgraph Core["Core del Dominio"]
        DOM["Domain\\nEntities + Rules"]
    end
    subgraph Adaptadores["Adaptadores"]
        AD1["Doctrine Repo"]
        AD2["HTTP Controller"]
        AD3["Message Handler"]
    end
    Exterior --> Puertos
    Puertos --> Core
    Adaptadores --> Puertos
    style DOM fill:#dbeafe,stroke:#1a56db,stroke-width:2px`
  },
  {
    // Parte 3: Topología RabbitMQ
    search: 'prestflow',
    mermaid: `graph LR
    subgraph Wallet["wallet-service"]
        PUB["Publisher"]
    end
    subgraph Exchange["Exchange prestaflow.events"]
        EX["direct exchange"]
    end
    subgraph Colas["Colas"]
        Q1["transactions"]
        Q2["loans"]
        Q3["notifications"]
        Q4["dead-letter"]
    end
    subgraph Consumers["Consumidores"]
        LOAN["loan-service"]
        NOTIFY["notif-worker"]
    end
    PUB --> EX
    EX --> Q1
    EX --> Q2
    EX --> Q3
    Q1 --> LOAN
    Q2 --> LOAN
    Q3 --> NOTIFY
    style EX fill:#fce7f3,stroke:#db2777
    style Q1 fill:#fef3c7,stroke:#d97706
    style Q2 fill:#fef3c7,stroke:#d97706
    style Q3 fill:#fef3c7,stroke:#d97706`
  },
  {
    // Parte 4: Pirámide de testing (leo la segunda ocurrencia)
    search: 'Unit (base, rápidos)',
    mermaid: `graph TB
    A["/< E2E (Behat) \\>\\n Lentos, pocos"]
    B["/< Integration \\>\\n Medio"]
    C["/< Unit (PHPUnit) \\>\\n Rápidos, muchos — BASE"]
    A --- B
    B --- C
    style A fill:#fce7f3,stroke:#db2777
    style B fill:#fef3c7,stroke:#d97706
    style C fill:#dbeafe,stroke:#1a56db,stroke-width:2px`
  },
  {
    // Parte 5: Clúster K8s
    search: 'CLÚSTER Kind',
    mermaid: `graph TD
    subgraph K8s["Clúster Kind (nodo único local)"]
        NS["Namespace: prestaflow"]
        subgraph Pods["Pods"]
            P1["Pod wallet-service A"]
            P2["Pod wallet-service B"]
        end
        SVC["Service\\nwallet-service:80 → :9000"]
        CM["ConfigMap\\nAPP_ENV, DATABASE_URL"]
        SEC["Secret\\nAPP_SECRET"]
        HPA["HPA\\nmin:2 max:10\\nCPU > 70%"]
    end
    NS --> Pods
    SVC --> Pods
    HPA --> Pods
    style NS fill:#f0fdf4,stroke:#16a34a
    style SVC fill:#dbeafe,stroke:#1a56db
    style HPA fill:#fef3c7,stroke:#d97706`
  },
  {
    // Parte 5: Pipeline CI/CD
    search: 'CI (calidad)',
    mermaid: `graph LR
    subgraph CI["CI — Calidad"]
        LINT["php-lint"]
        TEST["PHPUnit + Behat"]
        STAN["PHPStan"]
        CS["CS-Fixer"]
    end
    subgraph CD["CD — Entrega"]
        BUILD["docker build\\nmulti-stage"]
        PUSH["push a GHCR"]
        DEPLOY["kubectl apply\\nrollout K8s"]
    end
    LINT --> TEST
    TEST --> STAN
    STAN --> CS
    CS --> BUILD
    BUILD --> PUSH
    PUSH --> DEPLOY
    style CI fill:#dbeafe,stroke:#1a56db
    style CD fill:#f0fdf4,stroke:#16a34a`
  }
];

// ─── Mapeo de sidebar order por parte ───
const PART_ORDER = {
  parte0: 0, parte1: 1, parte2: 2, parte3: 3, parte4: 4, parte5: 5
};

const PART_LABELS = {
  parte0: 'Parte 0 — Infraestructura Base',
  parte1: 'Parte 1 — DDD y PHP Moderno',
  parte2: 'Parte 2 — Symfony y Arquitectura Hexagonal',
  parte3: 'Parte 3 — CQRS y Event-Driven Architecture',
  parte4: 'Parte 4 — Testing Profesional',
  parte5: 'Parte 5 — Observabilidad, K8s y CI/CD'
};

// ─── Utilidades ───

function slugFromFilename(filename) {
  return basename(filename, extname(filename));
}

function extractTitle(content) {
  const h1 = content.match(/^#\s+(.+)/m);
  return h1 ? h1[1].trim() : 'Untitled';
}

function extractDescription(content) {
  const bq = content.match(/^>\s+\*\*(.+?)\*\*/m);
  if (bq) return bq[1].trim();
  const bq2 = content.match(/^>\s+(.+)/m);
  return bq2 ? bq2[1].trim() : '';
}

function replaceAsciiDiagrams(content) {
  let result = content;
  for (const { search, mermaid } of MERMAID_MAP) {
    // Buscamos bloques de código sin lenguaje que contengan el patrón
    const regex = new RegExp(
      '```\\n([\\s\\S]*?' + escapeRegex(search) + '[\\s\\S]*?)```',
      'g'
    );
    result = result.replace(regex, (_match, inner) => {
      return `\`\`\`mermaid\n${mermaid}\n\`\`\``;
    });
  }
  return result;
}

function escapeRegex(str) {
  return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function generateFrontmatter({ title, description, sidebar, prev, next }) {
  let fm = '---\n';
  fm += `title: ${JSON.stringify(title)}\n`;
  if (description) fm += `description: ${JSON.stringify(description)}\n`;
  if (sidebar) fm += `sidebar: ${JSON.stringify(sidebar)}\n`;
  if (prev) fm += `prev: ${JSON.stringify(prev)}\n`;
  if (next) fm += `next: ${JSON.stringify(next)}\n`;
  fm += '---\n\n';
  return fm;
}

// ─── Procesamiento principal ───

function migrateParte(parteName) {
  const srcDir = join(CURSO, parteName);
  const readme = join(srcDir, 'README.md');
  const ejerciciosDir = join(srcDir, 'ejercicios');
  const outDir = join(SRC, parteName);

  if (!existsSync(readme)) {
    console.log(`⚠️  ${parteName}/README.md no existe, saltando`);
    return [];
  }

  mkdirSync(outDir, { recursive: true });

  // Leer y migrar README
  let content = readFileSync(readme, 'utf-8');
  const title = extractTitle(content);
  const description = extractDescription(content);
  const order = PART_ORDER[parteName];

  // Reemplazar diagramas ASCII
  content = replaceAsciiDiagrams(content);

  // Quitar el H1 del título (Starlight lo pone del frontmatter)
  content = content.replace(/^#\s+.+\n/m, '');

  const frontmatter = generateFrontmatter({
    title,
    description: description || `${PART_LABELS[parteName]} — Curso FSC-PHP PrestaFlow`,
    sidebar: { order }
  });

  writeFileSync(join(outDir, 'index.md'), frontmatter + content);
  console.log(`✅ ${parteName}/index.md`);

  // Migrar ejercicios
  const pages = [`${parteName}/index`];

  if (existsSync(ejerciciosDir)) {
    const files = readdirSync(ejerciciosDir)
      .filter(f => f.endsWith('.md'))
      .sort();

    files.forEach((file, idx) => {
      const slug = slugFromFilename(file);
      let exContent = readFileSync(join(ejerciciosDir, file), 'utf-8');
      const exTitle = extractTitle(exContent);
      const exDesc = extractDescription(exContent);

      // Quitar H1
      exContent = exContent.replace(/^#\s+.+\n/m, '');

      const exFm = generateFrontmatter({
        title: exTitle,
        description: exDesc,
        sidebar: { label: exTitle, order: idx + 1 }
      });

      writeFileSync(join(outDir, `${slug}.mdx`), exFm + exContent);
      pages.push(`${parteName}/${slug}`);
    });
  }

  return pages;
}

// ─── Migrar soluciones como código referenciado ───

function migrateSoluciones(parteName) {
  const solDir = join(CURSO, parteName, 'soluciones');
  if (!existsSync(solDir)) return;

  const outDir = join(SRC, parteName);
  mkdirSync(outDir, { recursive: true });

  // Recopilar archivos de código
  const codeFiles = [];
  function walkDir(dir, prefix = '') {
    if (!existsSync(dir)) return;
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      if (entry.name.startsWith('.')) continue;
      const fullPath = join(dir, entry.name);
      const relPath = prefix ? `${prefix}/${entry.name}` : entry.name;
      if (entry.isDirectory()) {
        walkDir(fullPath, relPath);
      } else if (/\.(php|yaml|yml|json|json5|conf|sh|feature|Dockerfile|prod)$/.test(entry.name) || entry.name === 'Makefile') {
        try {
          const content = readFileSync(fullPath, 'utf-8');
          codeFiles.push({ path: relPath, content });
        } catch { /* skip binary */ }
      }
    }
  }

  walkDir(solDir);

  if (codeFiles.length === 0) return;

  const langMap = {
    'php': 'php', 'yaml': 'yaml', 'yml': 'yaml', 'json': 'json',
    'json5': 'json5', 'conf': 'nginx', 'sh': 'bash', 'feature': 'gherkin',
    'Makefile': 'makefile', 'Dockerfile': 'dockerfile'
  };

  let md = '';
  md += generateFrontmatter({
    title: `Soluciones — ${PART_LABELS[parteName]}`,
    description: `Código fuente de las soluciones de la ${parteName.replace('parte', 'Parte ')}`,
    sidebar: { label: 'Soluciones', order: 100 }
  });

  md += `\n> 📁 **${codeFiles.length} archivos** de código de referencia.\n\n`;

  for (const file of codeFiles) {
    const ext = file.path.split('.').pop().toLowerCase();
    const lang = langMap[ext] || ext;
    md += `## ${file.path}\n\n`;
    md += `\`\`\`${lang}\n${file.content}\n\`\`\`\n\n`;
  }

  writeFileSync(join(outDir, 'soluciones.md'), md);
  console.log(`✅ ${parteName}/soluciones.md (${codeFiles.length} archivos)`);
}

// ─── Ejecutar ───

console.log('🚀 Migrando curso FSC-PHP a Starlight...\n');

const allPages = [];
const partes = ['parte0', 'parte1', 'parte2', 'parte3', 'parte4', 'parte5'];

for (const parte of partes) {
  const pages = migrateParte(parte);
  allPages.push(...pages);
  migrateSoluciones(parte);
}

console.log(`\n✅ Migración completa: ${allPages.length} páginas generadas`);
console.log(`📁 Contenido en: ${SRC}`);
console.log('\nPara ejecutar: cd web && npm run dev');