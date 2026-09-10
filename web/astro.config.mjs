import { defineConfig } from 'astro/config';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import starlight from '@astrojs/starlight';
import mermaid from 'astro-mermaid';

const __dirname = dirname(fileURLToPath(import.meta.url));

// Sidebar generado por scripts/migrate.mjs → sidebar.generated.json.
// Garantiza que los enlaces coincidan exactamente con los archivos generados.
function loadSidebar() {
  try {
    return JSON.parse(readFileSync(join(__dirname, 'sidebar.generated.json'), 'utf-8'));
  } catch {
    console.warn('⚠️  sidebar.generated.json no encontrado. Ejecuta: npm run migrate');
    return [];
  }
}

export default defineConfig({
  site: 'https://fsc-php.vercel.app',
  integrations: [
    mermaid({
      theme: 'default',
      autoTheme: true,
      mermaidConfig: {
        flowchart: { curve: 'basis', useMaxWidth: true },
        sequence: { mirrorActors: false },
      },
    }),
    starlight({
      title: 'FSC-PHP · PrestaFlow',
      description: 'Curso práctico de PHP 8.3 + Symfony 7.4. Microservicios fintech con DDD, CQRS, RabbitMQ, testing y CI/CD.',
      defaultLocale: 'es',
      social: [
        { icon: 'github', label: 'GitHub', href: 'https://github.com/jlaguna553/curso-fsc-php' },
      ],
      sidebar: loadSidebar(),
      customCss: ['./src/styles/custom.css'],
      head: [
        {
          tag: 'meta',
          attrs: { property: 'og:image', content: '/og.png' },
        },
      ],
    }),
  ],
});