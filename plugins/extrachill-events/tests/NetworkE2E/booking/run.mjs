#!/usr/bin/env node

import { spawnSync } from 'node:child_process';
import { mkdir, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const supportRoot = path.dirname(fileURLToPath(import.meta.url));
const componentRoot = path.resolve(supportRoot, '../../..');
const seed = process.env.BOOKING_E2E_SEED || 'booking-network-e2e-001';
const artifactRoot = path.resolve(process.env.BOOKING_E2E_ARTIFACT_ROOT || path.join(componentRoot, 'artifacts/booking-network-e2e'));
const mysqlImage = 'mysql:8.0@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b';
const databaseProvider = process.env.BOOKING_E2E_DATABASE_PROVIDER || 'docker';

if (!/^[A-Za-z0-9._-]{1,80}$/.test(seed)) {
  throw new Error('BOOKING_E2E_SEED must contain only letters, numbers, dots, underscores, or hyphens.');
}
if (!['docker', 'native'].includes(databaseProvider)) {
  throw new Error('BOOKING_E2E_DATABASE_PROVIDER must be docker or native.');
}

await mkdir(artifactRoot, { recursive: true });

const components = await resolveComponents();
const rigRunner = await resolveRigRunner();
const { buildRecipe } = await import(pathToFileURL(rigRunner));
const runtimeBootstrap = path.join(artifactRoot, 'assert-booking-network-e2e.php');

await writeFile(runtimeBootstrap, `<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/events/';
$_SERVER['SCRIPT_NAME'] = '/index.php';
define( 'REST_REQUEST', true );
putenv( 'WP_AGENT_RUNTIME=1' );
$booking_network_e2e_seed = ${JSON.stringify(seed)};
require '/wordpress/wp-load.php';
require '/wordpress/wp-content/booking-network-e2e-support/assert.php';
`);

const plugin = (slug, file) => ({
  source: components[slug].path,
  slug,
  pluginFile: `${slug}/${file}`,
  activate: false,
  metadata: { revision: components[slug].revision },
});

const settings = {
  wordpress_runtime_version: process.env.BOOKING_E2E_WORDPRESS_VERSION || '7.0.2',
  wordpress_runtime_php_version: process.env.BOOKING_E2E_PHP_VERSION || '8.4',
  wordpress_multisite_synthetic_fixture: false,
  wordpress_runtime_blueprint: {
    steps: [{
      step: 'defineWpConfigConsts',
      consts: {
        WP_ALLOW_MULTISITE: true,
        MULTISITE: true,
        SUBDOMAIN_INSTALL: false,
        DOMAIN_CURRENT_SITE: 'localhost',
        PATH_CURRENT_SITE: '/',
        SITE_ID_CURRENT_SITE: 1,
        BLOG_ID_CURRENT_SITE: 1,
      },
    }],
  },
  wp_codebox_extra_plugins: [
    plugin('extrachill-network', 'extrachill-network.php'),
    plugin('extrachill-users', 'extrachill-users.php'),
    plugin('extrachill-api', 'extrachill-api.php'),
    plugin('data-machine', 'data-machine.php'),
    plugin('data-machine-events', 'data-machine-events.php'),
    plugin('extrachill-events', 'extrachill-events.php'),
  ],
  wp_codebox_extra_themes: [{
    source: components.extrachill.path,
    slug: 'extrachill',
    activate: false,
    metadata: { revision: components.extrachill.revision },
  }],
  wordpress_runtime_prepare_steps: [
    { command: 'wordpress.run-php', args: [`code-file=${path.join(supportRoot, 'topology.php')}`], metadata: { phase: 'topology-and-activation' } },
    { command: 'wordpress.run-php', args: [`code-file=${runtimeBootstrap}`, 'bootstrap=none'], metadata: { phase: 'booking-network-e2e', seed } },
  ],
};

const recipe = await buildRecipe(settings, componentRoot);
recipe.inputs.mounts.push({
  type: 'directory',
  source: supportRoot,
  target: '/wordpress/wp-content/booking-network-e2e-support',
  mode: 'readonly',
  metadata: { kind: 'recipe-support', slug: 'booking-network-e2e' },
});
recipe.inputs.services = [{
  id: 'wordpress-database',
  kind: 'mysql',
  configuration: databaseProvider === 'native'
    ? { provider: 'native', engine: 'mariadb' }
    : { provider: 'docker', engine: 'mysql', image: mysqlImage, rootAuthentication: 'generated-password' },
  outputs: { host: 'DB_HOST', port: 'DB_PORT', username: 'DB_USER', password: 'DB_PASSWORD', database: 'DB_NAME' },
}];
recipe.artifacts = { directory: path.join(artifactRoot, 'wp-codebox') };

const recipeFile = path.join(artifactRoot, 'recipe.json');
await writeFile(recipeFile, `${JSON.stringify(recipe, null, 2)}\n`);

const replayCommand = `BOOKING_E2E_SEED=${seed} BOOKING_E2E_DATABASE_PROVIDER=${databaseProvider} BOOKING_E2E_ARTIFACT_ROOT=<new-directory> node tests/NetworkE2E/booking/run.mjs`;
const provenance = {
  schema: 'homeboy/fuzz-provenance/v1',
  id: `booking-network-e2e-${seed}`,
  seed,
  runtime: {
    wordpress: settings.wordpress_runtime_version,
    php: settings.wordpress_runtime_php_version,
    database: databaseProvider === 'native' ? 'native:mariadb' : mysqlImage,
  },
  components,
  primitive: { homeboy_rig: 'wordpress-multisite-e2e', runner: rigRunner, wp_codebox: commandVersion('wp-codebox', ['version']) },
  action_model: path.relative(componentRoot, path.join(supportRoot, 'action-model.json')),
  replay: replayCommand,
};
await writeJson(path.join(artifactRoot, 'provenance.json'), provenance);
await writeJson(path.join(artifactRoot, 'replay.json'), { schema: 'homeboy/fuzz-replay/v1', seed, command: replayCommand });

const run = spawnSync(process.env.HOMEBOY_WP_CODEBOX_BIN || 'wp-codebox', [
  'recipe-run', '--recipe', recipeFile, '--artifacts', recipe.artifacts.directory, '--timeout', '25m', '--json',
], { encoding: 'utf8', env: process.env, maxBuffer: 50 * 1024 * 1024 });

await writeFile(path.join(artifactRoot, 'wp-codebox.stdout.log'), run.stdout || '');
await writeFile(path.join(artifactRoot, 'wp-codebox.stderr.log'), run.stderr || '');
await writeFile(
  path.join(artifactRoot, 'runtime-result.json'),
  run.stdout || `${JSON.stringify({ success: false, error: run.error?.message || 'WP Codebox emitted no JSON result.' }, null, 2)}\n`
);

const marker = `${run.stdout || ''}\n${run.stderr || ''}`.match(/BOOKING_NETWORK_E2E_RESULT:([A-Za-z0-9+/=]+)/);
const campaign = marker ? JSON.parse(Buffer.from(marker[1], 'base64').toString('utf8')) : null;
const runtimeResult = parseJson(run.stdout);
const cases = campaign?.cases || [];
const findings = campaign?.findings || [];
const operations = campaign?.operations || [];
const runtimeFailure = run.error || (!campaign && run.status !== 0) || (!campaign && run.status === 0);

await writeFile(
  path.join(artifactRoot, 'case-log.jsonl'),
  cases.map((item, index) => JSON.stringify({ schema: 'homeboy/fuzz-case-log/v1', index, ...item })).join('\n') + (cases.length ? '\n' : '')
);

const coverage = {
  schema: 'homeboy/fuzz-coverage-summary/v1',
  declared_targets: 9,
  executable_targets: 9,
  proven_targets: campaign ? 9 : 0,
  target_coverage_ratio: campaign ? 1 : 0,
  declared_operations: 16,
  executable_operations: 16,
  proven_operations: operations.length,
  operation_coverage_ratio: operations.length / 16,
};
await writeJson(path.join(artifactRoot, 'coverage-summary.json'), coverage);

const status = runtimeFailure ? 'runtime_failure' : findings.length ? 'invariant_findings' : 'passed';
const envelope = {
  schema: 'homeboy/fuzz-result-envelope/v1',
  id: `booking-network-e2e-${seed}`,
  status,
  failure_class: runtimeFailure ? 'harness_runtime' : findings.length ? 'product_invariant' : null,
  metrics: {
    assertions: campaign?.assertions || 0,
    passed: campaign?.passed || 0,
    open_findings: findings.length,
    case_log_artifacts: 1,
    declared_targets: coverage.declared_targets,
    executable_targets: coverage.executable_targets,
    proven_targets: coverage.proven_targets,
    target_coverage_ratio: coverage.target_coverage_ratio,
    declared_operations: coverage.declared_operations,
    executable_operations: coverage.executable_operations,
    proven_operations: coverage.proven_operations,
    operation_coverage_ratio: coverage.operation_coverage_ratio,
  },
  findings,
  diagnostics: runtimeFailure ? [{
    code: run.error ? 'runner_spawn_failed' : 'campaign_result_missing',
    exit_code: run.status,
    message: run.error?.message || runtimeResult?.error?.message || runtimeResult?.result?.failure_summary || 'WP Codebox did not emit a booking campaign result.',
    stderr_artifact: 'wp-codebox.stderr.log',
  }] : [],
  artifacts: ['runtime-result.json', 'provenance.json', 'replay.json', 'case-log.jsonl', 'coverage-summary.json'],
};
await writeJson(path.join(artifactRoot, 'result-envelope.json'), envelope);
if (campaign) {
  await writeJson(path.join(artifactRoot, 'campaign-result.json'), campaign);
}

process.stdout.write(run.stdout || '');
process.stderr.write(run.stderr || '');
console.log(JSON.stringify({ artifactRoot, result: envelope }, null, 2));
if (runtimeFailure || findings.length || run.status !== 0) {
  process.exitCode = 1;
}

async function resolveComponents() {
  const parent = path.dirname(componentRoot);
  const declarations = {
    'data-machine': ['BOOKING_E2E_DATA_MACHINE', path.join(parent, 'data-machine')],
    'data-machine-events': ['BOOKING_E2E_DATA_MACHINE_EVENTS', path.join(parent, 'data-machine-events')],
    'extrachill-events': ['BOOKING_E2E_EXTRACHILL_EVENTS', componentRoot],
    'extrachill-api': ['BOOKING_E2E_EXTRACHILL_API', path.join(parent, 'extrachill-api')],
    'extrachill-network': ['BOOKING_E2E_EXTRACHILL_NETWORK', path.join(parent, 'extrachill-network')],
    'extrachill-users': ['BOOKING_E2E_EXTRACHILL_USERS', path.join(parent, 'extrachill-users')],
    extrachill: ['BOOKING_E2E_EXTRACHILL_THEME', path.join(parent, 'extrachill')],
  };
  const resolved = {};
  for (const [slug, [environmentName, fallback]] of Object.entries(declarations)) {
    const source = path.resolve(process.env[environmentName] || fallback);
    if (!(await stat(source).catch(() => null))?.isDirectory()) {
      throw new Error(`${environmentName} must point to the ${slug} checkout; missing directory: ${source}`);
    }
    const revision = git(source, ['rev-parse', 'HEAD']);
    const expected = process.env[`${environmentName}_REV`];
    if (expected && expected !== revision) {
      throw new Error(`${slug} revision mismatch: expected ${expected}, found ${revision}`);
    }
    resolved[slug] = { path: source, revision, dirty: git(source, ['status', '--porcelain=v1']).trim() !== '' };
  }
  return resolved;
}

async function resolveRigRunner() {
  if (process.env.BOOKING_E2E_RIG_RUNNER) {
    return path.resolve(process.env.BOOKING_E2E_RIG_RUNNER);
  }
  const result = spawnSync('homeboy', ['rig', 'list'], { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 });
  if (result.status !== 0) {
    throw new Error(`Unable to discover wordpress-multisite-e2e: ${result.stderr || result.stdout}`);
  }
  const payload = JSON.parse(result.stdout);
  const rig = payload.data?.payload?.rigs?.find((entry) => entry.id === 'wordpress-multisite-e2e');
  if (!rig?.source?.package_path) {
    throw new Error('Install the Homeboy wordpress-multisite-e2e rig before running this gate.');
  }
  return path.join(rig.source.package_path, 'run.mjs');
}

function git(directory, args) {
  const result = spawnSync('git', ['-C', directory, ...args], { encoding: 'utf8' });
  if (result.status !== 0) {
    throw new Error(`Git inspection failed for ${directory}: ${result.stderr}`);
  }
  return result.stdout.trim();
}

function commandVersion(command, args) {
  const result = spawnSync(process.env.HOMEBOY_WP_CODEBOX_BIN || command, args, { encoding: 'utf8' });
  return result.status === 0 ? result.stdout.trim() : 'unavailable';
}

async function writeJson(file, value) {
  await writeFile(file, `${JSON.stringify(value, null, 2)}\n`);
}

function parseJson(value) {
  try {
    return value ? JSON.parse(value) : null;
  } catch {
    return null;
  }
}
