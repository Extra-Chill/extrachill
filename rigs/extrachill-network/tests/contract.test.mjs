/**
 * Env-independent contract test for the extrachill-network rig's recipe
 * builder. No live WP Codebox infrastructure is touched here; this only
 * asserts the shape of the generated wp-codebox/workspace-recipe/v1 document.
 */
import { strict as assert } from 'node:assert';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { buildCheckoutComponent, assembleDemoArgs, buildRecipe, demoEncodeArgs, demoClickLeadMs, demoLoadCuts, demoTrimmedTime, journeyMarkers, paceDemoSteps, domainIdsMuPluginSource, DOMAIN_IDS_MU_PLUGIN_FILENAME, SANDBOX_COMPAT_MU_PLUGIN_FILENAME, journeySeedSetting, journeySelection, latestPrefixedReleaseTag, pinMonorepoReleases, recipeRunArgs, recipeRunTimeout, setReleaseLookup, validateJourneyDocument } from '../run.mjs';

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const topology = JSON.parse(await readFile(path.join(packageRoot, 'network-topology.json'), 'utf8'));
const components = JSON.parse(await readFile(path.join(packageRoot, 'components.json'), 'utf8'));

const temporary = await mkdtemp(path.join(os.tmpdir(), 'extrachill-network-contract-'));
const themeDir = path.join(temporary, 'extrachill-theme');
const artifactsDir = path.join(temporary, 'artifacts');
process.env.HOMEBOY_ARTIFACT_ROOT = artifactsDir;

// Monorepo-released components resolve their newest prefixed tag over the
// network; the contract test stubs that lookup.
const releaseLookups = [];
setReleaseLookup(async (repo, prefix) => {
  releaseLookups.push([repo, prefix]);
  return `${prefix}9.9.9`;
});

try {
  // Data Machine extensions release from the data-machine monorepo, whose
  // GitHub Latest belongs to core, so they pin their own newest tag.
  for (const slug of ['data-machine-events', 'data-machine-socials', 'data-machine-business']) {
    const entry = components.plugins.find((component) => component.slug === slug);
    assert.equal(entry.repo, 'Extra-Chill/data-machine', slug);
    assert.equal(entry.releaseTagPrefix, `${slug}-v`, slug);
  }
  const core = components.plugins.find((component) => component.slug === 'data-machine');
  assert.equal(core.releaseTagPrefix, undefined, 'core owns releases/latest');
  const extensions = components.plugins.filter((component) => component.releaseTagPrefix);
  const pinned = await pinMonorepoReleases(
    [...extensions, core],
    { 'data-machine-socials': '/override/socials' },
    { 'data-machine-business': { ref: 'data-machine-business-v1.0.0' } },
  );
  assert.equal(pinned['data-machine-events'].ref, 'data-machine-events-v9.9.9');
  assert.equal(pinned['data-machine-socials'], undefined, 'override wins');
  assert.equal(pinned['data-machine-business'].ref, 'data-machine-business-v1.0.0', 'caller pin wins');
  assert.equal(pinned['data-machine'], undefined, 'core keeps releases/latest');
  assert.deepEqual(releaseLookups, [['Extra-Chill/data-machine', 'data-machine-events-v']]);

  const releasePages = [
    [{ tag_name: 'data-machine-v0.201.0' }, { tag_name: 'data-machine-events-v0.79.0', draft: true }, { tag_name: 'data-machine-events-v0.78.1', prerelease: true }],
    [{ tag_name: 'data-machine-events-v0.78.0' }],
  ];
  const fakeFetch = async (url) => {
    const page = Number(new URL(url).searchParams.get('page'));
    const body = page === 1 ? [...releasePages[0], ...Array(97).fill({ tag_name: 'data-machine-v0.1.0' })] : releasePages[page - 1] ?? [];
    return { ok: true, json: async () => body };
  };
  assert.equal(await latestPrefixedReleaseTag('Extra-Chill/data-machine', 'data-machine-events-v', fakeFetch, {}), 'data-machine-events-v0.78.0');
  assert.equal(await latestPrefixedReleaseTag('Extra-Chill/data-machine', 'nope-v', fakeFetch, {}), null);
  await assert.rejects(latestPrefixedReleaseTag('x/y', 'p', async () => ({ ok: false, status: 403 }), {}), /HTTP 403/);

  await mkdir(themeDir, { recursive: true });
  await writeFile(path.join(themeDir, 'style.css'), '/*\nTheme Name: Extra Chill\n*/\n');
  await writeFile(path.join(themeDir, 'index.php'), '<?php\n');

  // Monorepo sources beat release pins and legacy theme settings, while
  // components absent from the checkout retain their exact default URLs.
  const checkout = path.join(temporary, 'checkout');
  for (const dir of ['plugins/extrachill-users', 'plugins/extrachill-network', 'themes/extrachill']) {
    await mkdir(path.join(checkout, dir), { recursive: true });
  }
  await writeFile(path.join(checkout, 'plugins/extrachill-network/extrachill-network.php'), '<?php');
  await writeFile(path.join(checkout, 'plugins/extrachill-users/extrachill-users.php'), '<?php');
  await writeFile(path.join(checkout, 'themes/extrachill/style.css'), '/* Theme Name: Extra Chill */');
  const monorepo = await buildRecipe({ extrachill_monorepo_source: checkout, extrachill_theme_source: '/old/theme', extrachill_release_set: { 'extrachill-users': { ref: 'old' } } }, packageRoot);
  const localUsers = monorepo.inputs.extra_plugins.find((plugin) => plugin.slug === 'extrachill-users');
  assert.equal(localUsers.source, path.join(checkout, 'plugins/extrachill-users'));
  assert.equal(monorepo.inputs.mounts.find((mount) => mount.metadata?.kind === 'wordpress-theme').source, path.join(checkout, 'themes/extrachill'));
  // The network plugin is an ordinary member under plugins/ (no root special case).
  const networkPlugin = monorepo.inputs.extra_plugins.find((plugin) => plugin.slug === 'extrachill-network');
  assert.equal(networkPlugin.source, path.join(checkout, 'plugins/extrachill-network'));
  const monoOverride = await buildRecipe({ extrachill_monorepo_source: checkout, extrachill_component_source_overrides: { 'extrachill-users': '/override/users', 'extrachill-network': '/override/root', extrachill: '/override/theme' } }, packageRoot);
  assert.equal(monoOverride.inputs.extra_plugins.find((plugin) => plugin.slug === 'extrachill-users').source, '/override/users');
  assert.equal(monoOverride.inputs.extra_plugins.find((plugin) => plugin.slug === 'extrachill-network').source, '/override/root');
  assert.equal(monoOverride.inputs.mounts.find((mount) => mount.metadata?.kind === 'wordpress-theme').source, '/override/theme');
  await assert.rejects(buildRecipe({ extrachill_monorepo_source: 'relative' }, packageRoot), /absolute checkout path/);

  const buildDir = path.join(temporary, 'build-component');
  await mkdir(buildDir);
  await writeFile(path.join(buildDir, 'composer.json'), '{}');
  await writeFile(path.join(buildDir, 'package.json'), JSON.stringify({ scripts: { build: 'build-assets' } }));
  await writeFile(path.join(buildDir, 'package-lock.json'), '{}');
  const calls = [];
  await buildCheckoutComponent(buildDir, async (...args) => calls.push(args));
  assert.deepEqual(calls, [
    ['composer', ['install', '--no-dev', '--prefer-dist', '--no-interaction', '--optimize-autoloader'], buildDir],
    ['npm', ['ci', '--include=dev'], buildDir],
    ['npm', ['run', 'build'], buildDir],
  ]);
  await assert.rejects(buildCheckoutComponent(buildDir, async () => { throw new Error('build failed'); }), /build failed/);

  // 1. No theme source supplied: recipe still builds (dry-run/check must not
  // require a local checkout of the theme repo), just without a theme mount.
  const withoutTheme = await buildRecipe({}, packageRoot);
  assert.equal(withoutTheme.schema, 'wp-codebox/workspace-recipe/v1');
  assert.equal(withoutTheme.runtime.backend, 'wordpress');
  assert.equal(withoutTheme.inputs.extra_themes, undefined);
  for (const plugin of withoutTheme.inputs.extra_plugins) {
    if (['extrachill-network', 'extrachill-users'].includes(plugin.slug)) continue;
    assert.equal(monorepo.inputs.extra_plugins.find((entry) => entry.slug === plugin.slug).source, plugin.source, `${plugin.slug} absent from checkout retains default`);
  }
  assert.equal(withoutTheme.inputs.siteSeeds.length, 1);
  const seed = withoutTheme.inputs.siteSeeds[0];
  assert.equal(seed.type, 'parent_site');
  assert.equal(seed.bootstrap.multisite.enabled, true);
  assert.equal(seed.bootstrap.multisite.install, 'subdomain');
  assert.equal(seed.bootstrap.multisite.sites.length, topology.sites.length);
  assert.equal(seed.bootstrap.domains.filter((domain) => domain.primary).length, 1);
  assert.equal(withoutTheme.inputs.extra_plugins.length, components.plugins.length);
  for (const plugin of withoutTheme.inputs.extra_plugins) {
    assert.equal(plugin.activate, false, `${plugin.slug} must mount without wp-codebox's own activation; the rig activates plugins itself per-site`);
    assert.match(plugin.source, /^https:\/\//, `${plugin.slug} must default to a remote source`);
  }
  assert.equal(withoutTheme.workflow.steps.length, 2 + topology.sites.length, 'activate + assert + browser-probe-per-site (no theme step when extrachill_theme_source is unset)');

  // 1b. The domain-ID mu-plugin is mounted on every boot: file mount exists,
  // the source was generated into the artifacts root, and the generated PHP
  // covers every topology domain with a constant mapping.
  const muMount = withoutTheme.inputs.mounts.find((mount) => mount.metadata?.kind === 'extrachill-network-domain-ids');
  assert.ok(muMount, 'the generated EC_BLOG_ID mu-plugin must be mounted on every boot');
  assert.equal(muMount.type, 'file');
  assert.match(muMount.target, /^\/wordpress\/wp-content\/mu-plugins\//);
  const muSource = await readFile(muMount.source, 'utf8');
  assert.match(muSource, /events\.extrachill\.com'\s+=>\s+'EC_BLOG_ID_EVENTS'/);
  for (const site of topology.sites) {
    assert.ok(
      muSource.includes(`'${site.domain}' => 'EC_BLOG_ID_`),
      `generated mu-plugin must map topology domain ${site.domain}`,
    );
  }
  assert.match(muSource, /WP_INSTALLING/, 'mu-plugin must skip while installing');
  assert.match(muSource, /function_exists\( ?'get_sites' ?\)/, 'mu-plugin must skip requests where the multisite API is not loaded yet');

  // 1b'. The sandbox-compat mu-plugin is mounted on every boot
  // (extrachill-network#302): http URL resolution, Playground redirect-host
  // re-merge, SQLite advisory-lock answers.
  const compatMount = withoutTheme.inputs.mounts.find((mount) => mount.metadata?.kind === 'extrachill-network-sandbox-compat');
  assert.ok(compatMount, 'the sandbox-compat mu-plugin must be mounted on every boot');
  assert.equal(compatMount.target, `/wordpress/wp-content/mu-plugins/${SANDBOX_COMPAT_MU_PLUGIN_FILENAME}`);
  const compatSource = await readFile(compatMount.source, 'utf8');
  assert.match(compatSource, /'ec_site_url_override'/);
  // Redirect hosts and the production egress fence are WP Codebox's job since v0.28.2 (#2533, #2534).
  assert.doesNotMatch(compatSource, /'allowed_redirect_hosts'|'pre_http_request'/);
  assert.match(compatSource, /'clean_url', 'ec_rig_downgrade_network_urls'/);
  assert.match(compatSource, /GET_LOCK\|RELEASE_LOCK\|IS_FREE_LOCK/);
  assert.match(compatSource, /'extrachill_api_rate_limit_store'/);
  assert.match(compatSource, /'extrachill_users_registration_admitter'/);

  // 1c. domainIdsMuPluginSource refuses a topology that dropped a mapped domain.
  assert.throws(
    () => domainIdsMuPluginSource({ sites: topology.sites.filter((site) => site.domain !== 'events.extrachill.com') }),
    /events\.extrachill\.com/,
  );

  // 1d. journeySelection validates its shape.
  assert.deepEqual(journeySelection({}), []);
  assert.deepEqual(journeySelection({ extrachill_journeys: ['gardner-event-rsvp'] }), ['gardner-event-rsvp']);
  assert.throws(() => journeySelection({ extrachill_journeys: 'gardner-event-rsvp' }), /array/);
  assert.throws(() => journeySelection({ extrachill_journeys: [42] }), /non-empty journey ID/);
  assert.throws(() => journeySelection({ extrachill_journeys: ['a', 'a'] }), /more than once/);

  // 2. Local theme checkout path: mounts as a directory, adds a theme
  // activation workflow step (matching wordpress-multisite-e2e's own pattern).
  const withLocalTheme = await buildRecipe({ extrachill_theme_source: themeDir }, packageRoot);
  const themeMount = withLocalTheme.inputs.mounts.find((mount) => mount.metadata?.kind === 'wordpress-theme');
  assert.ok(themeMount, 'local theme checkout mounts as a directory beside the mu-plugin');
  assert.equal(themeMount.source, themeDir);
  assert.equal(withLocalTheme.inputs.extra_themes, undefined);
  assert.ok(withLocalTheme.workflow.steps.some((step) => step.metadata?.kind === 'wordpress-theme-activation'));

  // 3. Remote https zip theme source: forward-compatible inputs.extra_themes
  // (Automattic/wp-codebox#2519). No local mount, no activation step needed
  // since wp-codebox's own extra_themes activation handles it.
  const withRemoteTheme = await buildRecipe({ extrachill_theme_source: 'https://github.com/Extra-Chill/extrachill-theme/releases/latest/download/extrachill.zip' }, packageRoot);
  assert.equal(withRemoteTheme.inputs.mounts.find((mount) => mount.metadata?.kind === 'wordpress-theme'), undefined);
  assert.equal(withRemoteTheme.inputs.extra_themes.length, 1);
  assert.equal(withRemoteTheme.inputs.extra_themes[0].activate, true);
  assert.ok(!withRemoteTheme.workflow.steps.some((step) => step.metadata?.kind === 'wordpress-theme-activation'));

  // 4. Component source overrides and a release-set pin change the resolved
  // source without touching the checked-in default manifest.
  const overridden = await buildRecipe({
    extrachill_component_source_overrides: { 'extrachill-network': '/abs/local/extrachill-network' },
    extrachill_release_set: { 'extrachill-users': { ref: 'v0.42.8' } },
  }, packageRoot);
  const overriddenNetwork = overridden.inputs.extra_plugins.find((plugin) => plugin.slug === 'extrachill-network');
  assert.equal(overriddenNetwork.source, '/abs/local/extrachill-network');
  const pinnedUsers = overridden.inputs.extra_plugins.find((plugin) => plugin.slug === 'extrachill-users');
  assert.equal(pinnedUsers.source, 'https://github.com/Extra-Chill/extrachill-users/releases/download/v0.42.8/extrachill-users.zip');

  // 5. Excluded components (no public source) are not mounted by default and
  // require both the opt-in flag and an explicit override to appear.
  assert.ok(!withoutTheme.inputs.extra_plugins.some((plugin) => plugin.slug === 'intelligence'));
  const withIntelligence = await buildRecipe({
    extrachill_include_excluded_components: ['intelligence'],
    extrachill_component_source_overrides: { intelligence: '/abs/local/intelligence' },
  }, packageRoot);
  assert.ok(withIntelligence.inputs.extra_plugins.some((plugin) => plugin.slug === 'intelligence' && plugin.source === '/abs/local/intelligence'));

  // 6. wp.org sources hit the always-allowed default download host with no
  // extra WP_CODEBOX_ALLOWED_DOWNLOAD_HOSTS configuration required.
  const woocommerce = withoutTheme.inputs.extra_plugins.find((plugin) => plugin.slug === 'woocommerce');
  assert.equal(woocommerce.source, 'https://downloads.wordpress.org/plugin/woocommerce.zip');

  // 7. Every browser-probe step targets a real declared site domain and
  // blocks all other network egress.
  const probes = withoutTheme.workflow.steps.filter((step) => step.metadata?.kind === 'extrachill-network-baseline-page-load');
  assert.equal(probes.length, topology.sites.length);
  for (const site of topology.sites) {
    const probe = probes.find((step) => step.metadata.domain === site.domain);
    assert.ok(probe, `missing baseline browser probe for ${site.domain}`);
    assert.ok(probe.args.includes(`route-host=${site.domain}`));
    assert.ok(probe.args.includes('network-policy=block'));
  }

  // 8. Journey contract: selecting a journey appends seed -> steps -> grade
  // after the baseline, merges its runtimeEnv, and stamps every step.
  const withJourney = await buildRecipe({ extrachill_journeys: ['gardner-event-rsvp'] }, packageRoot);
  const journeyMeta = withJourney.metadata?.extrachillJourneys;
  assert.deepEqual(journeyMeta, [{ id: 'gardner-event-rsvp', persona: 'extra-chill-users/chris-gardner', sites: ['events.extrachill.com', 'extrachill.com'] }]);
  assert.equal(withJourney.inputs.runtimeEnv?.WP_AGENT_RUNTIME, '1', 'journey runtimeEnv merges into the recipe inputs');
  assert.equal(withoutTheme.inputs.runtimeEnv, undefined, 'no runtimeEnv when no journey is selected');

  const kinds = withJourney.workflow.steps.map((step) => step.metadata?.kind ?? 'baseline');
  const firstJourneyIndex = kinds.indexOf('journey-seed');
  assert.ok(firstJourneyIndex > 0, 'journey seed step exists');
  assert.equal(kinds[kinds.indexOf('extrachill-network-assertion')], 'extrachill-network-assertion');
  assert.ok(firstJourneyIndex > kinds.lastIndexOf('extrachill-network-baseline-page-load'), 'journeys run after the full baseline');
  const gradeIndex = kinds.lastIndexOf('journey-grade');
  assert.ok(gradeIndex > firstJourneyIndex, 'grade runs after the journey steps');
  for (let i = firstJourneyIndex + 1; i < gradeIndex; ++i) {
    assert.equal(withJourney.workflow.steps[i].metadata.journey, 'gardner-event-rsvp', `step ${i} belongs to the journey`);
  }
  // Journey isolation: every journey step may fail without stopping the
  // recipe; baseline steps (activation, assertion, page probes) stay fatal.
  for (const step of withJourney.workflow.steps) {
    if (step.metadata?.journey) {
      assert.equal(step.allowFailure, true, `journey step ${step.metadata.kind} must be isolated (allowFailure)`);
    }
  }
  assert.equal(withJourney.workflow.steps.find((step) => step.metadata?.kind === 'extrachill-network-assertion')?.allowFailure, undefined, 'baseline assertion stays fatal');

  const seedStep = withJourney.workflow.steps[firstJourneyIndex];
  assert.equal(seedStep.command, 'wordpress.run-php');
  assert.match(seedStep.args[0], /^code-file=/, 'seed runs a code-file from the journey directory');
  const gradeStep = withJourney.workflow.steps[gradeIndex];
  assert.equal(gradeStep.command, 'wordpress.run-php');
  assert.match(gradeStep.args[0], /^code-file=/);

  // 8b. Journey browser steps are domain-scoped: absolute URLs on declared
  // sites, route-host/allow-host within the journey's sites, no egress leaks.
  const journeyBrowserSteps = withJourney.workflow.steps.filter((step) => step.metadata?.kind === 'journey-browser-step');
  assert.ok(journeyBrowserSteps.length >= 5, 'the gardner-event-rsvp journey ships multiple browser steps');
  for (const step of journeyBrowserSteps) {
    const urlArg = step.args.find((arg) => arg.startsWith('url='));
    assert.ok(urlArg, 'journey browser step carries an absolute url');
    const host = new URL(urlArg.slice('url='.length)).host;
    assert.ok(['events.extrachill.com', 'extrachill.com'].includes(host), `journey step targets declared site, got ${host}`);
    assert.ok(step.args.some((arg) => arg === `route-host=${host}`), 'journey step pins route-host to the url host');
    assert.ok(step.args.includes('network-policy=block'), 'journey step blocks other network egress');
  }

  // 8c. Unknown journeys, foreign domains, and missing persona files fail loudly.
  await assert.rejects(
    () => buildRecipe({ extrachill_journeys: ['nope'] }, packageRoot),
    /Unknown extrachill_journeys entry 'nope'/,
  );

  const doc = (overrides = {}) => ({
    schema: 'extrachill-network/journey/v1',
    id: 'gardner-event-rsvp',
    sites: ['events.extrachill.com'],
    persona: { file: 'gardner.v1.json' },
    steps: [{ command: 'wordpress.browser-actions', args: ['url=http://events.extrachill.com/', 'route-host=events.extrachill.com'] }],
    ...overrides,
  });
  await assert.rejects(validateJourneyDocument(doc({ schema: 'other/v1' }), 'gardner-event-rsvp', topology), /must declare schema/);
  await assert.rejects(validateJourneyDocument(doc({ id: 'other' }), 'gardner-event-rsvp', topology), /must match the directory name/);
  await assert.rejects(validateJourneyDocument(doc({ sites: [] }), 'gardner-event-rsvp', topology), /non-empty "sites"/);
  await assert.rejects(validateJourneyDocument(doc({ sites: ['nonexistent.example'] }), 'gardner-event-rsvp', topology), /never by blog ID/);
  await assert.rejects(validateJourneyDocument(doc(), 'gardner-event-rsvp', topology, async () => false), /missing from rigs\/extrachill-network\/personas\//);
  await assert.rejects(validateJourneyDocument(doc({ grade: { codeFile: 'grade.php' } }), 'gardner-event-rsvp', topology, async () => true, async () => false), /grade\.php' does not exist/);
  await assert.rejects(validateJourneyDocument(doc({ steps: [{ command: 'wordpress.browser-actions', args: ['url=/events/x'] }] }), 'gardner-event-rsvp', topology), /non-absolute url/);
  await assert.rejects(validateJourneyDocument(doc({ steps: [{ command: 'wordpress.browser-actions', args: ['url=http://artist.extrachill.com/'] }] }), 'gardner-event-rsvp', topology), /outside the journey's declared sites/);
  await assert.rejects(validateJourneyDocument(doc({ steps: [{ command: 'wordpress.browser-actions', args: ['url=http://events.extrachill.com/', 'route-host=extrachill.com'] }] }), 'gardner-event-rsvp', topology), /route-host/);

  // Repeatable allow-host/route-host (a journey step driving a real
  // cross-site flow, e.g. a browser-handoff redirect, legitimately declares
  // more than one host): every declared host is checked, not just the first.
  const multiHostDoc = {
    schema: 'extrachill-network/journey/v1',
    id: 'gardner-event-rsvp',
    sites: ['events.extrachill.com', 'extrachill.com'],
    steps: [{ command: 'wordpress.browser-actions', args: ['url=http://events.extrachill.com/', 'route-host=events.extrachill.com', 'allow-host=events.extrachill.com', 'allow-host=extrachill.com'] }],
  };
  await validateJourneyDocument(multiHostDoc, 'gardner-event-rsvp', topology);
  await assert.rejects(
    validateJourneyDocument({ ...multiHostDoc, steps: [{ ...multiHostDoc.steps[0], args: [...multiHostDoc.steps[0].args, 'allow-host=outside.example'] }] }, 'gardner-event-rsvp', topology),
    /allow-host to 'outside\.example'/,
  );
  await assert.rejects(validateJourneyDocument(doc({ steps: [{ command: 'wordpress.browser-actions', metadata: { kind: 'baseline' }, args: [] }] }), 'gardner-event-rsvp', topology), /reserved metadata kind/);

  const demoDoc = doc({ demo: { video: { viewport: '390x844', size: '390x844', output: { width: 390, height: 844, fps: 30 } }, presentation: {}, theme: 'extra-chill.json', steps: [] } });
  await validateJourneyDocument(demoDoc, 'gardner-event-rsvp', topology);
  await assert.rejects(validateJourneyDocument({ ...demoDoc, demo: { ...demoDoc.demo, video: { viewport: 'phone' } } }, 'gardner-event-rsvp', topology), /viewport must be WxH/);
  await assert.rejects(validateJourneyDocument({ ...demoDoc, demo: { ...demoDoc.demo, theme: 'missing.json' } }, 'gardner-event-rsvp', topology), /unknown demo theme/);
  await assert.rejects(validateJourneyDocument({ ...demoDoc, demo: { ...demoDoc.demo, video: { ...demoDoc.demo.video, maxSeconds: 0 } } }, 'gardner-event-rsvp', topology), /maxSeconds/);
  const homepageDemo = JSON.parse(await readFile(path.join(packageRoot, 'journeys', 'calendar-going-share', 'journey.json'), 'utf8'));
  assert.ok(homepageDemo.demo.video.maxSeconds <= 59, 'calendar demo is capped under one minute');
  const recorded = [
    { kind: 'navigate', videoOffsetMs: { startMs: 0, endMs: 5000 } },
    { kind: 'annotate', videoOffsetMs: { startMs: 5000, endMs: 5010 } },
    { kind: 'waitFor', waitFor: 'duration', videoOffsetMs: { startMs: 5010, endMs: 7010 } },
    { kind: 'click', videoOffsetMs: { startMs: 7010, endMs: 26000 } },
    { kind: 'waitFor', waitFor: 'duration', videoOffsetMs: { startMs: 26000, endMs: 26900 } },
    { kind: 'waitFor', waitFor: 'load', videoOffsetMs: { startMs: 26900, endMs: 48000 } },
    { kind: 'click', videoOffsetMs: { startMs: 48000, endMs: 49000 } },
    { kind: 'expect', videoOffsetMs: { startMs: 49000, endMs: 49100 } },
  ];
  const cuts = demoLoadCuts(recorded, 600);
  assert.deepEqual(cuts, [[0, 5600], [7610, 26000], [26900, 48000]], 'the recorder lead-in through the first loaded frame is cut, as are later loads; authored waits and in-page clicks are kept');
  assert.equal(demoTrimmedTime(49100, cuts), 49100 - 5600 - 18390 - 21100);
  assert.equal(demoTrimmedTime(3000, cuts), 0, 'a time inside the initial lead-in maps to the clean opening frame');
  assert.equal(demoTrimmedTime(6000, cuts), 400, 'marker/still timestamps shift by the removed lead-in');
  assert.match(demoEncodeArgs('in.webm', 'out.mp4', { video: { viewport: '540x960' } }, [[600, 5000]])[6], /^select='not\(between\(t,0\.600,5\.000\)\)',setpts=N\/FRAME_RATE\/TB,format=yuv420p$/);
  assert.deepEqual(demoLoadCuts([{ kind: 'annotate', videoOffsetMs: { startMs: 2500, endMs: 2510 } }]), [[0, 2500]], 'lead-in before the first step is cut');
  {
    const clickNav = [
      { kind: 'navigate', videoOffsetMs: { startMs: 0, endMs: 100 } },
      { kind: 'click', videoOffsetMs: { startMs: 4000, endMs: 6000 } },
      { kind: 'waitFor', waitFor: 'load', videoOffsetMs: { startMs: 6000, endMs: 6200 } },
    ];
    const lead = demoClickLeadMs({ motion: { moveDurationMs: 600 }, clickFeedback: { enabled: true } });
    assert.equal(lead, 850, 'click lead covers the cursor glide plus click feedback');
    assert.deepEqual(demoLoadCuts(clickNav, 500, lead), [[0, 600], [5350, 6200]], 'a navigating click keeps its cursor glide and feedback before the load is cut');
    assert.deepEqual(demoLoadCuts(clickNav, 500), [[0, 600], [4500, 6200]], 'without presentation timing the old cut is unchanged');
    assert.equal(demoClickLeadMs(undefined), 250);
  }
  assert.match(homepageDemo.steps[0].args.find((arg) => arg.startsWith('url=')), /^url=http:\/\/extrachill\.com\/$/, 'calendar demo starts on the homepage');
  await assert.rejects(validateJourneyDocument({ ...demoDoc, demo: { ...demoDoc.demo, environment: { colorScheme: 'sepia' } } }, 'gardner-event-rsvp', topology), /colorScheme/);
  await assert.rejects(validateJourneyDocument({ ...demoDoc, demo: { ...demoDoc.demo, cover: { marker: 'missing' } } }, 'gardner-event-rsvp', topology), /cover.marker/);
  await assert.rejects(validateJourneyDocument({ ...demoDoc, steps: [{ marker: 'known' }], demo: { ...demoDoc.demo, steps: [{ after: 'missing' }] } }, 'gardner-event-rsvp', topology), /unknown markers/);
  const theme = { accentColor: '#53940b' };
  assert.deepEqual(assembleDemoArgs(demoDoc.demo, theme), ['capture=steps,console,errors,network,screenshot,video', 'viewport=390x844', 'video-size=390x844', 'presentation-json={}', 'annotation-theme-json={"accentColor":"#53940b"}', 'is-mobile=true', 'has-touch=true']);
  assert.ok(assembleDemoArgs({ ...demoDoc.demo, environment: { colorScheme: 'dark' } }).includes('browser-environment-json={"colorScheme":"dark"}'));
  const pacingInput = JSON.stringify([{ kind: 'navigate' }, { kind: 'annotate', shape: 'caption' }, { kind: 'click' }]);
  assert.deepEqual(JSON.parse(paceDemoSteps(pacingInput, { settleMs: 1800, minCaptionMs: 2500 })), [{ kind: 'navigate' }, { kind: 'waitFor', waitFor: 'duration', duration: '1800ms' }, { kind: 'annotate', shape: 'caption' }, { kind: 'waitFor', waitFor: 'duration', duration: '2500ms' }, { kind: 'click' }, { kind: 'waitFor', waitFor: 'duration', duration: '1800ms' }]);
  assert.equal(paceDemoSteps(pacingInput), pacingInput);
  assert.ok(!assembleDemoArgs({ ...demoDoc.demo, video: { viewport: '1280x720' } }).includes('is-mobile=true'));
  assert.deepEqual(demoEncodeArgs('in.webm', 'out.mp4', { video: { viewport: '540x960', output: { width: 1080, height: 1920, fps: 30 } } }).slice(5, 7), ['-vf', 'scale=1080:1920:flags=lanczos,fps=30,format=yuv420p']);
  const demoJourney = JSON.parse(await readFile(path.join(packageRoot, 'journeys', 'calendar-going-share', 'journey.json'), 'utf8'));
  assert.ok(journeyMarkers(demoJourney).includes('going'), 'calendar-going-share must mark its Going step');
  assert.ok(journeyMarkers(demoJourney).includes(demoJourney.demo.cover.marker), 'cover marker exists in the journey');
  const calendarSeed = await readFile(path.join(packageRoot, 'journeys', 'calendar-going-share', 'seed.php'), 'utf8');
  const calendarGrade = await readFile(path.join(packageRoot, 'journeys', 'calendar-going-share', 'grade.php'), 'utf8');
  const featuredSlug = calendarSeed.match(/array\( '(channel-bluff-[^']+)'/)[1];
  assert.ok(calendarGrade.includes(`'${featuredSlug}'`), 'journey featured slug matches its grade');
  const shippedTheme = JSON.parse(await readFile(path.join(packageRoot, 'demo-themes', 'extra-chill.json'), 'utf8'));
  for (const key of ['accentColor', 'textColor', 'background', 'fontFamily']) assert.ok(key in shippedTheme, `extra-chill theme declares ${key}`);
  const demoRecipe = await buildRecipe({ extrachill_journeys: ['calendar-going-share'], extrachill_demo: true }, packageRoot);
  assert.ok(demoRecipe.workflow.steps.some((step) => step.metadata?.journey === 'calendar-going-share' && step.args?.includes('viewport=540x960') && step.args?.some((arg) => arg.startsWith('annotation-theme-json={'))));
  const calendarRegression = await buildRecipe({ extrachill_journeys: ['calendar-going-share'] }, packageRoot);
  const calendarDemoBrowser = demoRecipe.workflow.steps.find((step) => step.metadata?.kind === 'journey-browser-step' && step.metadata?.journey === 'calendar-going-share');
  const calendarRegressionBrowser = calendarRegression.workflow.steps.find((step) => step.metadata?.kind === 'journey-browser-step' && step.metadata?.journey === 'calendar-going-share');
  assert.ok(calendarDemoBrowser.args.includes('browser-environment-json={"colorScheme":"dark"}'));
  assert.ok(!calendarRegressionBrowser.args.some((arg) => arg.startsWith('browser-environment-json=')));
  assert.equal(calendarRegressionBrowser.args.find((arg) => arg.startsWith('steps-json=')), demoJourney.steps[0].args.find((arg) => arg.startsWith('steps-json=')), 'regression steps remain unpaced');
  await assert.rejects(buildRecipe({ extrachill_journeys: ['gardner-event-rsvp'], extrachill_demo: true }, packageRoot), /has no demo contract/);
  const allowedRecipeStepKeys = new Set(['command', 'args', 'metadata', 'allowFailure', 'timeoutMs', 'env', 'code', 'codeFile']);
  for (const step of demoRecipe.workflow.steps) {
    for (const key of Object.keys(step)) assert.ok(allowedRecipeStepKeys.has(key), `recipe step key '${key}' would fail the WP Codebox recipe schema`);
  }

  // Link Page setup must use the deliberate, nonce-checked UI action, not
  // assume create-artist provisioned a page (artist-platform#257).
  for (const [journeyId, setupIds] of [
    ['musician-link-page-onboarding', ['join-link-live-signup', 'musician-creates-artist-profile']],
    ['returning-artist-link-page', ['setup-first-session']],
  ]) {
    const journey = JSON.parse(await readFile(path.join(packageRoot, 'journeys', journeyId, 'journey.json'), 'utf8'));
    await validateJourneyDocument(journey, journeyId, topology);
    for (const setupId of setupIds) {
      const setup = journey.steps.find((step) => step.metadata.step === setupId);
      const actions = JSON.parse(setup.args.find((arg) => arg.startsWith('steps-json=')).slice('steps-json='.length));
      const artist = actions.findIndex((action) => action.kind === 'click' && action.selector.includes('Create Artist Profile'));
      const prompt = actions.findIndex((action) => action.kind === 'waitFor' && action.selector === '[data-link-page-setup-state="create"]');
      const cta = actions.findIndex((action) => action.kind === 'click' && action.selector === '[data-link-page-setup-state="create"] button[type="submit"]');
      const editor = actions.findIndex((action) => action.kind === 'waitFor' && action.selector === '.ec-editor');
      assert.ok(artist >= 0 && artist < prompt && prompt < cta && cta < editor, `${journeyId}/${setupId}: create artist -> prompt -> CTA -> editor`);
    }
  }
  const musicianGrade = await readFile(path.join(packageRoot, 'journeys/musician-link-page-onboarding/grade.php'), 'utf8');
  assert.ok(!musicianGrade.includes("'create-artist-provisions-link-page'"));
  assert.ok(musicianGrade.includes("'link-page-created-only-after-cta'"));

  // 8d. fixtureMuPlugins: journey-owned files mount into mu-plugins/ under a
  // journey-namespaced target, generically -- the rig never inspects their
  // content -- and a missing file fails validation loudly.
  await assert.rejects(
    validateJourneyDocument(doc({ fixtureMuPlugins: ['missing.php'] }), 'gardner-event-rsvp', topology, async () => true, async () => false),
    /fixtureMuPlugins entry 'missing\.php' does not exist/,
  );
  await assert.rejects(
    validateJourneyDocument(doc({ fixtureMuPlugins: [] }), 'gardner-event-rsvp', topology),
    /fixtureMuPlugins must be a non-empty array/,
  );

  // 9. journeySeedSetting: opaque passthrough, validated shape only.
  assert.equal(journeySeedSetting({}), undefined);
  assert.equal(journeySeedSetting({ extrachill_journey_seed: 'campaign-001' }), 'campaign-001');
  assert.throws(() => journeySeedSetting({ extrachill_journey_seed: '' }), /non-empty string/);
  assert.throws(() => journeySeedSetting({ extrachill_journey_seed: 42 }), /non-empty string/);

  const withSeed = await buildRecipe({ extrachill_journeys: ['gardner-event-rsvp'], extrachill_journey_seed: 'campaign-001' }, packageRoot);
  const seedStepIndex = withSeed.workflow.steps.findIndex((step) => step.metadata?.kind === 'journey-seed-setting');
  assert.ok(seedStepIndex > -1, 'a journey seed is selected passes through as one generic run-php step');
  assert.ok(seedStepIndex < withSeed.workflow.steps.findIndex((step) => step.metadata?.kind === 'journey-seed'), 'the seed-setting step runs before any journey seed step');
  assert.equal(withoutTheme.workflow.steps.some((step) => step.metadata?.kind === 'journey-seed-setting'), false, 'no journey selected means no seed-setting step');

  // 8e. allow-host (and route-host) accept a comma-separated host list, but
  // every listed host must still be a declared site -- a step may name more
  // than one declared site (a real cross-site click-through), never widen
  // beyond the journey's own sites.
  await assert.doesNotReject(
    validateJourneyDocument(
      doc({
        sites: ['events.extrachill.com', 'extrachill.com'],
        steps: [{ command: 'wordpress.browser-actions', args: ['url=http://events.extrachill.com/', 'route-host=events.extrachill.com', 'allow-host=events.extrachill.com,extrachill.com'] }],
      }),
      'gardner-event-rsvp',
      topology,
    ),
  );
  await assert.rejects(
    validateJourneyDocument(
      doc({
        sites: ['events.extrachill.com', 'extrachill.com'],
        steps: [{ command: 'wordpress.browser-actions', args: ['url=http://events.extrachill.com/', 'route-host=events.extrachill.com', 'allow-host=events.extrachill.com,artist.extrachill.com'] }],
      }),
      'gardner-event-rsvp',
      topology,
    ),
    /outside the journey's declared sites/,
  );

  // recipe-run --timeout: WP Codebox's 25m default kills the network rig
  // mid-journey, so the rig always passes its own (default 50m).
  const timeoutOf = (args) => args[args.indexOf('--timeout') + 1];
  const defaultArgs = recipeRunArgs('/tmp/recipe.json', '/tmp/artifacts', {}, { env: {} });
  assert.ok(defaultArgs.includes('--timeout'), 'recipe-run args must include --timeout');
  assert.equal(timeoutOf(defaultArgs), '50m');
  assert.deepEqual(defaultArgs.slice(0, 5), ['recipe-run', '--recipe', '/tmp/recipe.json', '--artifacts', '/tmp/artifacts']);
  assert.ok(!defaultArgs.includes('--dry-run'));
  const dryArgs = recipeRunArgs('/tmp/recipe.json', '/tmp/artifacts', {}, { dryRun: true, env: {} });
  assert.equal(dryArgs.at(-1), '--dry-run');
  assert.equal(timeoutOf(dryArgs), '50m');
  assert.equal(timeoutOf(recipeRunArgs('/r', '/a', { extrachill_recipe_timeout: '40m' }, { env: {} })), '40m');
  assert.equal(timeoutOf(recipeRunArgs('/r', '/a', { extrachill_recipe_timeout: 2400000 }, { env: {} })), '2400000');
  assert.equal(timeoutOf(recipeRunArgs('/r', '/a', { extrachill_recipe_timeout: '40m' }, { env: { HOMEBOY_WP_CODEBOX_RECIPE_TIMEOUT: '45m' } })), '45m', 'env override wins over the setting');
  assert.equal(recipeRunTimeout({}, { HOMEBOY_WP_CODEBOX_RECIPE_TIMEOUT: '' }), '50m', 'empty env override falls back');
  for (const bad of ['50 minutes', '0m', '-5m', '1h', true, '']) {
    assert.throws(() => recipeRunTimeout({ extrachill_recipe_timeout: bad }, {}), /extrachill_recipe_timeout must be a positive duration/);
  }
  assert.throws(() => recipeRunTimeout({}, { HOMEBOY_WP_CODEBOX_RECIPE_TIMEOUT: '1h' }), /HOMEBOY_WP_CODEBOX_RECIPE_TIMEOUT must be a positive duration/);

  console.log('extrachill-network rig contract ok');
} finally {
  delete process.env.HOMEBOY_ARTIFACT_ROOT;
  await rm(temporary, { recursive: true, force: true });
}
