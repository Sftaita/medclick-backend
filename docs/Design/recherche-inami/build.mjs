// Construit l'aperçu (public/app.js, public/app.css). Utilise le node_modules de ce dossier
// (npm install), ou à défaut celui de ../medclick-react s'il existe.
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { existsSync } from 'node:fs';

const here = path.dirname(fileURLToPath(import.meta.url));
const local = path.join(here, 'node_modules');
const modules = existsSync(path.join(local, 'esbuild')) ? local : path.resolve(here, '../medclick-react/node_modules');
if (!existsSync(path.join(modules, 'esbuild'))) {
  console.error('esbuild introuvable : lancez « npm install » dans docs/Design/recherche-inami.');
  process.exit(1);
}
const esbuild = createRequire(path.join(modules, 'x.js'))('esbuild');

await esbuild.build({
  entryPoints: [path.join(here, 'apercu/main.jsx')],
  outfile: path.join(here, 'public/app.js'),
  bundle: true,
  minify: true,
  jsx: 'automatic',
  loader: { '.js': 'jsx' },
  target: 'es2020',
  define: { 'process.env.NODE_ENV': '"production"' },
  nodePaths: [modules],
  logLevel: 'warning',
});
console.log('Aperçu construit : public/app.js et public/app.css');
