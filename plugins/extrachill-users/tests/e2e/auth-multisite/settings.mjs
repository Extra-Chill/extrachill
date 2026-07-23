import { execFile } from 'node:child_process';
import { createHash } from 'node:crypto';
import { access, lstat, readFile, readdir, readlink, realpath } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { buildCasePlan } from './cases.mjs';

const execFileAsync = promisify(execFile);
const immutableRevision = /^[0-9a-f]{40}$/i;

export const root = path.dirname(fileURLToPath(import.meta.url));
export const repositoryRoot = path.resolve(root, '../../..');
export const componentFiles = {
  'extrachill-network': 'extrachill-network.php',
  'extrachill-api': 'extrachill-api.php',
  'wp-native': 'plugins/wp-native-auth/wp-native-auth.php',
  'extrachill-analytics': 'extrachill-analytics.php',
  'extrachill-users': 'extrachill-users.php',
  extrachill: 'style.css',
};

export async function readComponents(file = process.env.AUTH_FUZZ_COMPONENTS_FILE, { enforceUsersRoot = true } = {}) {
  if (!file) throw new Error('AUTH_FUZZ_COMPONENTS_FILE must name a JSON component manifest.');
  const manifest = JSON.parse(await readFile(path.resolve(file), 'utf8'));
  if (enforceUsersRoot) {
    const declared = await realpath(manifest['extrachill-users']?.path || '').catch(() => '');
    if (declared !== await realpath(repositoryRoot)) throw new Error(`extrachill-users.path must be ${repositoryRoot}.`);
  }
  for (const [slug, checkoutFile] of Object.entries(componentFiles)) {
    const component = manifest[slug];
    if (!component || !path.isAbsolute(component.path || '')) throw new Error(`${slug}.path must be absolute.`);
    if (!immutableRevision.test(component.version || '')) throw new Error(`${slug}.version must be a full Git revision.`);
    await access(path.join(component.path, checkoutFile));
    const head = (await execFileAsync('git', ['-C', component.path, 'rev-parse', 'HEAD'])).stdout.trim();
    const dirty = (await execFileAsync('git', ['-C', component.path, 'status', '--porcelain=v1', '--untracked-files=normal'])).stdout.trim() !== '';
    if (head !== component.version) throw new Error(`${slug}.version does not match checkout HEAD ${head}.`);
    if (dirty) throw new Error(`${slug}.path has uncommitted changes.`);
    manifest[slug] = { ...component, revision: head, contentSha256: await digestDirectory(component.path), dirty: false };
  }
  return manifest;
}

export function buildSettings(components, wordpressVersion, phpVersion, seed) {
  if (!wordpressVersion) throw new Error('AUTH_FUZZ_WORDPRESS_VERSION is required.');
  if (!phpVersion) throw new Error('AUTH_FUZZ_PHP_VERSION is required.');
  const plan = buildCasePlan(seed);
  const provenance = buildProvenance(components, wordpressVersion, phpVersion, plan);
  const fixture = path.join(root, 'fixture');
  return {
    wordpress_runtime_version: wordpressVersion,
    wordpress_runtime_php_version: phpVersion,
    wordpress_multisite_synthetic_fixture: false,
    wp_codebox_extra_plugins: [
      { source: fixture, slug: '00-auth-fuzz-fixture', pluginFile: '00-auth-fuzz-fixture/auth-fuzz-fixture.php', activate: false },
      plugin(components, 'extrachill-network', 'extrachill-network.php'),
      plugin(components, 'extrachill-api', 'extrachill-api.php'),
      plugin(components, 'wp-native', 'wp-native-auth.php', path.join(components['wp-native'].path, 'plugins/wp-native-auth'), 'wp-native-auth'),
      plugin(components, 'extrachill-analytics', 'extrachill-analytics.php'),
      plugin(components, 'extrachill-users', 'extrachill-users.php'),
    ],
    wp_codebox_extra_themes: [{
      source: components.extrachill.path,
      slug: 'extrachill',
      activate: true,
      metadata: { provenance: componentProvenance(components, 'extrachill', 'theme') },
    }],
    wordpress_runtime_prepare_steps: [
      phpStep('topology.php', { auth_fuzz_provenance: provenance }),
      phpStep('activate.php'),
      inlinePlanStep(plan),
      phpStep('seed.php'),
      phpStep('assert.php'),
    ],
    wp_codebox_scenario_manifests: [
      path.join(root, 'browser-anonymous.json'),
      path.join(root, 'browser-registration.json'),
    ],
    wordpress_runtime_post_steps: [phpStep('post-assert.php')],
  };
}

export function buildProvenance(components, wordpress, php, plan) {
  return {
    schema: 'extrachill-users/auth-fuzz-provenance/v1',
    wordpress,
    php,
    seed: plan.seed,
    replay: plan.replay,
    components: Object.keys(componentFiles).map((slug) => ({
      slug,
      ...(slug === 'extrachill' ? { kind: 'theme' } : {}),
      revision: components[slug].revision,
      content_sha256: components[slug].contentSha256,
      dirty: false,
    })),
  };
}

function plugin(components, component, file, source = components[component].path, slug = component) {
  return { source, slug, pluginFile: `${slug}/${file}`, activate: false, metadata: { provenance: componentProvenance(components, component) } };
}

function componentProvenance(components, slug, kind) {
  return { schema: 'extrachill-users/auth-fuzz-component/v1', component: slug, ...(kind ? { kind } : {}), revision: components[slug].revision, content_sha256: components[slug].contentSha256, dirty: false };
}

function phpStep(file, metadata) {
  return { command: 'wordpress.run-php', args: [`code-file=${path.join(root, file)}`], ...(metadata ? { metadata } : {}) };
}

function inlinePlanStep(plan) {
  const encoded = Buffer.from(JSON.stringify(plan)).toString('base64');
  return { command: 'wordpress.run-php', args: [`code=update_site_option( 'extrachill_auth_fuzz_plan', json_decode( base64_decode( '${encoded}' ), true ) );`] };
}

async function digestDirectory(directory, relative = '', hash = createHash('sha256')) {
  const entries = await readdir(path.join(directory, relative), { withFileTypes: true });
  entries.sort((a, b) => a.name.localeCompare(b.name));
  for (const entry of entries) {
    if (relative === '' && (entry.name === '.git' || entry.name === 'node_modules')) continue;
    const childRelative = path.join(relative, entry.name);
    const child = path.join(directory, childRelative);
    const stat = await lstat(child);
    hash.update(`${childRelative.split(path.sep).join('/')}\0${stat.mode.toString(8)}\0`);
    if (stat.isDirectory()) await digestDirectory(directory, childRelative, hash);
    else if (stat.isSymbolicLink()) hash.update(`link\0${await readlink(child)}\0`);
    else if (stat.isFile()) hash.update(await readFile(child));
  }
  return relative === '' ? hash.digest('hex') : hash;
}
