#!/usr/bin/env node
// Require every component's immediate docs/ directory in docs/README.md.
// Run from any directory: node tools/check-docs-index.mjs
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const index = readFileSync(path.join(root, 'docs/README.md'), 'utf8');
const links = [...index.matchAll(/\]\(\.\.\/((?:plugins|themes|packages)\/[^/\s)]+\/docs)(?:\/[^\s)]*)?\)/g)]
  .map((match) => match[1]);
const listed = new Set(links);
const actual = new Set();
for (const group of ['plugins', 'themes', 'packages']) {
  for (const entry of readdirSync(path.join(root, group), { withFileTypes: true })) {
    if (!entry.isDirectory()) continue;
    const docs = `${group}/${entry.name}/docs`;
    if (existsSync(path.join(root, docs)) && statSync(path.join(root, docs)).isDirectory()) {
      actual.add(docs);
    }
  }
}
const errors = [
  ...[...actual].filter((docs) => !listed.has(docs)).map((docs) => `Missing from docs/README.md: ${docs}`),
  ...[...listed].filter((docs) => !actual.has(docs)).map((docs) => `Stale docs/README.md entry: ${docs}`),
];
if (errors.length) {
  console.error(errors.join('\n'));
  process.exitCode = 1;
} else {
  console.log(`Documentation index covers all ${actual.size} component docs directories.`);
}
