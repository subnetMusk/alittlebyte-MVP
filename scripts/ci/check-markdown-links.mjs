// Verifica i link relativi e le anchor dei file Markdown versionati.
// I link esterni (http, https, mailto) non vengono controllati: dipendono dalla rete.
// Uso: node scripts/ci/check-markdown-links.mjs [file.md ...]
import { execFileSync } from "node:child_process";
import { existsSync, readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, normalize, resolve } from "node:path";

const root = resolve(dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1")), "../..");

// Cartelle generate o di dipendenze, escluse quando git non è disponibile (container Node).
const IGNORED_DIRS = new Set([".git", "node_modules", "vendor", "coverage", "storage", "dist", ".angular", "backups"]);

function walk(dir, prefix = "") {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const relative = prefix ? `${prefix}/${entry.name}` : entry.name;

    if (entry.isDirectory()) {
      return IGNORED_DIRS.has(entry.name) ? [] : walk(join(dir, entry.name), relative);
    }

    return entry.name.endsWith(".md") ? [relative] : [];
  });
}

function markdownFiles() {
  try {
    return execFileSync("git", ["ls-files", "--cached", "--others", "--exclude-standard", "*.md"], {
      cwd: root,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"],
    })
      .split("\n")
      .filter((file) => file && existsSync(join(root, file)));
  } catch {
    return walk(root);
  }
}

const files = process.argv.length > 2 ? process.argv.slice(2) : markdownFiles();

// Slug delle intestazioni come lo calcola GitHub: minuscolo, punteggiatura tolta, spazi in trattini.
function slug(heading) {
  return heading
    .trim()
    .toLowerCase()
    .replace(/<[^>]+>/g, "")
    .replace(/[^\p{L}\p{N}\s_-]/gu, "")
    .replace(/\s/g, "-");
}

const anchorCache = new Map();

function anchorsOf(file) {
  if (anchorCache.has(file)) {
    return anchorCache.get(file);
  }

  const anchors = new Set();
  const seen = new Map();
  let inFence = false;

  for (const line of readFileSync(file, "utf8").split("\n")) {
    if (/^\s*(```|~~~)/.test(line)) {
      inFence = !inFence;
      continue;
    }

    const match = !inFence && line.match(/^#{1,6}\s+(.*?)\s*#*\s*$/);

    if (match) {
      const base = slug(match[1].replace(/`/g, ""));
      const count = seen.get(base) ?? 0;
      anchors.add(count === 0 ? base : `${base}-${count}`);
      seen.set(base, count + 1);
    }
  }

  anchorCache.set(file, anchors);

  return anchors;
}

function linksOf(text) {
  const links = [];
  let inFence = false;

  text.split("\n").forEach((rawLine, index) => {
    if (/^\s*(```|~~~)/.test(rawLine)) {
      inFence = !inFence;
      return;
    }

    if (inFence) {
      return;
    }

    const line = rawLine.replace(/`[^`]*`/g, (code) => " ".repeat(code.length));

    for (const match of line.matchAll(/!?\[[^\]]*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/g)) {
      links.push({ target: match[1], line: index + 1 });
    }
  });

  return links;
}

const errors = [];
let checked = 0;

for (const file of files) {
  const absolute = join(root, file);

  for (const { target, line } of linksOf(readFileSync(absolute, "utf8"))) {
    if (/^(https?:|mailto:|data:)/i.test(target)) {
      continue;
    }

    checked++;
    const [pathPart, anchor] = target.split("#");
    const destination = pathPart === "" ? absolute : normalize(join(dirname(absolute), decodeURIComponent(pathPart)));

    if (!existsSync(destination)) {
      errors.push(`${file}:${line}: destinazione inesistente: ${target}`);
      continue;
    }

    if (anchor !== undefined && anchor !== "") {
      if (statSync(destination).isDirectory() || !destination.endsWith(".md")) {
        errors.push(`${file}:${line}: anchor su un file non Markdown: ${target}`);
      } else if (!anchorsOf(destination).has(decodeURIComponent(anchor).toLowerCase())) {
        errors.push(`${file}:${line}: anchor inesistente: ${target}`);
      }
    }
  }
}

if (errors.length > 0) {
  console.error(errors.join("\n"));
  console.error(`\n${errors.length} link non validi su ${checked} link relativi in ${files.length} file.`);
  process.exit(1);
}

console.log(`${checked} link relativi verificati in ${files.length} file Markdown: nessun errore.`);
