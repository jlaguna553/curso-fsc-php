import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const dir = path.join(__dirname, '..', 'src/content/docs');
const files = [];
function walk(d) {
  for (const f of fs.readdirSync(d, { withFileTypes: true })) {
    const p = path.join(d, f.name);
    if (f.isDirectory()) { walk(p); continue; }
    if (f.name.endsWith('.md')) files.push(p);
  }
}
walk(dir);

let totalFixes = 0;
for (const file of files) {
  const lines = fs.readFileSync(file, 'utf-8').split('\n');
  let clean = [];
  let open = false;
  let fixes = 0;

  for (const line of lines) {
    const trimmed = line.trim();
    const isFence = trimmed.startsWith('```');

    if (!isFence) { clean.push(line); continue; }

    if (!open) {
      // Apertura de bloque
      open = true;
      clean.push(line);
      continue;
    }

    // Ya dentro de un bloque:
    if (trimmed === '```') {
      // Cierre plano = cierre normal del bloque
      open = false;
      clean.push(line);
      continue;
    }

    // Fence con info string (```mermaid, ```bash, ```json...) dentro de un bloque
    // abierto → el cierre del bloque anterior se perdió en una migración previa.
    // Este fence es en realidad: cierre(```) + apertura de un nuevo bloque.
    clean.push('```');   // cierre del bloque anterior
    clean.push(line);    // apertura del nuevo bloque
    fixes++;
  }

  if (fixes > 0) {
    fs.writeFileSync(file, clean.join('\n'));
    console.log(`Fixed ${fixes} fence gaps: ${path.relative(dir, file)}`);
    totalFixes += fixes;
  }
}
console.log(`\nTotal fence fixes: ${totalFixes}`);
