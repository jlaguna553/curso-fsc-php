import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseDiagram } from '@mermaid-js/parser';

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
for (const file of files) {
  const content = fs.readFileSync(file, 'utf-8');
  const regex = /```mermaid\n([\s\S]*?)```/g;
  let m, idx = 0;
  while ((m = regex.exec(content)) !== null) {
    total++;
    idx++;
    const code = m[1];
    try {
      const result = parseDiagram(code);
      if (result.diagramType === undefined) {
        // unknown diagram type
        errors++;
        console.log('FAIL ' + path.relative(dir, file) + ' #' + idx + ' → tipo desconocido');
        continue;
      }
      console.log('OK   ' + path.relative(dir, file) + ' #' + idx + ' (' + result.diagramType + ')');
    } catch (e) {
      errors++;
      const msg = (e.message || '').split('\n').slice(0, 5).join(' | ');
      console.log('FAIL ' + path.relative(dir, file) + ' #' + idx + '\n     → ' + msg);
    }
  }
}
console.log('\nTotal: ' + total + ' diagramas, ' + errors + ' con error');
