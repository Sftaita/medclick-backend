// Serveur du test de transition CRA → Vite, sur une seule origine (http://localhost:4300) — test local, hors VPS.
// Le contenu servi dépend de ./mode.txt (« cra » ou « vite »), relu à chaque requête : changer le
// fichier = remplacer le serveur. Reproduit fidèlement :
//  - cra  : deploy/vps/cra-transition/cra-nginx.conf (en-têtes du CDN Hostinger actuel) ;
//  - vite : deploy/vps/frontend-nginx.conf (futur VPS).
// ./delay.txt (ms, optionnel) ajoute un délai à chaque réponse (connexion lente).
import { createServer } from "node:http";
import { readFileSync, existsSync, statSync } from "node:fs";
import { extname, join, normalize } from "node:path";

// Répertoire de travail : TRANSITION_DIR (contient cra-prod/, vite-dist/, mode.txt, delay.txt).
const here = process.env.TRANSITION_DIR ?? process.cwd();
const ROOTS = { cra: join(here, "cra-prod"), vite: join(here, "vite-dist") };
const TYPES = { ".html": "text/html; charset=utf-8", ".js": "application/javascript", ".css": "text/css", ".json": "application/json", ".webmanifest": "application/manifest+json", ".png": "image/png", ".svg": "image/svg+xml", ".ico": "image/x-icon", ".webp": "image/webp", ".txt": "text/plain" };
const log = [];

const read = (name, fallback) => (existsSync(join(here, name)) ? readFileSync(join(here, name), "utf8").trim() : fallback);
const isFile = (p) => existsSync(p) && statSync(p).isFile();

function route(mode, path) {
  const root = ROOTS[mode];
  const file = join(root, normalize(path).replace(/^([\\/])+/, ""));
  if (mode === "cra") {
    if (path === "/service-worker.js" || path.startsWith("/static/")) {
      return isFile(file) ? { file, cache: "public, max-age=604800" } : { status: 404 };
    }
    return { file: isFile(file) ? file : join(root, "index.html") };
  }
  // vite (frontend-nginx.conf)
  if (path === "/service-worker.js") return isFile(file) ? { file, cache: "no-cache, no-store, must-revalidate" } : { status: 404 };
  if (path === "/index.html") return { file, cache: "no-cache" };
  if (path === "/manifest.webmanifest") return isFile(file) ? { file, cache: "no-cache" } : { status: 404 };
  if (path.startsWith("/assets/")) return isFile(file) ? { file, cache: "public, max-age=31536000, immutable" } : { status: 404 };
  if (/(^|\/)\./.test(path) || /^\/(src|config|vendor|var|bin|migrations|templates|tests|node_modules)(\/|$)/.test(path) || /\.(pem|key|env|log|php|sql|gz|bak|ya?ml|lock)$/i.test(path)) return { status: 404 };
  return { file: isFile(file) ? file : join(root, "index.html"), cache: "no-cache" };
}

createServer(async (req, res) => {
  const path = decodeURIComponent(new URL(req.url, "http://localhost").pathname);
  if (path === "/__log") {
    res.writeHead(200, { "Content-Type": "application/json", "Cache-Control": "no-store" });
    return res.end(JSON.stringify(log.slice(-300)));
  }
  const mode = read("mode.txt", "cra");
  const delay = Number(read("delay.txt", "0")) || 0;
  if (delay) await new Promise((r) => setTimeout(r, delay));
  const r = route(mode, path);
  log.push(`${new Date().toISOString().slice(11, 23)} ${mode} ${req.method} ${path} ${r.status ?? 200}${req.headers["service-worker"] ? " [sw]" : ""}`);
  if (r.status) {
    res.writeHead(r.status, { "Content-Type": "text/plain" });
    return res.end("Not Found");
  }
  const headers = { "Content-Type": TYPES[extname(r.file)] ?? "application/octet-stream" };
  if (r.cache) headers["Cache-Control"] = r.cache;
  res.writeHead(200, headers);
  res.end(readFileSync(r.file));
}).listen(4300, () => console.log("transition sur http://localhost:4300 (mode : mode.txt)"));
