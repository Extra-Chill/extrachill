#!/usr/bin/env node
// Require every registered WordPress ability to be documented in its
// component's docs/abilities.md, and every documented ability H3 to exist.
// Run from any directory: node tools/check-abilities-docs.mjs
//
// Registrations are found in git-tracked PHP under plugins/<p>/ and
// themes/<t>/, excluding tests/ and vendor/ paths. Comments are ignored when
// looking for calls.
//
// - Literal names: `wp_register_ability( 'vendor/name', ... )`; the call may
//   span lines.
// - Non-literal names (a variable, concatenation, constant, sprintf, ...) are
//   not evaluated. Declare the names the call registers in a PHP comment
//   placed above it, after any earlier wp_register_ability() call in the file:
//
//       // abilities-docs: extrachill/list-review-submissions, extrachill/read-review-submissions
//       foreach ( array( 'list', 'read' ) as $action ) {
//           wp_register_ability( 'extrachill/' . $action . '-review-submissions', ... );
//
//   Names are separated by commas or whitespace; a trailing period is ignored
//   so the comment satisfies PHPCS. Long lists may span several
//   "abilities-docs:" comment lines. All declarations between the previous
//   call and a non-literal call belong to that call. When the first argument
//   is a concatenation starting or ending with a string literal (for example
//   `'extrachill/' . $x . '-suffix'`),
//   every declared name must start/end with it. An undeclared non-literal
//   call, or a declaration no call consumes, fails with file:line. Keep the
//   declaration next to the list it mirrors: the checker cannot evaluate PHP,
//   so adding a name to that list also means adding it here.
//
// Each component with at least one registered ability must have
// docs/abilities.md mentioning every name in backticks (`vendor/name`). Every
// `### \`vendor/name\`` heading in a docs/abilities.md must be registered by
// that component.
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const NAME = /^[a-z0-9-]+\/[a-z0-9-]+$/;
const DECLARATION = /abilities-docs:([^\n]*?)(?:\*\/|$)/gm;

// Split PHP source into code (comments blanked, newlines kept) and comments.
export function splitPhp(source) {
  let code = '';
  const comments = [];
  let i = 0;
  while (i < source.length) {
    const c = source[i];
    const next = source[i + 1];
    if (c === "'" || c === '"') {
      let j = i + 1;
      while (j < source.length && source[j] !== c) j += source[j] === '\\' ? 2 : 1;
      code += source.slice(i, j + 1);
      i = j + 1;
    } else if ((c === '/' && next === '/') || (c === '#' && next !== '[')) {
      let j = source.indexOf('\n', i);
      if (j === -1) j = source.length;
      comments.push({ index: i, text: source.slice(i, j) });
      code += ' '.repeat(j - i);
      i = j;
    } else if (c === '/' && next === '*') {
      let j = source.indexOf('*/', i + 2);
      j = j === -1 ? source.length : j + 2;
      const text = source.slice(i, j);
      comments.push({ index: i, text });
      code += text.replace(/[^\n]/g, ' ');
      i = j;
    } else {
      code += c;
      i += 1;
    }
  }
  return { code, comments };
}

// Return the first argument expression starting at `start` (just after "(").
function firstArgument(code, start) {
  let depth = 0;
  let i = start;
  while (i < code.length) {
    const c = code[i];
    if (c === "'" || c === '"') {
      let j = i + 1;
      while (j < code.length && code[j] !== c) j += code[j] === '\\' ? 2 : 1;
      i = j + 1;
      continue;
    }
    if ('([{'.includes(c)) depth += 1;
    else if (')]}'.includes(c)) {
      if (depth === 0) break;
      depth -= 1;
    } else if (c === ',' && depth === 0) break;
    i += 1;
  }
  return code.slice(start, i).trim();
}

function literal(expression) {
  const match = expression.match(/^'((?:\\.|[^'\\])*)'$/) || expression.match(/^"((?:\\.|[^"\\$])*)"$/);
  return match ? match[1].replace(/\\(['"\\])/g, '$1') : undefined;
}

const lineOf = (source, index) => source.slice(0, index).split('\n').length;

// Registered ability names in one PHP file, plus errors for unresolved names.
export function registeredAbilities(source, file) {
  const { code, comments } = splitPhp(source);
  const declarations = [];
  for (const comment of comments) {
    for (const match of comment.text.matchAll(DECLARATION)) {
      declarations.push({
        index: comment.index + match.index,
        names: match[1].split(/[\s,]+/).map((name) => name.replace(/\.$/, '')).filter(Boolean),
        used: false,
      });
    }
  }
  const names = [];
  const errors = [];
  let previous = -1;
  for (const call of code.matchAll(/(?<![\w$>:]|function\s+)wp_register_ability\s*\(/g)) {
    const index = call.index;
    const where = `${file}:${lineOf(source, index)}`;
    const expression = firstArgument(code, index + call[0].length);
    const name = literal(expression);
    if (name !== undefined) {
      if (NAME.test(name)) names.push(name);
      else errors.push(`${where}: invalid ability name '${name}'`);
    } else if (expression) {
      const covering = declarations.filter((d) => d.index > previous && d.index < index);
      const shown = expression.replace(/\s+/g, ' ');
      if (!covering.length) {
        errors.push(`${where}: non-literal ability name \`${shown}\`; add an "abilities-docs: <names>" comment above the call`);
      } else {
        covering.forEach((d) => { d.used = true; });
        const declared = covering.flatMap((d) => d.names);
        const prefix = expression.match(/^(['"])([^'"$\\]*)\1\s*\./)?.[2];
        const suffix = expression.match(/\.\s*(['"])([^'"$\\]*)\1$/)?.[2];
        if (!declared.length) errors.push(`${where}: empty abilities-docs declaration`);
        for (const name of declared) {
          if (!NAME.test(name)) errors.push(`${where}: invalid declared ability name '${name}'`);
          else if ((prefix && !name.startsWith(prefix)) || (suffix && !name.endsWith(suffix))) {
            errors.push(`${where}: declared ability '${name}' does not match \`${shown}\``);
          } else names.push(name);
        }
      }
    }
    previous = index;
  }
  for (const declaration of declarations.filter((d) => !d.used)) {
    errors.push(`${file}:${lineOf(source, declaration.index)}: abilities-docs declaration is not followed by a non-literal wp_register_ability() call`);
  }
  return { names, errors };
}

// Compare one component's registered names with its docs/abilities.md text.
export function documentationErrors(component, names, markdown) {
  const docs = `${component}/docs/abilities.md`;
  const registered = new Set(names);
  const errors = [];
  if (markdown === undefined) {
    if (registered.size) errors.push(`${docs} is missing; it must document: ${[...registered].sort().join(', ')}`);
    return errors;
  }
  for (const name of [...registered].sort()) {
    if (!markdown.includes(`\`${name}\``)) errors.push(`${docs}: missing entry for registered ability \`${name}\``);
  }
  for (const match of markdown.matchAll(/^ {0,3}###[ \t]+`([^`\n]+)`/gm)) {
    if (!registered.has(match[1])) errors.push(`${docs}: documents \`${match[1]}\`, which ${component} does not register`);
  }
  return errors;
}

export function checkAbilitiesDocs(base = root) {
  const files = execFileSync('git', ['ls-files', '-z', '--', 'plugins/*.php', 'themes/*.php'], { cwd: base, encoding: 'utf8' })
    .split('\0')
    .filter((file) => file && file.split('/').length > 2 && !file.split('/').slice(2).some((part) => part === 'tests' || part === 'vendor'));
  const components = new Map();
  const errors = [];
  for (const file of files) {
    const component = file.split('/').slice(0, 2).join('/');
    const result = registeredAbilities(readFileSync(path.join(base, file), 'utf8'), file);
    errors.push(...result.errors);
    if (!components.has(component)) components.set(component, []);
    components.get(component).push(...result.names);
  }
  const docs = execFileSync('git', ['ls-files', '-z', '--', 'plugins/*/docs/abilities.md', 'themes/*/docs/abilities.md'], { cwd: base, encoding: 'utf8' })
    .split('\0').filter(Boolean);
  for (const doc of docs) {
    const component = doc.split('/').slice(0, 2).join('/');
    if (!components.has(component)) components.set(component, []);
  }
  let count = 0;
  for (const [component, names] of [...components].sort()) {
    count += new Set(names).size;
    const file = path.join(base, component, 'docs/abilities.md');
    const markdown = existsSync(file) ? readFileSync(file, 'utf8') : undefined;
    errors.push(...documentationErrors(component, names, markdown));
  }
  return { errors, count };
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const { errors, count } = checkAbilitiesDocs();
  if (errors.length) {
    console.error(errors.join('\n'));
    process.exitCode = 1;
  } else {
    console.log(`All ${count} registered abilities are documented in their component's docs/abilities.md.`);
  }
}
