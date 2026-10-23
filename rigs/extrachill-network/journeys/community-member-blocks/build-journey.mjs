// Generates journey.json (steps-json strings are unreadable to hand-escape).
// Usage: node build-journey.mjs  (from this directory)
import { writeFileSync } from 'node:fs';

const out = new URL('./journey.json', import.meta.url).pathname;
const SITE = 'community.extrachill.com';
const BASE = `http://${SITE}`;
const MEMBER_ID = 801;

const wait = (ms) => ({ kind: 'waitFor', waitFor: 'duration', duration: `${ms}ms` });
const shot = (name) => ({ kind: 'screenshot', name });
const nav = (url) => ({ kind: 'navigate', url, waitFor: 'load' });
const waitFor = (selector, timeout = '30s') => ({ kind: 'waitFor', selector, timeout });
const check = (expression, expected = true) => ({ kind: 'evaluate', expression, assert: expected });
const noErrors = check('window.__wpCodeboxBrowserErrors?.length ?? 0', 0);
const noInvalidInput = check("!document.body.innerText.includes('has invalid input')");
// Last action of a step: record success server-side for grade.php. POST so
// the anonymous page cache can never answer it.
const reached = (id) =>
  check(`fetch('/?community_member_blocks_reached=${id}', { method: 'POST', credentials: 'same-origin' }).then((r) => r.status === 204)`);

const step = (id, { url, member = false, steps }) => ({
  command: 'wordpress.browser-actions',
  allowFailure: true,
  metadata: { kind: 'journey-browser-step', step: id },
  args: [
    `url=${url}`,
    `route-host=${SITE}`,
    'network-policy=block',
    `allow-host=${SITE}`,
    'viewport=1280x900',
    ...(member ? ['auth=wordpress-admin', `auth-user-id=${MEMBER_ID}`] : []),
    'step-timeout=60s',
    'timeout=240s',
    'capture=steps,console,errors,network,screenshot,dom-snapshot',
    `steps-json=${JSON.stringify(steps)}`,
  ],
});

const steps = [
  // The original MagDuffie report: Settings -> Notifications failed with
  // "has invalid input" because the settings ability got no input object.
  step('settings-notifications-save', {
    url: `${BASE}/settings/`,
    member: true,
    steps: [
      nav(`${BASE}/settings/#tab-notifications`),
      wait(5000),
      shot('settings-after-load'),
      waitFor('#ec-notif-emails'),
      noInvalidInput,
      check("document.querySelector('#ec-notif-emails').checked"),
      shot('settings-notifications-loaded'),
      { kind: 'click', selector: '#ec-notif-emails' },
      { kind: 'click', selector: "button:has-text('Save Preferences')" },
      waitFor(".wp-block-extrachill-user-settings :text('Notification preferences updated.')"),
      shot('settings-notifications-saved'),
      noInvalidInput,
      noErrors,
      reached('settings_notifications_save'),
    ],
  }),
  step('edit-profile-save', {
    url: `${BASE}/u/scenemember/edit/`,
    member: true,
    steps: [
      nav(`${BASE}/u/scenemember/edit/`),
      wait(5000),
      shot('edit-profile-after-load'),
      waitFor('#ec-custom-title'),
      noInvalidInput,
      { kind: 'fill', selector: '#ec-custom-title', value: 'Scene Regular' },
      { kind: 'click', selector: "button:has-text('Save Profile Changes')" },
      waitFor(":text('Profile details and links updated successfully.')"),
      shot('edit-profile-saved'),
      noErrors,
      reached('edit_profile_save'),
    ],
  }),
  // Logged out: ability descriptors need the read cap, so page 1 must be in
  // the server render and page 2 must use the public run endpoint.
  step('leaderboard-logged-out', {
    url: `${BASE}/leaderboard/`,
    steps: [
      nav(`${BASE}/leaderboard/`),
      waitFor('.leaderboard-table'),
      check("document.querySelector('.leaderboard-table').innerText.includes('Scene Member (Fixture)')"),
      shot('leaderboard-page-1'),
      { kind: 'click', selector: "button:has-text('Next')" },
      waitFor(".leaderboard-table:has-text('Neighbour Two (Fixture)')"),
      check("!document.querySelector('.leaderboard-table').innerText.includes('Scene Member (Fixture)')"),
      check("!document.querySelector('.notice-error')"),
      shot('leaderboard-page-2'),
      noErrors,
      reached('leaderboard_logged_out'),
    ],
  }),
  step('heatmap-logged-out-year', {
    url: `${BASE}/u/scenemember/`,
    steps: [
      nav(`${BASE}/u/scenemember/`),
      wait(3000),
      shot('profile-after-load'),
      waitFor('.ec-heatmap-years'),
      shot('heatmap-trailing-year'),
      { kind: 'click', selector: ".ec-heatmap-year:has-text('2025')" },
      waitFor(".ec-heatmap-year.is-active:has-text('2025')"),
      wait(4000),
      check("!document.querySelector('.ec-heatmap-error')"),
      check("!!document.querySelector('.ec-heatmap-total')"),
      shot('heatmap-2025'),
      noErrors,
      reached('heatmap_logged_out_year'),
    ],
  }),
];

const journey = {
  schema: 'extrachill-network/journey/v1',
  id: 'community-member-blocks',
  title: 'Community member blocks: settings, edit profile, leaderboard and profile heatmap',
  description:
    'A member saves notification preferences and a profile title through the community Settings and Edit Profile blocks, and a logged-out visitor pages the leaderboard and switches heatmap years on a member profile. Covers extrachill-community#325/#326 (blocks moved onto core @wordpress/abilities; public blocks no longer need the logged-in ability catalog) and the Settings "has invalid input" report fixed by extrachill-users#473.',
  sites: [SITE],
  runtimeEnv: { WP_AGENT_RUNTIME: '1' },
  fixtureMuPlugins: ['reached-fixture.php'],
  seed: { codeFile: 'seed.php', timeoutMs: 120000 },
  grade: { codeFile: 'grade.php', timeoutMs: 120000 },
  steps,
};

writeFileSync(out, `${JSON.stringify(journey, null, 2)}\n`);
console.log(`wrote ${out}`);
