#!/usr/bin/env node
// Check local Markdown destinations without dependencies or network access.
// Run from any directory: node tools/check-docs-links.mjs
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export function markdownDestinations(markdown) {
  let fence;
  const prose = markdown.split(/\r?\n/).map((line) => {
    const marker = line.match(/^ {0,3}(`{3,}|~{3,})(.*)$/);
    if (fence) {
      if (marker && marker[1][0] === fence[0] && marker[1].length >= fence.length && !marker[2].trim()) fence = undefined;
      return '';
    }
    if (marker) {
      fence = marker[1];
      return '';
    }
    return line;
  }).join('\n').replace(/(`+)[\s\S]*?\1(?!`)/g, '');

  // Inline links and images, including angle-wrapped paths, optional titles,
  // escaped characters and parentheses in destinations. Reference-style links
  // are checked at their definitions (including collapsed/shortcut references).
  const destinations = [];
  const inline = /!?\[(?:\\.|[^\]\\])*\]\(\s*(<[^>\n]*>|(?:\\.|[^\s\\()]|\((?:\\.|[^\\()])*\))*)\s*(?:"[^"\n]*"|'[^'\n]*'|\([^\n)]*\))?\s*\)/g;
  const references = /^ {0,3}\[(?:\\.|[^\]\\])+\]:[ \t]*(<[^>\n]*>|\S+)/gm;
  for (const pattern of [inline, references]) {
    for (const match of prose.matchAll(pattern)) {
      destinations.push(match[1].replace(/^<|>$/g, '').replace(/\\([!"#$%&'()*+,\-./:;<=>?@[\]\\^_`{|}~])/g, '$1'));
    }
  }
  return destinations;
}

export function brokenLinks(markdown, file, base = root) {
  return markdownDestinations(markdown).filter((link) => {
    // Ignore all URI schemes, anchors and site-absolute URLs. Only local file
    // existence is checked; fragments and query strings are not file names.
    if (!link || /^(?:[a-z][a-z\d+.-]*:|#|\/)/i.test(link)) return false;
    let target = link.split(/[?#]/, 1)[0];
    try {
      target = decodeURIComponent(target);
    } catch {
      return true;
    }
    return !existsSync(path.resolve(base, path.dirname(file), target));
  });
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const files = execFileSync('git', ['ls-files', '-z', '--', '*.md'], { cwd: root, encoding: 'utf8' })
    .split('\0').filter((file) => file && path.basename(file) !== 'CHANGELOG.md' && !file.split('/').includes('node_modules'));
  const errors = [];
  for (const file of files) {
    for (const link of brokenLinks(readFileSync(path.join(root, file), 'utf8'), file)) {
      errors.push(`${file} -> ${link}`);
    }
  }
  if (errors.length) {
    console.error(errors.join('\n'));
    process.exitCode = 1;
  } else {
    console.log(`Relative documentation links resolve in all ${files.length} Markdown files.`);
  }
}
