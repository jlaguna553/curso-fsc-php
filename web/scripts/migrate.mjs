#!/usr/bin/env node
/**
 * scripts/migrate.mjs
 *
 * Migra el contenido del curso (parte0–5) a formato Starlight.
 * - Lee parteN/README.md → genera src/content/docs/parteN/index.md
 * - Lee parteN/ejercicios/*.md → genera src/content/docs/parteN/X-name.md
 * - Reemplaza diagramas ASCII por bloques ```mermaid
 * - Añade frontmatter compatible con Starlight
 */

import { readFileSync, writeFileSync, mkdirSync, rmSync, readdirSync, existsSync } from 'node:fs';
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
    participant C as "Cliente (curl)"
    participant DNS as DNS
    participant TLS as "TCP/TLS"
    participant S as "Servidor (PHP)"

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
    search: 'publica → exchange "prestaflow"',
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

// ─── Mapeo de soluciones por ejercicio ───
// Cada clave es el ID del ejercicio (ej: "1.3") y el valor son rutas RELATIVAS
// dentro de parteN/soluciones/ cuyo código se muestra en el <details> de ese ejercicio.
const SOLUCIONES_MAP = {
  parte1: {
    '1.1': ['composer.json', 'phpunit.xml.dist', 'phpstan.neon', '.php-cs-fixer.dist.php', 'Makefile', 'tests/bootstrap.php'],
    '1.2': ['src/Domain/Moneda.php'],
    '1.3': ['src/Domain/Dinero.php', 'tests/Unit/Domain/DineroTest.php'],
    '1.4': ['src/Domain/TipoMovimiento.php', 'src/Domain/Movimiento.php', 'tests/Unit/Domain/MovimientoTest.php'],
    '1.5': ['src/Domain/Billetera.php', 'src/Domain/Exception/FondosInsuficientes.php', 'tests/Unit/Domain/BilleteraTest.php'],
    '1.6': ['src/Controller/SaludController.php', 'public/index.php'],
    '1.7': ['tests/Architecture/DominioPuroTest.php'],
    '1.8': ['phpstan.neon', '.php-cs-fixer.dist.php', 'Makefile', 'phpunit.xml.dist'],
    '1.9': [],
    '1.10': [],
  },
  parte2: {
    '2.1': ['docker-compose.yml', 'docker/php-fpm/Dockerfile', 'docker/php-fpm/php-fpm-pool.conf', 'docker/nginx/Dockerfile', 'docker/nginx/default.conf'],
    '2.2': ['config/packages/doctrine.yaml', 'migrations/Version20260910000000.php', 'src/Infrastructure/Persistence/Doctrine/Type/UuidType.php', 'src/Infrastructure/Persistence/Doctrine/Mapping/BilleteraEntity.php'],
    '2.3': ['src/Application/Service/CrearBilleteraService.php', 'src/Application/Service/RealizarDepositoService.php'],
    '2.4': ['src/Controller/BilleteraController.php', 'config/packages/messenger.yaml'],
    '2.5': ['tests/Integration/Repository/BilleteraRepositoryTest.php', 'src/Infrastructure/Repository/BilleteraRepository.php'],
    '2.6': ['tests/Functional/BilleteraControllerTest.php'],
    '2.7': ['src/Infrastructure/Repository/BilleteraRepository.php', 'src/Application/Service/RealizarDepositoService.php'],
    '2.8': [],
  },
  parte3: {
    '3.1': ['src/Message/Command/CrearBilleteraCommand.php', 'src/Message/Query/ObtenerBilleteraQuery.php', 'src/Message/Event/BilleteraCreadaEvent.php', 'src/Infrastructure/Bus/CommandBus.php', 'src/Infrastructure/Bus/QueryBus.php'],
    '3.2': ['src/Application/CommandHandler/CrearBilleteraCommandHandler.php', 'src/Application/QueryHandler/ObtenerBilleteraQueryHandler.php'],
    '3.3': ['config/packages/messenger.yaml', 'src/Infrastructure/Serializer/EventoSerializer.php'],
    '3.4': ['config/packages/messenger.yaml'],
    '3.5': ['src/Application/CommandHandler/RealizarDepositoCommandHandler.php', 'src/Controller/BilleteraController.php'],
    '3.6': ['src/Domain/Repository/IdempotenciaRepository.php', 'src/Infrastructure/Repository/DoctrineIdempotenciaRepository.php', 'src/Application/Exception/BilleteraNoEncontrada.php'],
    '3.7': ['loan-service/src/Infrastructure/EventosProcesadosRepository.php', 'loan-service/config/schema.sql'],
    '3.8': ['loan-service/src/Application/EvaluadorElegibilidad.php', 'loan-service/src/Domain/ResultadoElegibilidad.php', 'loan-service/tests/EvaluadorElegibilidadTest.php'],
  },
  parte4: {
    '4.1': [],
    '4.2': ['tests/Unit/Domain/DineroDataProviderTest.php', 'tests/Unit/Domain/RetiroDataProviderTest.php'],
    '4.3': ['tests/Unit/Application/DepositoConMocksTest.php'],
    '4.4': ['behat.yml'],
    '4.5': ['features/billetera.feature', 'features/bootstrap/FeatureContext.php'],
    '4.6': ['phpunit.xml.dist'],
    '4.7': ['infection.json5'],
    '4.8': ['Makefile'],
  },
  parte5: {
    '5.1': [], // logs estructurados: se aplican sobre la solución de la Parte 4
    '5.2': ['src/Infrastructure/Tracing/TraceableCommandBus.php'],
    '5.3': ['src/Infrastructure/Tracing/TraceableCommandBus.php', 'src/Infrastructure/Tracing/TraceableQueryBus.php'],
    '5.4': ['docker/php-fpm/Dockerfile.prod'],
    '5.5': ['k8s/namespace.yaml', 'k8s/deployment.yaml', 'k8s/service.yaml'],
    '5.6': ['k8s/configmap.yaml', 'k8s/hpa.yaml'],
    '5.7': ['.github/workflows/ci.yml'],
    '5.8': ['.github/workflows/cd.yml'],
  },
};

// ─── Utilidades ───

function slugFromFilename(filename) {
  return basename(filename, extname(filename));
}

// Mismo algoritmo de slug que Starlight (para que las URLs del sidebar coincidan con los archivos).
function starlightSlug(base) {
  return base
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')   // quitar diacríticos
    .replace(/[^\w\d -]/g, '')          // quitar puntuación (incluye puntos)
    .replace(/_/g, '-')
    .toLowerCase();
}

function pageUrl(parteName, filename) {
  const base = basename(filename, '.md');
  if (base === 'index') return `/${parteName}/`;
  return `/${parteName}/${starlightSlug(base)}/`;
}

function slugifyTitle(title) {
  return starlightSlug(title.trim().replace(/\s+/g, '-')).replace(/-{2,}/g, '-');
}

// Orden natural por número de ejercicio: 1.1, 1.2, …, 1.10 (no lexical)
function byExerciseNumber(a, b) {
  const ia = exerciseIdFromFile(a);
  const ib = exerciseIdFromFile(b);
  if (ia && ib && ia !== ib) {
    const [pa, sa] = ia.split('.').map(Number);
    const [pb, sb] = ib.split('.').map(Number);
    return pa - pb || sa - sb;
  }
  return a.localeCompare(b);
}

// Extrae el ID de ejercicio de un archivo "X.Y-algo.md" → "X.Y"
function exerciseIdFromFile(filename) {
  const m = basename(filename, '.md').match(/^(\d+)\.(\d+)/);
  return m ? `${m[1]}.${m[2]}` : null;
}

// Divide el README en secciones por encabezados nivel 2 (##). Devuelve intro
// (contenido antes del primer ##) y una lista de secciones { heading, title, body }.
function splitSections(content) {
  const lines = content.split('\n');
  const intro = [];
  const sections = [];
  let current = null;

  for (const line of lines) {
    const m = line.match(/^##\s+(.*)/);
    if (m) {
      if (current) sections.push(current);
      current = { heading: line, title: m[1].trim(), body: [] };
    } else if (current) {
      current.body.push(line);
    } else {
      intro.push(line);
    }
  }
  if (current) sections.push(current);
  return { intro: intro.join('\n'), sections };
}

const LESSON_RE = /^(\d+)\.(\d+)\s+(.*)$/;
const EXERCISES_RE = /ejercicios/i;                    // "## Ejercicios de la Parte 1", "## 🧪 Ejercicios…"
const DROP_RE = /^(índice|siguiente paso)/i;           // navegación pura → se descarta
const END_RE = /^(resumen|comandos?|autoevaluaci|contenido)/i; // contenido de cierre → a la overview

function classifySection(section) {
  const m = section.title.match(LESSON_RE);
  if (m) return { type: 'lesson', id: `${m[1]}.${m[2]}`, numTitle: m[3].trim() };
  if (EXERCISES_RE.test(section.title)) return { type: 'exercises' };
  if (DROP_RE.test(section.title)) return { type: 'drop' };
  if (END_RE.test(section.title)) return { type: 'end' };
  return { type: 'other' };
}

// Ensambla lecciones a partir de las secciones. Las secciones 'end' (Resumen,
// Comandos, Autoevaluación) van a la overview; 'exercises' corta el resto.
function buildLessons(sections) {
  const clsList = sections.map(classifySection);
  const lessons = [];
  const endMatter = [];
  let cur = null;

  for (let i = 0; i < sections.length; i++) {
    const cls = clsList[i];
    if (cls.type === 'lesson') {
      if (cur) lessons.push(cur);
      cur = {
        id: cls.id,
        numTitle: cls.numTitle,
        title: `${cls.id} ${cls.numTitle}`,
        body: sections[i].body,
      };
    } else if (cls.type === 'other') {
      const block = [sections[i].heading, ...sections[i].body].join('\n');
      // Sección suelta entre lecciones → se adjunta a la lección actual.
      if (cur) cur.body.push(block);
    } else if (cls.type === 'end') {
      endMatter.push([sections[i].heading, ...sections[i].body].join('\n'));
    } else if (cls.type === 'exercises') {
      if (cur) lessons.push(cur);
      cur = null;
      break; // el resto del README son ejercicios (inline o tablas de links) → se omiten
    }
    // 'drop' → se ignora
  }
  if (cur) lessons.push(cur);
  return { lessons, endMatter };
}

const SOLUTION_LANG = {
  php: 'php', yaml: 'yaml', yml: 'yaml', json: 'json', json5: 'json5',
  conf: 'nginx', sh: 'bash', feature: 'gherkin', sql: 'sql', xml: 'xml',
  neon: 'yaml', env: 'bash', txt: 'text', md: 'markdown',
};

function langFromPath(path) {
  const name = basename(path);
  if (name === 'Makefile') return 'makefile';
  if (name.startsWith('Dockerfile')) return 'dockerfile';
  return SOLUTION_LANG[path.split('.').pop().toLowerCase()] || '';
}

// Genera un bloque <details> (colapsado) con el código de las soluciones.
function generateSolutionDetails(solDir, files) {
  let md = '\n<details>\n';
  md += `<summary>🔍 Ver solución (${files.length} archivos)</summary>\n\n`;
  for (const rel of files) {
    const full = join(solDir, rel);
    if (!existsSync(full)) {
      console.log(`⚠️  falta archivo de solución: ${rel}`);
      continue;
    }
    try {
      const content = readFileSync(full, 'utf-8');
      const lang = langFromPath(rel);
      md += `**\`${rel}\`**\n\n\`\`\`${lang}\n${content}\n\`\`\`\n\n`;
    } catch {
      console.log(`⚠️  no se pudo leer: ${rel}`);
    }
  }
  md += '</details>\n';
  return md;
}

function appendSolutionsToFile(filePath, solDir, files) {
  if (files.length === 0) return;
  const details = generateSolutionDetails(solDir, files);
  const existing = readFileSync(filePath, 'utf-8');
  writeFileSync(filePath, existing.replace(/\s*$/, '') + '\n\n' + details);
}

function extractTitle(content) {
  const h1 = content.match(/^#\s+(.+)/m);
  return h1 ? h1[1].trim() : 'Untitled';
}

function extractDescription(content) {
  const lines = content.split('\n');
  // Descripción = primer párrafo de blockquote con formato **Label**: texto
  const start = lines.findIndex(l => /^>\s+\*\*[^*]+\*\*:/.test(l));
  if (start === -1) return '';
  const parts = [];
  for (let i = start; i < lines.length; i++) {
    const m = lines[i].match(/^>\s?(.*)$/);
    if (!m || m[1] === '') break; // fin del párrafo blockquote
    parts.push(m[1]);
  }
  return parts.join(' ').replace(/^\*\*[^*]+\*\*:\s*/, '').trim();
}

function replaceAsciiDiagrams(content) {
  const lines = content.split('\n');
  const out = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];
    const trimmed = line.trim();

    // Línea sin fence: copiar tal cual
    if (!trimmed.startsWith('```')) {
      out.push(line);
      i++;
      continue;
    }

    // Fence: extraer lenguaje ('' = bloque desnudo, candidato a diagrama)
    const lang = trimmed.slice(3).trim();

    // Buscar el fence de cierre de ESTE bloque (escaneo estado-consciente:
    // nunca tratamos un fence de cierre ajeno como apertura)
    let j = i + 1;
    const blockLines = [];
    let foundClose = false;
    while (j < lines.length) {
      if (lines[j].trim().startsWith('```')) {
        foundClose = true;
        break;
      }
      blockLines.push(lines[j]);
      j++;
    }

    if (!foundClose) {
      // Bloque sin cierre (final del archivo): copiar el resto tal cual
      out.push(...lines.slice(i));
      break;
    }

    // Solo bloques desnudos (sin lenguaje) son candidatos a diagrama ASCII
    if (lang === '') {
      const blockText = blockLines.join('\n');
      const entry = MERMAID_MAP.find(({ search }) => blockText.includes(search));
      if (entry) {
        out.push('```mermaid');
        out.push(entry.mermaid);
        out.push('```');
        i = j + 1; // saltar todo el bloque original
        continue;
      }
    }

    // Sin match: copiar el bloque completo verbatim (apertura + contenido + cierre)
    out.push(line, ...blockLines, lines[j]);
    i = j + 1;
  }

  return out.join('\n');
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
  const solDir = join(srcDir, 'soluciones');
  const outDir = join(SRC, parteName);

  if (!existsSync(readme)) {
    console.log(`⚠️  ${parteName}/README.md no existe, saltando`);
    return { meta: null, pages: 0 };
  }

  // Limpiar salidas anteriores (100% generadas; evita archivos obsoletos por renombres)
  rmSync(outDir, { recursive: true, force: true });
  mkdirSync(outDir, { recursive: true });

  let content = readFileSync(readme, 'utf-8');
  const title = extractTitle(content);
  const description = extractDescription(content);
  const order = PART_ORDER[parteName];

  content = replaceAsciiDiagrams(content);

  // Dividir el README en lecciones individuales
  const { intro, sections } = splitSections(content);
  const { lessons, endMatter } = buildLessons(sections);

  // ── index.md: overview (intro + secciones de cierre: Resumen, Comandos…) ──
  let indexBody = intro.replace(/^#\s+.+\n/m, '').trim();
  if (endMatter.length) indexBody += '\n\n' + endMatter.join('\n\n');

  const indexFm = generateFrontmatter({
    title,
    description: description || `${PART_LABELS[parteName]} — Curso FSC-PHP PrestaFlow`,
    sidebar: { label: 'Overview', order }
  });
  writeFileSync(join(outDir, 'index.md'), indexFm + indexBody + '\n');
  console.log(`✅ ${parteName}/index.md (overview)`);

  // ── Lecciones individuales ──
  const lessonMeta = [];
  lessons.forEach((lesson, idx) => {
    const fileName = `${lesson.id}-${slugifyTitle(lesson.numTitle)}.md`;
    const fm = generateFrontmatter({
      title: lesson.title,
      sidebar: { label: lesson.title, order: idx + 1 }
    });
    const body = lesson.body.join('\n').trim();
    writeFileSync(join(outDir, fileName), fm + body + '\n');
    lessonMeta.push({ label: lesson.title, link: pageUrl(parteName, fileName) });
    console.log(`✅ ${parteName}/${fileName}`);
  });

  // ── Ejercicios (páginas separadas + soluciones colapsadas) ──
  const exerciseMeta = [];
  if (existsSync(ejerciciosDir)) {
    const files = readdirSync(ejerciciosDir)
      .filter(f => f.endsWith('.md'))
      .sort(byExerciseNumber);

    files.forEach((file) => {
      const slug = slugFromFilename(file);
      let exContent = readFileSync(join(ejerciciosDir, file), 'utf-8');
      const exTitle = extractTitle(exContent);
      const exDesc = extractDescription(exContent);
      exContent = exContent.replace(/^#\s+.+\n/m, '');

      const exFm = generateFrontmatter({
        title: exTitle,
        description: exDesc,
        sidebar: { label: exTitle }
      });

      const outPath = join(outDir, `${slug}.md`);
      writeFileSync(outPath, exFm + exContent.trim() + '\n');

      // Añadir soluciones del ejercicio (si el mapa las define)
      const exId = exerciseIdFromFile(file);
      const solFiles = (SOLUCIONES_MAP[parteName] || {})[exId] || [];
      if (solFiles.length && existsSync(solDir)) {
        appendSolutionsToFile(outPath, solDir, solFiles);
      }

      exerciseMeta.push({ label: exTitle, link: pageUrl(parteName, file) });
      console.log(`✅ ${parteName}/${slug}.md`);
    });
  }

  const meta = {
    key: parteName,
    label: PART_LABELS[parteName],
    lessonMeta,
    exerciseMeta,
    hasSolutions: existsSync(solDir),
  };

  return { meta, pages: 1 + lessonMeta.length + exerciseMeta.length };
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

const partes = ['parte0', 'parte1', 'parte2', 'parte3', 'parte4', 'parte5'];
const allParts = [];
let totalPages = 0;

for (const parte of partes) {
  const { meta, pages } = migrateParte(parte);
  if (meta) allParts.push(meta);
  totalPages += pages;
  migrateSoluciones(parte);
}

// ─── Generar sidebar.generated.json (consumido por astro.config.mjs) ───
const sidebar = allParts.map(p => {
  const items = [{ label: 'Overview', link: `/${p.key}/` }];
  if (p.lessonMeta.length) {
    items.push({ label: 'Lecciones', collapsed: false, items: p.lessonMeta });
  }
  if (p.exerciseMeta.length) {
    items.push({ label: 'Ejercicios', collapsed: false, items: p.exerciseMeta });
  }
  if (p.hasSolutions) {
    items.push({ label: 'Soluciones', link: `/${p.key}/soluciones/` });
  }
  return { label: p.label, items };
});

writeFileSync(join(ROOT, 'sidebar.generated.json'), JSON.stringify(sidebar, null, 2) + '\n');
console.log('✅ sidebar.generated.json');

console.log(`\n✅ Migración completa: ${totalPages} páginas generadas`);
console.log(`📁 Contenido en: ${SRC}`);
console.log('\nPara ejecutar: cd web && npm run dev');