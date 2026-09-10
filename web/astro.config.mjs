import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import mermaid from 'astro-mermaid';

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
      sidebar: [
        { label: 'Parte 0 — Infraestructura Base', autogenerate: { directory: 'parte0' } },
        { label: 'Parte 1 — DDD y PHP Moderno', autogenerate: { directory: 'parte1' } },
        { label: 'Parte 2 — Symfony y Arquitectura Hexagonal', autogenerate: { directory: 'parte2' } },
        { label: 'Parte 3 — CQRS y Event-Driven Architecture', autogenerate: { directory: 'parte3' } },
        { label: 'Parte 4 — Testing Profesional', autogenerate: { directory: 'parte4' } },
        { label: 'Parte 5 — Observabilidad, K8s y CI/CD', autogenerate: { directory: 'parte5' } },
      ],
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