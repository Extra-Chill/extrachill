import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';
import { checkAbilitiesDocs, documentationErrors, registeredAbilities } from '../check-abilities-docs.mjs';

// Build a throwaway git repository, because the check scans git-tracked files.
function withRepo(files, callback) {
  const root = mkdtempSync(path.join(os.tmpdir(), 'abilities-docs-'));
  try {
    for (const [file, content] of Object.entries(files)) {
      mkdirSync(path.dirname(path.join(root, file)), { recursive: true });
      writeFileSync(path.join(root, file), content);
    }
    execFileSync('git', ['init', '-q'], { cwd: root });
    execFileSync('git', ['add', '-A'], { cwd: root });
    return callback(root);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
}

const doc = (...names) => `# Abilities\n\n${names.map((name) => `### \`${name}\`\n\n- **Purpose:** Test.\n`).join('\n')}`;

test('finds multi-line literal registrations and ignores comments, methods and definitions', () => {
  const source = [
    '<?php',
    '// wp_register_ability( \'demo/commented\', array() );',
    'wp_register_ability(',
    '\t\'demo/multi-line\',',
    '\tarray( \'label\' => \'x\' )',
    ');',
    'wp_register_ability( "demo/double", array() );',
    '$registry->wp_register_ability( $name, array() );',
    'function wp_register_ability( $name, $args ) {}',
  ].join('\n');
  assert.deepEqual(registeredAbilities(source, 'plugins/demo/a.php'), { names: ['demo/multi-line', 'demo/double'], errors: [] });
});

test('fails with file:line on an unresolved non-literal name', () => {
  const source = ['<?php', '', 'wp_register_ability( $name, array() );'].join('\n');
  const { names, errors } = registeredAbilities(source, 'plugins/demo/a.php');
  assert.deepEqual(names, []);
  assert.equal(errors.length, 1);
  assert.match(errors[0], /^plugins\/demo\/a\.php:3: non-literal ability name `\$name`/);
});

test('resolves non-literal names from abilities-docs declarations and checks prefixes', () => {
  const source = [
    '<?php',
    '// abilities-docs: demo/one, demo/two.',
    '// abilities-docs: demo/three.',
    'wp_register_ability( $name, array() );',
    '// abilities-docs: demo/get-thing, other/get-thing.',
    'wp_register_ability( \'demo/\' . $operation, array() );',
    '// abilities-docs: demo/orphan.',
  ].join('\n');
  const { names, errors } = registeredAbilities(source, 'plugins/demo/a.php');
  assert.deepEqual(names, ['demo/one', 'demo/two', 'demo/three', 'demo/get-thing']);
  assert.deepEqual(errors, [
    "plugins/demo/a.php:6: declared ability 'other/get-thing' does not match `'demo/' . $operation`",
    'plugins/demo/a.php:7: abilities-docs declaration is not followed by a non-literal wp_register_ability() call',
  ]);
});

test('reports missing entries, stale H3 entries and a missing docs file', () => {
  assert.deepEqual(documentationErrors('plugins/demo', ['demo/kept', 'demo/new'], doc('demo/kept', 'demo/removed')), [
    'plugins/demo/docs/abilities.md: missing entry for registered ability `demo/new`',
    'plugins/demo/docs/abilities.md: documents `demo/removed`, which plugins/demo does not register',
  ]);
  assert.deepEqual(documentationErrors('plugins/demo', ['demo/a'], undefined), [
    'plugins/demo/docs/abilities.md is missing; it must document: demo/a',
  ]);
  assert.deepEqual(documentationErrors('plugins/demo', [], undefined), []);
});

test('missing entry fails across a repository', () => {
  withRepo({
    'plugins/demo/inc/a.php': "<?php\nwp_register_ability( 'demo/documented', array() );\nwp_register_ability( 'demo/undocumented', array() );\n",
    'plugins/demo/docs/abilities.md': doc('demo/documented'),
  }, (root) => {
    assert.deepEqual(checkAbilitiesDocs(root).errors, ['plugins/demo/docs/abilities.md: missing entry for registered ability `demo/undocumented`']);
  });
});

test('stale H3 fails even for a component that registers nothing', () => {
  withRepo({
    'plugins/demo/inc/a.php': '<?php\n',
    'plugins/demo/docs/abilities.md': doc('demo/gone'),
  }, (root) => {
    assert.deepEqual(checkAbilitiesDocs(root).errors, ['plugins/demo/docs/abilities.md: documents `demo/gone`, which plugins/demo does not register']);
  });
});

test('excludes tests/ and vendor/ registrations', () => {
  withRepo({
    'plugins/demo/inc/a.php': "<?php\nwp_register_ability( 'demo/real', array() );\n",
    'plugins/demo/tests/fixture.php': "<?php\nwp_register_ability( 'demo/fixture', array() );\nwp_register_ability( $unresolved, array() );\n",
    'plugins/demo/vendor/lib/x.php': "<?php\nwp_register_ability( 'vendor/thing', array() );\n",
    'plugins/demo/docs/abilities.md': doc('demo/real'),
  }, (root) => {
    assert.deepEqual(checkAbilitiesDocs(root), { errors: [], count: 1 });
  });
});

test('passes when every registered ability, including declared ones, is documented', () => {
  withRepo({
    'plugins/demo/inc/a.php': "<?php\nwp_register_ability(\n\t'demo/literal',\n\tarray()\n);\n// abilities-docs: demo/dynamic.\nwp_register_ability( $name, array() );\n",
    'themes/look/inc/b.php': "<?php\nwp_register_ability( 'look/theme', array() );\n",
    'plugins/demo/docs/abilities.md': doc('demo/literal', 'demo/dynamic'),
    'themes/look/docs/abilities.md': 'Mentions `look/theme` in prose.\n',
    'plugins/plain/inc/c.php': '<?php\n',
  }, (root) => {
    assert.deepEqual(checkAbilitiesDocs(root), { errors: [], count: 3 });
  });
});

test('runs from any working directory', () => {
  const script = fileURLToPath(new URL('../check-abilities-docs.mjs', import.meta.url));
  const output = execFileSync(process.execPath, [script], { cwd: os.tmpdir(), encoding: 'utf8' });
  assert.match(output, /^All \d+ registered abilities are documented/);
});
