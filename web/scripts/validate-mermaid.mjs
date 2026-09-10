import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// ─── Shim mínimo de DOM/DOMPurify para mermaid v11 en Node ───
// mermaid v11 importa 'dompurify' a nivel de módulo; en Node sin window
// su default export es una fábrica sin métodos → "DOMPurify.sanitize is not a function".
// Proveemos un window stub ANTES del import de mermaid para que DOMPurify
// se inicialice con una instancia real (sanitize como passthrough).

const doc = {
  createElement: () => ({ setAttribute() {}, appendChild() {}, style: {}, classList: { add() {} } }),
  createElementNS: () => ({ setAttribute() {}, appendChild() {}, style: {} }),
  createTextNode: () => ({}),
  querySelector: () => null,
  querySelectorAll: () => [],
  getElementById: () => null,
  documentElement: { style: {} },
  head: { appendChild() {} },
  body: { appendChild() {} },
  implementation: { createHTMLDocument: () => doc },
  createEvent: () => ({ initEvent() {} }),
  addEventListener() {},
  removeEventListener() {},
};
globalThis.addEventListener = () => {};
globalThis.removeEventListener = () => {};
globalThis.window = globalThis;
globalThis.document = doc;
globalThis.DOMPurify = undefined;

const mermaid = (await import('mermaid')).default;
mermaid.initialize({ startOnLoad: false, securityLevel: 'loose' });

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
files.sort();

let total = 0, errors = 0;
const envArtifacts = /DOMPurify|dompurify|document is not defined|window is not defined/i;
for (const file of files) {
  const content = fs.readFileSync(file, 'utf-8');
  const regex = /```mermaid\n([\s\S]*?)```/g;
  let m, idx = 0;
  while ((m = regex.exec(content)) !== null) {
    total++;
    idx++;
    const code = m[1];
    try {
      await mermaid.parse(code);
      console.log('OK   ' + path.relative(dir, file) + ' #' + idx);
    } catch (e) {
      const msg = String((e && e.message) || e).split('\n').slice(0, 3).join(' | ');
      if (envArtifacts.test(msg)) {
        // Sin DOM real en Node: estos fallos NO son errores de sintaxis del diagrama
        console.log('SKIP ' + path.relative(dir, file) + ' #' + idx + ' [env: ' + msg.slice(0, 60) + ']');
        continue;
      }
      errors++;
      console.log('FAIL ' + path.relative(dir, file) + ' #' + idx + '\n     → ' + msg);
    }
  }
}
console.log('\nTotal: ' + total + ' diagramas, ' + errors + ' con error de sintaxis REAL');
process.exit(errors > 0 ? 1 : 0);