// Content-addressed component build cache (run.mjs prepareMonorepoSources).
import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile, appendFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { buildOrRestoreComponent, componentBuildKey, componentBuildOutputs, localWorkspaceDependencies, prepareMonorepoSources, resolveWorkspacePackages } from '../run.mjs';

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

// In-tree packages (local_workspace_dependencies) feed the consumer's key.
async function workspaceFixture() {
  const root = await mkdtemp(path.join(os.tmpdir(), 'rig-workspace-'));
  const write = async (relative, contents) => {
    await mkdir(path.dirname(path.join(root, relative)), { recursive: true });
    await writeFile(path.join(root, relative), contents);
  };
  const deps = (...paths) => JSON.stringify({ extensions: { wordpress: { settings: { local_workspace_dependencies: paths.map((p) => ({ name: `@x/${path.basename(p)}`, path: p })) } } } });
  await write('packages/tokens/index.js', 'tokens');
  await write('packages/components/index.js', 'components');
  await write('packages/components/homeboy.json', deps('../tokens'));
  await write('plugins/demo/demo.php', '<?php');
  await write('plugins/demo/homeboy.json', deps('../../packages/components'));
  git(root, 'init', '-q');
  git(root, 'add', '.');
  git(root, 'commit', '-qm', 'init');
  return root;
}

test('in-tree packages resolve dependencies first and key the consumer', async () => {
  const root = await workspaceFixture();
  try {
    const consumer = path.join(root, 'plugins', 'demo');
    const dependencies = await localWorkspaceDependencies(consumer);
    assert.deepEqual(dependencies, [path.join(root, 'packages', 'components')]);

    const keys = await resolveWorkspacePackages(dependencies);
    assert.deepEqual([...keys.keys()], [path.join(root, 'packages', 'tokens'), path.join(root, 'packages', 'components')]);
    const before = componentBuildKey(consumer, undefined, dependencies.map((d) => keys.get(d)));

    // A change two levels down (tokens) must invalidate the consumer.
    await appendFile(path.join(root, 'packages', 'tokens', 'index.js'), ' v2');
    git(root, 'commit', '-qam', 'tokens');
    const after = await resolveWorkspacePackages(dependencies);
    assert.notEqual(componentBuildKey(consumer, undefined, dependencies.map((d) => after.get(d))), before);

    // An uncommitted package edit makes the consumer uncacheable.
    await appendFile(path.join(root, 'packages', 'components', 'index.js'), ' dirty');
    const dirty = await resolveWorkspacePackages(dependencies);
    assert.equal(componentBuildKey(consumer, undefined, dependencies.map((d) => dirty.get(d))), undefined);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test('prepareMonorepoSources builds in-tree packages before their consumers', async () => {
  const root = await workspaceFixture();
  try {
    const order = [];
    const build = async (directory) => { order.push(path.relative(root, directory)); };
    const plugin = { slug: 'demo', activations: [{ pluginFile: 'demo/demo.php' }] };
    const theme = { slug: 'missing-theme' };
    await prepareMonorepoSources({ extrachill_monorepo_source: root }, [plugin], theme, undefined, build);
    assert.deepEqual(order, ['packages/tokens', 'packages/components', 'plugins/demo']);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
