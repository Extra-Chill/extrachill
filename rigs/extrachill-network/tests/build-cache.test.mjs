// Content-addressed component build cache (run.mjs prepareMonorepoSources).
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile, appendFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { buildOrRestoreComponent, componentBuildKey, componentBuildOutputs } from '../run.mjs';

const git = (cwd, ...args) => {
  const result = spawnSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@example.test', ...args], { cwd, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
};

async function fixture() {
  const root = await mkdtemp(path.join(os.tmpdir(), 'rig-build-cache-'));
  const component = path.join(root, 'plugins', 'demo');
  await mkdir(component, { recursive: true });
  await writeFile(path.join(component, 'demo.php'), '<?php // demo');
  await writeFile(path.join(component, 'committed.js'), 'original');
  await writeFile(path.join(component, '.gitignore'), 'build/\nnode_modules/\n');
  git(root, 'init', '-q');
  git(root, 'add', '.');
  git(root, 'commit', '-qm', 'init');
  return { root, component, cache: path.join(root, 'cache') };
}

// Stands in for composer/npm: writes an ignored output, install state, and
// rewrites a tracked file, the three kinds of change a real build makes.
let builds = 0;
async function fakeBuild(directory) {
  builds += 1;
  await mkdir(path.join(directory, 'build'), { recursive: true });
  await mkdir(path.join(directory, 'node_modules', 'pkg'), { recursive: true });
  await writeFile(path.join(directory, 'build', 'index.js'), `built ${builds}`);
  await writeFile(path.join(directory, 'node_modules', 'pkg', 'index.js'), 'install state');
  await writeFile(path.join(directory, 'committed.js'), 'rebuilt');
}

test('a build is cached, then restored without rebuilding', async () => {
  const { root, component, cache } = await fixture();
  try {
    await mkdir(cache);
    builds = 0;
    const first = await buildOrRestoreComponent(component, 'demo', cache, fakeBuild);
    assert.equal(first.status, 'built');
    assert.equal(builds, 1);
    assert.deepEqual(componentBuildOutputs(component), ['build/', 'committed.js']);

    // Fresh checkout: outputs and node_modules gone, tracked files reset.
    git(root, 'checkout', '-q', '--', '.');
    git(root, 'clean', '-qfdx', '--', 'plugins');
    const second = await buildOrRestoreComponent(component, 'demo', cache, fakeBuild);
    assert.equal(second.status, 'cached');
    assert.equal(builds, 1, 'cache hit must not run the build');
    assert.equal(await readFile(path.join(component, 'build', 'index.js'), 'utf8'), 'built 1');
    assert.equal(await readFile(path.join(component, 'committed.js'), 'utf8'), 'rebuilt');
    await assert.rejects(readFile(path.join(component, 'node_modules', 'pkg', 'index.js')), { code: 'ENOENT' });
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test('a committed source change gets a new key; an uncommitted one is never cached', async () => {
  const { root, component, cache } = await fixture();
  try {
    await mkdir(cache);
    const before = componentBuildKey(component);
    assert.match(before, /^[0-9a-f]{32}$/);

    await appendFile(path.join(component, 'demo.php'), "\n// edit");
    assert.equal(componentBuildKey(component), undefined, 'dirty tree must not be keyed');
    const dirty = await buildOrRestoreComponent(component, 'demo', cache, fakeBuild);
    assert.equal(dirty.status, 'built-uncacheable');
    assert.deepEqual(await readdir(cache), []);

    git(root, 'checkout', '-q', '--', '.');
    git(root, 'clean', '-qfdx', '--', 'plugins');
    await appendFile(path.join(component, 'demo.php'), "\n// edit");
    git(root, 'commit', '-qam', 'edit');
    const after = componentBuildKey(component);
    assert.ok(after && after !== before, 'committed change must change the key');
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test('without a cache directory the component is simply built', async () => {
  const { root, component } = await fixture();
  try {
    assert.equal((await buildOrRestoreComponent(component, 'demo', undefined, fakeBuild)).status, 'built');
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
