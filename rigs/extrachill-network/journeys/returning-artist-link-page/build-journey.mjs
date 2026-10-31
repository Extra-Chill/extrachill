// Generates journey.json (steps-json strings are unreadable to hand-escape).
// Usage: node build-journey.mjs  (from this directory)
import { writeFileSync } from 'node:fs';

const out = new URL('./journey.json', import.meta.url).pathname;
const shot = (name) => ({ kind: 'screenshot', name });
const nav = (url) => ({ kind: 'navigate', url, waitFor: 'load' });
const check = (expression, expected = true) => ({ kind: 'evaluate', expression, assert: expected });
const noErrors = check('window.__wpCodeboxBrowserErrors?.length ?? 0', 0);
// Last action of a path: record success server-side for grade.php.
const reached = (id) => check(`fetch('/?returning_artist_reached=${id}', { credentials: 'same-origin' }).then((r) => r.status === 204)`);
const onEditor = [
  { kind: 'waitFor', selector: '.ec-editor', timeout: '90s' },
  check("location.hostname === 'extrachill.link' && location.pathname.replace(/\\/$/, '') === '/edit'"),
  { kind: 'click', selector: ".ec-lpe-tabs [role=tab]:has-text('Links')" },
  check("Array.from(document.querySelectorAll(\"input[aria-label='Link title']\")).some((i) => i.value.startsWith('Tour Dates'))"),
];
const ALL = ['extrachill.com', 'extrachill.link', 'artist.extrachill.com', 'community.extrachill.com'];
const PUBLIC = 'http://extrachill.link/night-shift-radio';

const step = (id, { url, viewport = '1280x900', steps }) => ({
  command: 'wordpress.browser-actions',
  allowFailure: true,
  metadata: { kind: 'journey-browser-step', step: id },
  args: [
    `url=${url}`,
    `route-host=${new URL(url).hostname}`,
    'network-policy=block',
    `allow-host=${ALL.join(',')}`,
    `viewport=${viewport}`,
    'auth=wordpress-admin',
    'auth-user-id=701',
    'step-timeout=90s',
    'timeout=300s',
    'capture=steps,console,errors,network,screenshot,dom-snapshot',
    `steps-json=${JSON.stringify(steps)}`,
  ],
});

// "My Link Page" in the account menu, from any network site.
const viaAccountMenu = (site, name, viewport) =>
  step(`returns-from-${name}-account-menu`, {
    url: `http://${site}/`,
    viewport,
    steps: [
      nav(`http://${site}/`),
      { kind: 'click', selector: '.user-avatar-toggle' },
      { kind: 'click', selector: ".user-dropdown-menu a:has-text('My Link Page')" },
      ...onEditor,
      shot(`${name}-account-menu-to-editor`),
      noErrors,
      reached(`returns_from_${name.replace(/-/g, '_')}_account_menu`),
    ],
  });

const steps = [
  // Setup: the musician's first session (not the subject of this journey).
  step('setup-first-session', {
    url: 'http://artist.extrachill.com/create-artist/',
    steps: [
      nav('http://artist.extrachill.com/create-artist/'),
      { kind: 'waitFor', selector: '#ec-artist-name', timeout: '60s' },
      { kind: 'fill', selector: '#ec-artist-name', value: 'Night Shift Radio' },
      { kind: 'click', selector: "button[type='submit']:has-text('Create Artist Profile')" },
      { kind: 'waitFor', selector: '[data-link-page-setup-state="create"]', timeout: '90s' },
      { kind: 'click', selector: '[data-link-page-setup-state="create"] button[type="submit"]' },
      { kind: 'waitFor', selector: '.ec-editor', timeout: '90s' },
      { kind: 'click', selector: ".ec-lpe-tabs [role=tab]:has-text('Links')" },
      { kind: 'click', selector: "button:has-text('Add Section')" },
      { kind: 'click', selector: "button:has-text('Add Link') >> nth=-1" },
      { kind: 'fill', selector: "input[aria-label='Link title'] >> nth=-1", value: 'Tour Dates' },
      { kind: 'fill', selector: "input[aria-label='Link URL'] >> nth=-1", value: 'https://nightshiftradio.example/tour' },
      { kind: 'click', selector: ".ec-editor__header button[type='submit']" },
      { kind: 'waitFor', selector: ".ec-editor__header button[type='submit']:has-text('Saved')", timeout: '60s' },
      shot('setup-saved'),
      reached('setup_first_session'),
    ],
  }),
  // Coming back, each in a fresh browser: no editor token in localStorage.
  viaAccountMenu('extrachill.com', 'blog', '1280x900'),
  viaAccountMenu('community.extrachill.com', 'community', '1280x900'),
  viaAccountMenu('extrachill.com', 'blog-mobile', '390x844'),
  step('returns-from-artist-dashboard', {
    url: 'http://artist.extrachill.com/',
    steps: [
      nav('http://artist.extrachill.com/'),
      // Every "Manage Link Page" link on the dashboard must reach the editor.
      check("Array.from(document.querySelectorAll('a')).filter((a) => /Manage Link Page/.test(a.textContent)).length > 0"),
      { kind: 'click', selector: "a:has-text('Manage Link Page') >> nth=0" },
      ...onEditor,
      shot('dashboard-to-editor'),
      noErrors,
      reached('returns_from_artist_dashboard'),
    ],
  }),
  step('returns-from-own-public-page', {
    url: PUBLIC,
    steps: [
      nav(PUBLIC),
      // The owner's pencil appears after a token handoff round-trip.
      { kind: 'waitFor', selector: '.extrch-link-page-edit-btn', timeout: '60s' },
      shot('public-page-owner-pencil'),
      { kind: 'click', selector: '.extrch-link-page-edit-btn' },
      ...onEditor,
      noErrors,
      reached('returns_from_own_public_page'),
    ],
  }),
  step('edits-and-sees-change-live', {
    url: 'http://extrachill.link/edit',
    steps: [
      nav('http://artist.extrachill.com/manage-link-page/'),
      ...onEditor,
      { kind: 'fill', selector: "input[aria-label='Link title'] >> nth=0", value: 'Tour Dates 2027' },
      { kind: 'click', selector: ".ec-editor__header button[type='submit']" },
      { kind: 'waitFor', selector: ".ec-editor__header button[type='submit']:has-text('Saved')", timeout: '60s' },
      nav(PUBLIC),
      check("document.body.innerText.includes('Tour Dates 2027')"),
      shot('public-page-shows-edit'),
      noErrors,
      reached('edits_and_sees_change_live'),
    ],
  }),
  step('analytics-knows-the-page', {
    url: 'http://artist.extrachill.com/analytics/',
    steps: [
      { kind: 'navigate', url: 'http://artist.extrachill.com/analytics/', waitFor: 'networkidle' },
      check("!document.body.innerText.includes('Create a link page')"),
      shot('analytics'),
      noErrors,
      reached('analytics_knows_the_page'),
    ],
  }),
];

const journey = {
  schema: 'extrachill-network/journey/v1',
  id: 'returning-artist-link-page',
  title: 'Returning artist: find and edit an existing Link Page from anywhere on the network',
  description:
    'An artist who already has a Link Page comes back in a fresh browser from the blog, the community, a phone, the artist dashboard, and their own public page, and must reach the extrachill.link editor with their existing links every time; then edits a link and sees it on the public page, and Analytics recognises the page. The Link Pages migration moved every one of these paths.',
  sites: ALL,
  runtimeEnv: { WP_AGENT_RUNTIME: '1' },
  fixtureMuPlugins: ['reached-fixture.php'],
  seed: { codeFile: 'seed.php', timeoutMs: 120000 },
  grade: { codeFile: 'grade.php', timeoutMs: 120000 },
  steps,
};
writeFileSync(out, `${JSON.stringify(journey, null, 2)}\n`);
console.log('wrote', out, steps.length, 'steps');
