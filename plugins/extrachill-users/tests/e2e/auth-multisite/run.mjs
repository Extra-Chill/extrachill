#!/usr/bin/env node
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { buildCasePlan } from './cases.mjs';
import { buildProvenance, buildSettings, readComponents, requiredWordPressVersion } from './settings.mjs';

const components = await readComponents();
const wordpress = process.env.AUTH_FUZZ_WORDPRESS_VERSION || requiredWordPressVersion;
const php = process.env.AUTH_FUZZ_PHP_VERSION;
const seed = process.env.AUTH_FUZZ_SEED || 'extrachill-auth-fuzz';
const artifactRoot = path.resolve(process.env.AUTH_FUZZ_ARTIFACT_ROOT || 'artifacts/auth-fuzz');
const plan = buildCasePlan(seed);
const settings = buildSettings(components, wordpress, php, seed);
const files = {
  result: path.join(artifactRoot, 'rig-result.json'),
  provenance: path.join(artifactRoot, 'auth-fuzz-provenance.json'),
  plan: path.join(artifactRoot, 'auth-fuzz-plan.json'),
  stdout: path.join(artifactRoot, 'homeboy-rig.stdout.log'),
  stderr: path.join(artifactRoot, 'homeboy-rig.stderr.log'),
};

await mkdir(artifactRoot, { recursive: true });
await Promise.all([
  writeFile(files.provenance, `${JSON.stringify(buildProvenance(components, wordpress, php, plan), null, 2)}\n`),
  writeFile(files.plan, `${JSON.stringify(plan, null, 2)}\n`),
]);
const result = spawnSync(process.env.AUTH_FUZZ_HOMEBOY_BIN || 'homeboy', ['--placement', 'local', 'rig', 'up', 'wordpress-multisite-e2e'], {
  encoding: 'utf8',
  env: { ...process.env, HOMEBOY_ARTIFACT_ROOT: artifactRoot, HOMEBOY_NETWORK_E2E_RESULT_FILE: files.result, HOMEBOY_SETTINGS_JSON: JSON.stringify(settings) },
  maxBuffer: 20 * 1024 * 1024,
});
await Promise.all([writeFile(files.stdout, result.stdout || ''), writeFile(files.stderr, result.stderr || '')]);
process.stdout.write(result.stdout || '');
process.stderr.write(result.stderr || '');
console.log(JSON.stringify({ artifactRoot, seed, ...files }, null, 2));
if (result.error) throw result.error;
if (result.status !== 0) throw new Error(`Homeboy multisite rig exited with status ${result.status}.`);
