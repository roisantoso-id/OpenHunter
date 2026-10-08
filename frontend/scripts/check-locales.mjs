#!/usr/bin/env node
/**
 * Locale sanity check (run: `node scripts/check-locales.mjs` or `npm run check:locales`).
 *   1. zh-CN / en-US / id-ID have exactly the same key set
 *   2. every statically referenced key ('pages.x.y' / 'menu.x.y' string literals in src) exists
 *   3. every dynamic key prefix (`pages.x.${...}` template literals) has at least one key
 *   4. {placeholders} match across the three languages
 * Exits 1 on any failure.
 */
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SRC = path.join(root, 'src');
const LANGS = ['zh-CN', 'en-US', 'id-ID'];

const loadLocale = (lang) => {
  const txt = fs.readFileSync(path.join(SRC, 'locales', `${lang}.ts`), 'utf8').replace(/^\s*export default/m, 'return');
  return new Function(txt)();
};
const walk = (d) => fs.readdirSync(d, { withFileTypes: true }).flatMap((e) => {
  if (e.name.startsWith('.umi') || e.name === 'locales') return [];
  const p = path.join(d, e.name);
  return e.isDirectory() ? walk(p) : /\.(tsx?|jsx?)$/.test(e.name) ? [p] : [];
});

const locs = Object.fromEntries(LANGS.map((l) => [l, loadLocale(l)]));
const errors = [];

// 1. parity
const base = new Set(Object.keys(locs[LANGS[0]]));
for (const l of LANGS.slice(1)) {
  const ks = new Set(Object.keys(locs[l]));
  for (const k of base) if (!ks.has(k)) errors.push(`[parity] ${l} missing ${k}`);
  for (const k of ks) if (!base.has(k)) errors.push(`[parity] ${LANGS[0]} missing ${k} (present in ${l})`);
}

// 2 + 3. references in source
const statics = new Map();
const prefixes = new Map();
for (const f of [...walk(SRC), path.join(root, '.umirc.ts')]) {
  if (!fs.existsSync(f)) continue;
  const s = fs.readFileSync(f, 'utf8');
  const rel = path.relative(root, f);
  for (const m of s.matchAll(/['"]((?:pages|menu)\.[A-Za-z0-9_.]*[A-Za-z0-9_])['"]/g)) if (!statics.has(m[1])) statics.set(m[1], rel);
  for (const m of s.matchAll(/`((?:pages|menu)\.[A-Za-z0-9_.]*)\$\{/g)) if (!prefixes.has(m[1])) prefixes.set(m[1], rel);
}
for (const [k, f] of statics) for (const l of LANGS) if (!(k in locs[l])) errors.push(`[missing] ${l} ${k} (used in ${f})`);
for (const [p, f] of prefixes) if (![...base].some((k) => k.startsWith(p))) errors.push(`[dynamic] no key with prefix ${p} (used in ${f})`);

// 4. placeholders
const ph = (v) => [...String(v).matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join(',');
for (const k of base) {
  const want = ph(locs[LANGS[0]][k]);
  for (const l of LANGS.slice(1)) if (k in locs[l] && ph(locs[l][k]) !== want) errors.push(`[placeholder] ${k}: ${LANGS[0]}={${want}} ${l}={${ph(locs[l][k])}}`);
}

console.log(`keys: ${base.size} per language · static refs: ${statics.size} · dynamic prefixes: ${prefixes.size}`);
if (errors.length) {
  console.error(errors.join('\n'));
  console.error(`\n${errors.length} problem(s)`);
  process.exit(1);
}
console.log('OK');
