#!/usr/bin/env node
import { strict as assert } from 'node:assert';
import { spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { buildCasePlan } from './cases.mjs';
import { buildSettings, componentFiles, readComponents, root } from './settings.mjs';

const temporaryRoot = await mkdtemp(path.join(os.tmpdir(), 'auth-fuzz-validate-'));
try {
  const manifest = {};
  for (const [slug, checkoutFile] of Object.entries(componentFiles)) {
    const componentPath = path.join(temporaryRoot, slug);
    await mkdir(path.dirname(path.join(componentPath, checkoutFile)), { recursive: true });
    const fixture = slug === 'extrachill' ? '/*\nTheme Name: Auth Fuzz\n*/\n' : '<?php\n/* Plugin Name: Auth Fuzz */\n';
    await writeFile(path.join(componentPath, checkoutFile), fixture);
    if (slug === 'extrachill') await writeFile(path.join(componentPath, 'index.php'), '<?php\n');
    run('git', ['init', '--quiet', componentPath]);
    run('git', ['-C', componentPath, 'add', '.']);
    run('git', ['-C', componentPath, '-c', 'user.name=Auth Fuzz', '-c', 'user.email=auth-fuzz@example.test', 'commit', '--quiet', '-m', 'fixture']);
    manifest[slug] = { path: componentPath, version: run('git', ['-C', componentPath, 'rev-parse', 'HEAD']).stdout.trim() };
  }
  const manifestFile = path.join(temporaryRoot, 'components.json');
  await writeFile(manifestFile, `${JSON.stringify(manifest)}\n`);
  const components = await readComponents(manifestFile, { enforceUsersRoot: false });
  const plan = buildCasePlan('validation-seed');
  const settings = buildSettings(components, 'nightly', '8.4', plan.seed);

  assert.deepEqual(buildCasePlan('validation-seed'), plan);
  assert.notEqual(buildCasePlan('different-seed').generated_email, plan.generated_email);
  assert.equal(settings.wordpress_multisite_synthetic_fixture, false);
  assert.equal(settings.wp_codebox_extra_plugins.length, 6);
  assert.equal(settings.wp_codebox_extra_themes.length, 1);
  assert.equal(settings.wordpress_runtime_prepare_steps.length, 5);
  assert.equal(settings.wp_codebox_scenario_manifests.length, 2);
  assert.equal(settings.wordpress_runtime_post_steps.length, 1);
  assert.equal(settings.wordpress_runtime_prepare_steps[0].metadata.auth_fuzz_provenance.seed, 'validation-seed');

  for (const scenarioPath of settings.wp_codebox_scenario_manifests) {
    const scenario = JSON.parse(await readFile(scenarioPath, 'utf8'));
    assert.ok(Array.isArray(scenario.steps) && scenario.steps.length > 0);
    assert.ok(scenario.captures.includes('network'));
    assert.ok(scenario.assertions.some((assertion) => assertion.type === 'noPageErrors'));
  }
  for (const file of ['topology.php', 'activate.php', 'seed.php', 'assert.php', 'post-assert.php', 'README.md']) {
    assert.doesNotMatch(await readFile(path.join(root, file), 'utf8'), /\/var\/(?:lib\/datamachine\/workspace|www)\//);
  }

  const rigCheck = run(process.env.AUTH_FUZZ_HOMEBOY_BIN || 'homeboy', ['--placement', 'local', 'rig', 'check', 'wordpress-multisite-e2e'], {
    HOMEBOY_ARTIFACT_ROOT: path.join(temporaryRoot, 'artifacts'),
    HOMEBOY_NETWORK_E2E_RESULT_FILE: path.join(temporaryRoot, 'rig-result.json'),
    HOMEBOY_SETTINGS_JSON: JSON.stringify(settings),
  });
  process.stdout.write(rigCheck.stdout);
  process.stderr.write(rigCheck.stderr);
  console.log('Authentication fuzz plan, provenance, recipe, and browser schemas passed static validation.');
} finally {
  await rm(temporaryRoot, { recursive: true, force: true });
}

function run(command, args, extraEnv = {}) {
  const result = spawnSync(command, args, { encoding: 'utf8', env: { ...process.env, ...extraEnv }, maxBuffer: 20 * 1024 * 1024 });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`${command} ${args.join(' ')} failed:\n${result.stdout || ''}${result.stderr || ''}`);
  return result;
}
