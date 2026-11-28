// Generates journey.json for forum-activity-fuzz (steps-json strings are
// unreadable to hand-escape). Usage: node build-journey.mjs
//
// Exploratory, not a regression contract: every task is its own isolated
// browser step (allowFailure), every page visited posts a generic UX
// observation (horizontal scroll, overflowing elements, broken images, error
// notices, PHP noise, JS errors, tiny or unlabeled controls), and each task
// marks "reached" only once all its interactions completed. grade.php turns
// the markers into cases and prints every observation.
import { writeFileSync } from 'node:fs';

const out = new URL('./journey.json', import.meta.url).pathname;
const SITE = 'community.extrachill.com';
const BASE = `http://${SITE}`;
const MEMBER_ID = 811;
const DESKTOP = '1280x900';
const PHONE = '390x844';

const wait = (ms) => ({ kind: 'waitFor', waitFor: 'duration', duration: `${ms}ms` });
const shot = (name, fullPage = true) => ({ kind: 'screenshot', name, fullPage });
const nav = (path) => ({ kind: 'navigate', url: `${BASE}${path}`, waitFor: 'load' });
const waitFor = (selector, timeout = '30s') => ({ kind: 'waitFor', selector, timeout });
const click = (selector) => ({ kind: 'click', selector });
const fill = (selector, value) => ({ kind: 'fill', selector, value });
const type = (selector, value) => ({ kind: 'type', selector, value });
const press = (key, selector) => ({ kind: 'press', key, ...(selector ? { selector } : {}) });
const check = (expression, expected = true) => ({ kind: 'evaluate', expression, assert: expected });
const peek = (expression) => ({ kind: 'evaluate', expression });

// Post a JSON payload to the fixture sink; resolves to the payload.
const sink = (name, payloadExpr) =>
  `Promise.resolve(${payloadExpr}).then((p) => fetch('/?forum_fuzz_observe=' + encodeURIComponent(${JSON.stringify(name)}), { method: 'POST', credentials: 'same-origin', headers: { 'content-type': 'application/json' }, body: JSON.stringify(p) }).then(() => p))`;

// Generic page-health observation. Never asserts; it records.
const OBSERVE = `(() => {
  const d = document, vw = innerWidth, de = d.documentElement;
  const label = (e) => e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className.trim() ? '.' + e.className.trim().split(/\\s+/).slice(0, 3).join('.') : '');
  const visible = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const hscroll = de.scrollWidth > vw + 1;
  const overflowing = hscroll ? [...d.querySelectorAll('body *')].filter((e) => visible(e) && e.getBoundingClientRect().right > vw + 1 && ![...e.children].some((c) => c.getBoundingClientRect().right > vw + 1)).slice(0, 12).map((e) => ({ el: label(e), right: Math.round(e.getBoundingClientRect().right), text: (e.innerText || '').trim().slice(0, 60) })) : [];
  const brokenImages = [...d.images].filter((i) => i.complete && i.naturalWidth === 0 && i.src).map((i) => i.src).slice(0, 10);
  const notices = [...d.querySelectorAll('.notice, .bbp-template-notice, .wp-die-message, [role=alert]')].filter(visible).map((e) => ({ el: label(e), text: e.innerText.trim().slice(0, 200) }));
  const body = d.body ? d.body.innerText : '';
  const phpNoise = (body.match(/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught|There has been a critical error)[^\\n]{0,160}/g) || []).slice(0, 5);
  const controls = [...d.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea')].filter(visible);
  const smallTargets = controls.filter((e) => { const r = e.getBoundingClientRect(); return r.width < 24 || r.height < 24; }).slice(0, 15).map((e) => ({ el: label(e), w: Math.round(e.getBoundingClientRect().width), h: Math.round(e.getBoundingClientRect().height), text: (e.innerText || e.value || '').trim().slice(0, 30) }));
  const unlabeled = controls.filter((e) => !(e.innerText || '').trim() && !e.getAttribute('aria-label') && !e.getAttribute('title') && !e.getAttribute('aria-labelledby') && !(e.id && d.querySelector('label[for="' + e.id + '"]')) && !e.querySelector('img[alt]:not([alt=""]), svg title, .screen-reader-text') && !e.getAttribute('placeholder') && !e.value).slice(0, 10).map(label);
  const emptyLinks = [...d.querySelectorAll('a[href="#"], a[href=""]')].filter(visible).map((e) => ({ el: label(e), text: (e.innerText || '').trim().slice(0, 40) })).slice(0, 10);
  return { url: location.pathname + location.search + location.hash, title: d.title, viewport: vw + 'x' + innerHeight, docHeight: de.scrollHeight, hscroll, scrollWidth: de.scrollWidth, overflowing, brokenImages, notices, phpNoise, jsErrors: (window.__wpCodeboxBrowserErrors || []).map((e) => String(e.message || e).slice(0, 200)).slice(0, 5), h1: [...d.querySelectorAll('h1')].map((h) => h.innerText.trim()).slice(0, 3), smallTargets, smallTargetCount: controls.filter((e) => { const r = e.getBoundingClientRect(); return r.width < 24 || r.height < 24; }).length, unlabeled, emptyLinks };
})()`;
const observe = (name) => peek(sink(name, OBSERVE));

// Where does the post editor live? Records iframe/editable shape so a failed
// compose step can be told apart from a harness selector miss.
const EDITOR_SHAPE = `(() => ({
  iframes: [...document.querySelectorAll('iframe')].map((f) => ({ name: f.name, cls: f.className, title: f.title, w: f.getBoundingClientRect().width, h: f.getBoundingClientRect().height })),
  editables: [...document.querySelectorAll('[contenteditable=true]')].map((e) => e.tagName + '.' + e.className).slice(0, 5),
  textareas: [...document.querySelectorAll('textarea')].map((t) => t.id || t.name).slice(0, 5),
  beWrappers: [...document.querySelectorAll('[class*=blocks-everywhere]')].map((e) => e.className).slice(0, 5),
  canvasEditables: (() => { try { const f = document.querySelector('iframe[name=editor-canvas]'); return f ? [...f.contentDocument.querySelectorAll('[contenteditable=true], .block-editor-default-block-appender__content')].map((e) => e.tagName + '.' + e.className).slice(0, 5) : null; } catch (e) { return 'cross-origin: ' + e.message; } })(),
}))()`;
const editorShape = (name) => peek(sink(name, EDITOR_SHAPE));

const CANVAS = 'iframe[name="editor-canvas"] >> internal:control=enter-frame';
// A first-time poster: click into the empty editor, type.
const compose = (text) => [
  waitFor('iframe[name="editor-canvas"]', '45s'),
  wait(1500),
  click(`${CANVAS} >> :is(.block-editor-default-block-appender__content, p[contenteditable="true"]) >> nth=0`),
  type(`${CANVAS} >> p[contenteditable="true"] >> nth=-1`, text),
];
// Editing: click the last paragraph, jump to its end, append.
const append = (text) => [
  waitFor('iframe[name="editor-canvas"]', '45s'),
  wait(1500),
  click(`${CANVAS} >> p[contenteditable="true"] >> nth=-1`),
  press('End'),
  type(`${CANVAS} >> p[contenteditable="true"] >> nth=-1`, text),
];

const reached = (id) =>
  check(`fetch('/?forum_fuzz_reached=${id}', { method: 'POST', credentials: 'same-origin' }).then((r) => r.status === 204)`);

const step = (id, { path, member = false, viewport = DESKTOP, steps }) => ({
  command: 'wordpress.browser-actions',
  allowFailure: true,
  metadata: { kind: 'journey-browser-step', step: id },
  args: [
    `url=${BASE}${path}`,
    `route-host=${SITE}`,
    'network-policy=block',
    `allow-host=${SITE}`,
    `viewport=${viewport}`,
    ...(member ? ['auth=wordpress-admin', `auth-user-id=${MEMBER_ID}`] : []),
    'step-timeout=60s',
    'timeout=300s',
    'capture=steps,console,errors,network,screenshot,dom-snapshot',
    `steps-json=${JSON.stringify([...steps, reached(id.replace(/-/g, '_'))])}`,
  ],
});

const REPLY_TEXT = 'Fuzz reply: count me in for Tuesday 🎸 — bring earplugs & "good vibes".';
const NEW_TOPIC_TITLE = 'Fuzz: <script>window.__xss=1</script> & “smart quotes” — émoji 🎶 test';
const NEW_TOPIC_BODY = 'Brand new thread from the fuzz journey. Anyone going to the basement show Friday?';
const MOBILE_REPLY = 'Phone reply: a Focusrite Solo has treated me fine for years.';

const steps = [
  // ---- Logged-out visitor: find and read content -------------------------
  step('anon-home-browse', {
    path: '/',
    steps: [nav('/'), wait(2000), observe('anon-home'), shot('anon-home'),
      click('a[href*="/r/live-shows-scenes"] >> nth=0'), wait(2000), observe('anon-room'), shot('anon-room')],
  }),
  step('anon-read-busy-topic', {
    path: '/t/tuesday-rooms/',
    steps: [nav('/t/tuesday-rooms/'), wait(1500), observe('anon-busy-topic-p1'), shot('anon-busy-topic-p1'),
      { kind: 'scroll', position: 'bottom' }, shot('anon-busy-topic-bottom', false),
      click('.bbp-pagination-links a.next, .bbp-pagination-links a:has-text("2") >> nth=0'), wait(2000),
      observe('anon-busy-topic-p2'), shot('anon-busy-topic-p2')],
  }),
  step('anon-stress-topic', {
    path: '/t/long-title-recs/',
    steps: [nav('/t/long-title-recs/'), wait(1500), observe('anon-stress-topic'), shot('anon-stress-topic'),
      click('.read-more-toggle >> nth=0'), wait(800), observe('anon-stress-expanded'), shot('anon-stress-expanded')],
  }),
  step('anon-try-engage', {
    path: '/t/tuesday-rooms/',
    steps: [nav('/t/tuesday-rooms/'), wait(1500),
      peek(sink('anon-engage-affordances', `({ replyForm: !!document.querySelector('#new-post'), loginPrompt: (document.querySelector('.bbp-no-reply, .wp-block-extrachill-login-register') || {}).innerText?.slice(0, 200) ?? null, upvoteIcons: document.querySelectorAll('.upvote-icon').length })`)),
      click('.bbp-reply-card:not(.is-lead-topic) .upvote-icon >> nth=0'), wait(2000),
      peek(sink('anon-upvote-click-result', `({ url: location.href, modal: [...document.querySelectorAll('[role=dialog], .ec-modal, .login-modal')].filter((e) => e.offsetParent).map((e) => e.innerText.slice(0, 200)), notices: [...document.querySelectorAll('.notice, [role=alert]')].filter((e) => e.offsetParent).map((e) => e.innerText.slice(0, 200)) })`)),
      shot('anon-after-upvote-click', false)],
  }),
  step('anon-search', {
    path: '/?s=tuesday',
    steps: [nav('/?s=tuesday'), wait(2000), observe('anon-search'), shot('anon-search'),
      peek(sink('anon-search-results', `({ resultsText: document.body.innerText.includes('Best small rooms'), links: [...document.querySelectorAll('a[href*="/t/"]')].map((a) => a.getAttribute('href')).slice(0, 8) })`))],
  }),
  step('anon-recent-and-leaderboard', {
    path: '/recent/',
    steps: [nav('/recent/'), wait(2000), observe('anon-recent'), shot('anon-recent'),
      nav('/leaderboard/'), wait(2000), observe('anon-leaderboard'), shot('anon-leaderboard')],
  }),

  // ---- Member: engage, post, edit, profile ------------------------------
  step('member-home', {
    path: '/', member: true,
    steps: [nav('/'), wait(2500), observe('member-home'), shot('member-home'),
      nav('/notifications/'), wait(2500), observe('member-notifications'), shot('member-notifications'),
      peek(sink('member-notifications-content', `({ mentionsScenehost: /scene host|scenehost/i.test(document.body.innerText), text: (document.querySelector('main, #primary, .site-main') || document.body).innerText.slice(0, 600) })`))],
  }),
  step('member-upvote', {
    path: '/t/tuesday-rooms/', member: true,
    steps: [nav('/t/tuesday-rooms/'), wait(2000),
      peek(sink('member-upvote-before', `(window.__ffBefore = document.querySelector('.bbp-reply-card:not(.is-lead-topic) .upvote-count')?.innerText.trim(), { count: window.__ffBefore })`)),
      click('.bbp-reply-card:not(.is-lead-topic) .upvote-icon >> nth=0'), wait(2500),
      peek(sink('member-upvote-after-click', `({ before: window.__ffBefore, after: document.querySelector('.bbp-reply-card:not(.is-lead-topic) .upvote-count')?.innerText.trim(), iconClass: document.querySelector('.bbp-reply-card:not(.is-lead-topic) .upvote-icon')?.className })`)),
      shot('member-upvoted', false),
      nav('/t/tuesday-rooms/'), wait(2000),
      peek(sink('member-upvote-after-reload', `({ count: document.querySelector('.bbp-reply-card:not(.is-lead-topic) .upvote-count')?.innerText.trim() })`))],
  }),
  step('member-reply', {
    path: '/t/tuesday-rooms/', member: true,
    steps: [nav('/t/tuesday-rooms/'), wait(2500), { kind: 'scroll', selector: '#new-post' }, editorShape('member-reply-editor'),
      shot('member-reply-form', false), ...compose(REPLY_TEXT), shot('member-reply-typed', false),
      click('#bbp_reply_submit'), { kind: 'waitFor', waitFor: 'load' }, wait(2500),
      observe('member-reply-after-submit'), shot('member-reply-after-submit'),
      peek(sink('member-reply-landing', `({ url: location.href, hash: location.hash, textVisible: document.body.innerText.includes('count me in for Tuesday'), ampEscaped: document.body.innerText.includes('&amp;') })`)),
      check(`document.body.innerText.includes('count me in for Tuesday')`)],
  }),
  step('member-reply-to-user', {
    path: '/t/fuzz-fan-intro/', member: true,
    steps: [nav('/t/fuzz-fan-intro/'), wait(2500),
      click('.bbp-reply-to-link >> nth=0'), wait(1500), shot('member-reply-to-user', false),
      peek(sink('member-reply-to-user-state', `({ legend: document.querySelector('#new-post legend')?.innerText, scrolledTo: Math.round(document.querySelector('#new-post')?.getBoundingClientRect().top ?? -1), canvasText: (() => { try { return document.querySelector('iframe[name=editor-canvas]').contentDocument.body.innerText.slice(0, 200); } catch (e) { return null; } })() })`))],
  }),
  step('member-edit-reply', {
    path: '/t/tuesday-rooms/page/2/', member: true,
    steps: [nav('/t/tuesday-rooms/page/2/'), wait(2500), observe('member-busy-p2'),
      peek(sink('member-edit-affordances', `({ freshHasEdit: !![...document.querySelectorAll('.bbp-reply-card')].find((c) => c.innerText.includes('Fresh take'))?.querySelector('a[href*="edit"]'), oldHasEdit: !![...document.querySelectorAll('.bbp-reply-card')].find((c) => c.innerText.includes('Old take'))?.querySelector('a[href*="edit"]') })`)),
      click('.bbp-reply-card:has-text("Fresh take") .bbp-reply-user-actions a:has-text("Edit")'), { kind: 'waitFor', waitFor: 'load' }, wait(2500),
      editorShape('member-edit-reply-editor'), observe('member-edit-reply-form'), shot('member-edit-reply-form'),
      ...append(' (edited: bringing a friend)'), shot('member-edit-reply-typed', false),
      click('#bbp_reply_submit'), { kind: 'waitFor', waitFor: 'load' }, wait(2500),
      observe('member-edit-reply-after'), shot('member-edit-reply-after'),
      peek(sink('member-edit-reply-landing', `({ url: location.href, edited: document.body.innerText.includes('bringing a friend') })`)),
      check(`document.body.innerText.includes('bringing a friend')`)],
  }),
  step('member-edit-topic', {
    path: '/t/fuzz-fan-intro/', member: true,
    steps: [nav('/t/fuzz-fan-intro/'), wait(2500),
      click('.is-lead-topic .bbp-reply-user-actions a:has-text("Edit")'), { kind: 'waitFor', waitFor: 'load' }, wait(2500),
      observe('member-edit-topic-form'), shot('member-edit-topic-form'),
      fill('#bbp_topic_title', 'Fuzz Fan here, new to the scene (bassist, say hi)'),
      click('#bbp_topic_submit'), { kind: 'waitFor', waitFor: 'load' }, wait(2500),
      observe('member-edit-topic-after'), shot('member-edit-topic-after'),
      check(`document.body.innerText.includes('bassist, say hi')`)],
  }),
  step('member-new-topic', {
    path: '/', member: true,
    steps: [nav('/'), wait(2500), click('#new-topic-modal-trigger'), waitFor('#new-topic-modal #bbp_topic_title'), wait(1000),
      editorShape('member-new-topic-editor'), observe('member-new-topic-modal'), shot('member-new-topic-modal', false),
      fill('#new-topic-modal #bbp_topic_title', NEW_TOPIC_TITLE),
      { kind: 'select', selector: '#new-topic-modal #bbp_forum_id', value: 'Music Discussion' },
      ...compose(NEW_TOPIC_BODY), shot('member-new-topic-filled', false),
      click('#new-topic-modal #bbp_topic_submit'), { kind: 'waitFor', waitFor: 'load' }, wait(3000),
      observe('member-new-topic-after'), shot('member-new-topic-after'),
      peek(sink('member-new-topic-landing', `({ url: location.href, titleShownLiterally: document.body.innerText.includes('<script>window.__xss=1</script>') , xssRan: window.__xss === 1, h1: document.querySelector('h1')?.innerText, bodyShown: document.body.innerText.includes('basement show Friday') })`)),
      check(`document.body.innerText.includes('basement show Friday')`)],
  }),
  step('member-profile-edit', {
    path: '/u/fuzzfan/edit/', member: true,
    steps: [nav('/u/fuzzfan/edit/'), wait(4000), observe('member-profile-edit'), shot('member-profile-edit'),
      waitFor('#ec-custom-title'), fill('#ec-custom-title', 'Bass & Fuzz 🎸'),
      click('button:has-text("Save Profile Changes")'),
      waitFor(':text("Profile details and links updated successfully.")'), shot('member-profile-saved', false),
      click('[role=tab]:has-text("About"), button:has-text("About") >> nth=0'), waitFor('#ec-bio'),
      fill('#ec-bio', 'Bassist. Moved here for the scene. Into fuzz, shoegaze, and Tuesday shows.\nSecond line of bio.'),
      click('button:has-text("Save Profile Changes")'),
      waitFor(':text("Profile details and links updated successfully.")'), shot('member-profile-bio-saved', false), observe('member-profile-after-save')],
  }),
  step('member-profile-view', {
    path: '/u/fuzzfan/', member: true,
    steps: [nav('/u/fuzzfan/'), wait(3000), observe('member-profile-view'), shot('member-profile-view'),
      peek(sink('member-profile-view-content', `({ title: document.body.innerText.includes('Bass & Fuzz'), bio: document.body.innerText.includes('Moved here for the scene'), bioLineBreak: document.body.innerText.includes('scene, and Tuesday shows.\\nSecond line'), showsOwnTopic: document.body.innerText.includes('new to the scene') })`)),
      nav('/settings/'), wait(4000), observe('member-settings'), shot('member-settings')],
  }),

  // ---- Same member on a phone ---------------------------------------------
  step('mobile-home-and-menu', {
    path: '/', member: true, viewport: PHONE,
    steps: [nav('/'), wait(2500), observe('mobile-home'), shot('mobile-home'),
      click('#new-topic-modal-trigger'), wait(1500), observe('mobile-new-topic-modal'), shot('mobile-new-topic-modal', false)],
  }),
  step('mobile-read-stress', {
    path: '/t/long-title-recs/', member: true, viewport: PHONE,
    steps: [nav('/t/long-title-recs/'), wait(2500), observe('mobile-stress-topic'), shot('mobile-stress-topic')],
  }),
  step('mobile-reply', {
    path: '/t/budget-home-recording/', member: true, viewport: PHONE,
    steps: [nav('/t/budget-home-recording/'), wait(2500), observe('mobile-quiet-topic'), shot('mobile-quiet-topic'),
      { kind: 'scroll', selector: '#new-post' }, editorShape('mobile-reply-editor'), shot('mobile-reply-form', false),
      ...compose(MOBILE_REPLY), shot('mobile-reply-typed', false),
      click('#bbp_reply_submit'), { kind: 'waitFor', waitFor: 'load' }, wait(2500),
      observe('mobile-reply-after'), shot('mobile-reply-after'),
      check(`document.body.innerText.includes('Focusrite Solo')`)],
  }),
  step('mobile-profile', {
    path: '/u/fuzzfan/', member: true, viewport: PHONE,
    steps: [nav('/u/fuzzfan/'), wait(2500), observe('mobile-profile'), shot('mobile-profile'),
      nav('/u/fuzzfan/edit/'), wait(4000), observe('mobile-profile-edit'), shot('mobile-profile-edit')],
  }),
];

const journey = {
  schema: 'extrachill-network/journey/v1',
  id: 'forum-activity-fuzz',
  title: 'Forum activity fuzz: find, read, engage, post, edit, profile (desktop + phone)',
  description: 'Exploratory community journey. A logged-out visitor browses rooms, reads a paginated topic and a stress-shaped topic, tries to engage, and searches; a member upvotes, replies, replies-to-user, edits a reply and a topic, starts a new topic with hostile characters, and edits their profile; the same member repeats key flows on a phone. Every page posts a generic UX observation for review.',
  sites: [SITE],
  runtimeEnv: { WP_AGENT_RUNTIME: '1' },
  fixtureMuPlugins: ['observe-fixture.php'],
  seed: { codeFile: 'seed.php', timeoutMs: 180000 },
  grade: { codeFile: 'grade.php', timeoutMs: 120000 },
  steps,
};

writeFileSync(out, `${JSON.stringify(journey, null, 2)}\n`);
console.log(`wrote ${out} (${steps.length} steps)`);
