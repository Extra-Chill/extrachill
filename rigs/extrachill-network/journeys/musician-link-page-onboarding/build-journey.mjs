// Generates journey.json (steps-json strings are unreadable to hand-escape).
// Usage: node build-journey.mjs  (from this directory)
import { writeFileSync } from 'node:fs';

const out = new URL('./journey.json', import.meta.url).pathname;
const noErrors = { kind: 'evaluate', expression: 'window.__wpCodeboxBrowserErrors?.length ?? 0' };
const wait = (ms) => ({ kind: 'waitFor', waitFor: 'duration', duration: `${ms}ms` });
const shot = (name) => ({ kind: 'screenshot', name });
const nav = (url) => ({ kind: 'navigate', url, waitFor: 'load' });
let obsCounter = 0;
const labels = {};
// Every observation posts itself to the journey fixture's sink (see sandbox-fixture.php):
// browser-actions only keeps evaluate values when a step asserts.
const ev = (expression) => {
  const label = `o${String(++obsCounter).padStart(3, '0')}`;
  labels[label] = expression;
  return {
    kind: 'evaluate',
    expression: `(async()=>{let v;try{v=await (async()=>(${expression}))()}catch(e){v='ERR:'+e.message}try{await fetch('/?musician_journey_observe=1',{method:'POST',headers:{'content-type':'application/json'},credentials:'same-origin',keepalive:true,body:JSON.stringify({label:'${label}',step:window.__mjStep||'',url:location.href,value:v})})}catch(e){}return true})()`,
  };
};
const here = ev('location.href');
const text = (n = 900) => ev(`document.body.innerText.replace(/\\s+/g,' ').slice(0,${n})`);
const anchors = (scope = 'body', filter = '') =>
  ev(`Array.from(document.querySelectorAll('${scope} a')).filter(a=>a.offsetParent!==null)${filter}.map(a=>(a.textContent.trim().replace(/\\s+/g,' ')||a.getAttribute('aria-label')||'[icon]')+' -> '+a.getAttribute('href')).slice(0,60)`);
const overflow = ev('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 4');
const field = (label, control = 'input') => `.ec-lpe-field:has(> label:text-is('${label}')) > ${control}`;

const step = (id, { url, hosts, auth, viewport = '1280x900', timeout = '240s', steps }) => ({
  command: 'wordpress.browser-actions',
  allowFailure: true,
  metadata: { kind: 'journey-browser-step', step: id },
  args: [
    `url=${url}`,
    `route-host=${new URL(url).hostname}`,
    'network-policy=block',
    `allow-host=${hosts.join(',')}`,
    `viewport=${viewport}`,
    ...(auth ? ['auth=wordpress-admin', `auth-user-id=${auth}`] : []),
    'step-timeout=60s',
    `timeout=${timeout}`,
    'capture=steps,console,errors,network,screenshot,dom-snapshot',
    `steps-json=${JSON.stringify(steps)}`,
  ],
});

const ALL = ['extrachill.link', 'extrachill.com', 'artist.extrachill.com', 'community.extrachill.com', 'auth.extrachill.com'];
// create-artist redirects to /manage-link-page/ without provisioning a page.
const createLinkPage = [
  { kind: 'waitFor', selector: '[data-link-page-setup-state="create"]', timeout: '90s' },
  { kind: 'click', selector: '[data-link-page-setup-state="create"] button[type="submit"]' },
  { kind: 'waitFor', selector: '.ec-editor', timeout: '90s' },
];

const steps = [
  // 1. A musician sees a friend's Link Page, taps "Powered by Extra Chill", lands on /power.
  step('discover-power-from-link-page-footer', {
    url: 'http://extrachill.link/',
    hosts: ALL,
    steps: [
      nav('http://extrachill.link/'),
      wait(2500),
      shot('01-link-page-root-anonymous'),
      ev("document.title"),
      ev("(document.querySelector('.extrch-link-page-powered a')||{}).href||'no-powered-footer'"),
      ev("Array.from(document.querySelectorAll('a')).filter(a=>/join/i.test(a.href+a.textContent)).map(a=>a.textContent.trim()+' -> '+a.href)"),
      nav('http://extrachill.com/power/'),
      wait(2000),
      shot('02-power-page-desktop'),
      text(1500),
      ev("Array.from(document.querySelectorAll('.power-network-card')).map(c=>c.querySelector('h3').textContent.trim()+' | '+(c.querySelector('.power-network-card__cta')||{}).textContent+' -> '+c.getAttribute('href'))"),
      ev("/link page/i.test(document.body.innerText)"),
      ev("/(get|claim|create|make).{0,20}(your|a) (free )?link page/i.test(document.body.innerText)"),
      { kind: 'click', selector: ".power-network-card:has-text('Artist Platform')" },
      wait(4000),
      here,
      shot('03-artist-platform-home-from-power'),
      text(1500),
      anchors('main', ".filter(a=>/button|btn/.test(a.className)||/join|sign|start|create|get/i.test(a.textContent))"),
      noErrors,
    ],
  }),
  step('power-page-mobile', {
    url: 'http://extrachill.com/power/',
    hosts: ['extrachill.com'],
    viewport: '390x844',
    steps: [nav('http://extrachill.com/power/'), wait(1500), overflow, shot('04-power-page-mobile'), noErrors],
  }),
  // 2. The /join link: register as a brand-new musician, walk onboarding, create the artist.
  step('join-link-live-signup', {
    url: 'http://extrachill.link/join',
    hosts: ALL,
    timeout: '300s',
    steps: [
      nav('http://extrachill.link/join'),
      wait(2500),
      here,
      shot('05-join-landing'),
      text(800),
      // /join must land on the Register tab with no detour (artist-platform#244).
      { kind: 'expect', selector: '#extrachill_email', state: 'visible' },
      shot('06-join-register-tab'),
      { kind: 'fill', selector: '#extrachill_email', value: 'porch-lights-live@example.test' },
      { kind: 'fill', selector: '#extrachill_password', value: 'porch-lights-live-248' },
      { kind: 'fill', selector: '#extrachill_password_confirm', value: 'porch-lights-live-248' },
      { kind: 'click', selector: "input[name='extrachill_register']" },
      { kind: 'waitFor', selector: '#onboarding-username', timeout: '60s' },
      here,
      shot('07-after-register'),
      text(1000),
      shot('08-onboarding-form'),
      ev("Array.from(document.querySelectorAll('#onboarding-form label')).map(l=>l.innerText.trim())"),
      { kind: 'fill', selector: '#onboarding-username', value: 'porchlightslive' },
      // /join asks "What's your Link Page for?" with "My music" pre-selected
      // (extrachill-users#440); a musician changes nothing.
      { kind: 'expect', selector: "input[name='join_intent'][value='artist']", state: 'checked' },
      ev("Array.from(document.querySelectorAll(\"input[name='join_intent']\")).map(i=>i.value+':'+i.closest('label').innerText.trim())"),
      { kind: 'click', selector: '#onboarding-submit' },
      { kind: 'waitFor', selector: '#ec-artist-name', timeout: '60s' },
      here,
      shot('09-after-onboarding'),
      text(1000),
      { kind: 'fill', selector: '#ec-artist-name', value: 'The Porch Lights Live' },
      { kind: 'click', selector: "button[type='submit']:has-text('Create Artist Profile')" },
      ...createLinkPage,
      here,
      shot('10-live-after-create-link-page'),
      text(1000),
      noErrors,
    ],
  }),
  // A venue owner and a promoter use the same /join door and must not be
  // pushed into creating an artist profile.
  ...[
    ['venue', 'porch-venue-live@example.test', 'porchvenuelive', 'venue-link-page', 'Venue Link Page'],
    ['promoter', 'porch-promoter-live@example.test', 'porchpromoterlive', 'promoter-link-page', 'Promoter or Collective Link Page'],
  ].map(([intent, email, username, subject, subjectLabel]) =>
    step(`join-link-${intent}-signup`, {
      url: 'http://extrachill.link/join',
      hosts: ALL,
      timeout: '300s',
      steps: [
        nav('http://extrachill.link/join'),
        { kind: 'waitFor', selector: '#extrachill_email', timeout: '60s' },
        { kind: 'fill', selector: '#extrachill_email', value: email },
        { kind: 'fill', selector: '#extrachill_password', value: `${username}-pass-248` },
        { kind: 'fill', selector: '#extrachill_password_confirm', value: `${username}-pass-248` },
        { kind: 'click', selector: "input[name='extrachill_register']" },
        { kind: 'waitFor', selector: '#onboarding-username', timeout: '60s' },
        { kind: 'fill', selector: '#onboarding-username', value: username },
        { kind: 'click', selector: `input[name='join_intent'][value='${intent}']` },
        shot(`35-${intent}-intent-chosen`),
        { kind: 'click', selector: '#onboarding-submit' },
        { kind: 'waitFor', selector: '#ec-contact-subject', timeout: '60s' },
        here,
        { kind: 'expect', selector: '#ec-contact-subject', state: 'visible' },
        ev(`document.querySelector('#ec-contact-subject').value`),
        ev("(document.querySelector('.wp-block-extrachill-contact-form .notice, .notice-info')||{innerText:''}).innerText.trim()"),
        shot(`36-${intent}-lands-on-request-form`),
        noErrors,
      ],
    }),
  ),
  step('join-link-mobile', {
    url: 'http://extrachill.link/join',
    hosts: ALL,
    viewport: '390x844',
    steps: [nav('http://extrachill.link/join'), wait(2500), here, overflow, shot('11-join-landing-mobile'), noErrors],
  }),
  // 3. Fixture musician (registered + onboarded as an artist, nothing else yet).
  step('musician-creates-artist-profile', {
    url: 'http://artist.extrachill.com/',
    hosts: ALL,
    auth: 601,
    steps: [
      nav('http://artist.extrachill.com/'),
      wait(2000),
      shot('12-artist-home-before-artist'),
      text(1200),
      anchors('header'),
      nav('http://artist.extrachill.com/create-artist/'),
      { kind: 'waitFor', selector: '#ec-artist-name', timeout: '30s' },
      shot('13-create-artist-form'),
      { kind: 'fill', selector: '#ec-artist-name', value: 'The Porch Lights' },
      { kind: 'click', selector: "button[type='submit']:has-text('Create Artist Profile')" },
      ...createLinkPage,
      here,
      shot('14-after-create-link-page'),
      text(1200),
      anchors('main'),
      nav('http://artist.extrachill.com/manage-link-page/'),
      wait(4000),
      shot('14b-manage-link-page-right-after-create'),
      text(700),
      anchors('main'),
      nav('http://artist.extrachill.com/manage-artist/'),
      wait(5000),
      shot('14c-manage-artist-right-after-create'),
      ev("Array.from(document.querySelectorAll('button,a')).filter(a=>/link.?page|complete|setup/i.test(a.textContent)).map(a=>a.tagName+': '+a.textContent.trim())"),
      noErrors,
    ],
  }),
  // 4. How does the musician get to the editor from each place they might be?
  step('musician-navigation-map', {
    url: 'http://artist.extrachill.com/',
    hosts: ALL,
    auth: 601,
    timeout: '300s',
    steps: [
      nav('http://artist.extrachill.com/'),
      wait(2000),
      shot('15-artist-home-with-artist'),
      anchors('header'),
      anchors('body', ".filter(a=>/link.?page|edit|manage/i.test(a.textContent+a.href))"),
      nav('http://artist.extrachill.com/manage-artist/'),
      wait(5000),
      shot('16-manage-artist'),
      anchors('body', ".filter(a=>/link.?page|edit|extrachill\\.link/i.test(a.textContent+a.href))"),
      nav('http://artist.extrachill.com/manage-link-page/'),
      wait(7000),
      here,
      shot('17-legacy-manage-link-page'),
      text(600),
      ev("!!document.querySelector('#ec-link-page-editor-root *, .ec-editor')"),
      nav('http://artist.extrachill.com/analytics/'),
      wait(4000),
      shot('18-analytics'),
      anchors('main', ".filter(a=>/link.?page/i.test(a.textContent+a.href))"),
      nav('http://community.extrachill.com/'),
      wait(2500),
      shot('19-community-home-logged-in'),
      anchors('header'),
      nav('http://extrachill.com/'),
      wait(2500),
      shot('20-main-home-logged-in'),
      anchors('header'),
      anchors('body', ".filter(a=>/link.?page|artist\\.extrachill|extrachill\\.link/i.test(a.textContent+a.href))"),
      noErrors,
    ],
  }),
  // 5. Edit the Link Page on extrachill.link/edit: a link, colors, newsletter.
  step('musician-edits-link-page', {
    url: 'http://extrachill.link/edit',
    hosts: ALL,
    auth: 601,
    viewport: '1440x1000',
    timeout: '360s',
    steps: [
      nav('http://artist.extrachill.com/'),
      wait(1500),
      ev("Array.from(document.querySelectorAll('a')).filter(a=>/link.?page/i.test(a.textContent)).map(a=>a.textContent.trim()+' -> '+a.getAttribute('href'))"),
      nav('http://extrachill.link/edit'),
      wait(12000),
      here,
      ev("(document.getElementById('ec-link-page-edit-status')||{innerText:'no-status'}).innerText"),
      ev("(async()=>{const t=JSON.parse(localStorage.getItem('ecLinkEditToken')||'null');const s=window.ecLinkPageEditShell||{};if(!t||!s.configurationUrl)return {token:!!t,cfg:s.configurationUrl||null};const r=await fetch(s.configurationUrl+'?link_page_id=0',{headers:{Authorization:'Bearer '+t.token},credentials:'omit'});return {status:r.status,body:(await r.text()).slice(0,400),cfg:s.configurationUrl}})()"),
      shot('21a-editor-after-handoff'),
      { kind: 'waitFor', selector: '.ec-editor', timeout: '60s' },
      wait(1500),
      shot('21-editor-loaded'),
      ev("Array.from(document.querySelectorAll('.ec-lpe-tabs [role=tab]')).map(t=>t.textContent.trim())"),
      anchors('body'),
      ev("(document.querySelector('.ec-editor__header a')||{}).href||'no-public-url'"),
      { kind: 'click', selector: ".ec-lpe-tabs [role=tab]:has-text('Links')" },
      { kind: 'click', selector: "button:has-text('Add Section')" },
      { kind: 'click', selector: "button:has-text('Add Link') >> nth=-1" },
      { kind: 'fill', selector: "input[aria-label='Link title'] >> nth=-1", value: 'Listen on Bandcamp' },
      { kind: 'fill', selector: "input[aria-label='Link URL'] >> nth=-1", value: 'https://porchlights.bandcamp.com' },
      shot('22-links-tab-added-link'),
      { kind: 'click', selector: ".ec-lpe-tabs [role=tab]:has-text('Customize')" },
      wait(500),
      shot('23-customize-tab-before'),
      ev("Array.from(document.querySelectorAll('.ec-lpe-field > label')).map(l=>l.textContent.trim())"),
      { kind: 'fill', selector: field('Background Color'), value: '#1d3557' },
      { kind: 'fill', selector: field('Button Color'), value: '#e63946' },
      { kind: 'fill', selector: field('Text Color'), value: '#f1faee' },
      wait(800),
      shot('24-customize-tab-colors-changed'),
      ev("(()=>{const p=document.querySelector('.ec-editor__preview-region');if(!p)return 'no-preview';const s=p.querySelector('[style*=\"--link-page\"]')||p.firstElementChild;return s?getComputedStyle(s).getPropertyValue('--link-page-background-color')+' / '+getComputedStyle(s).backgroundColor:'no-preview-root'})()"),
      { kind: 'click', selector: ".ec-lpe-tabs [role=tab]:has-text('Advanced')" },
      wait(500),
      shot('25-advanced-tab'),
      ev("Array.from(document.querySelectorAll('.ec-lpe-field > label')).map(l=>l.textContent.trim())"),
      { kind: 'click', selector: ".ec-lpe-tabs [role=tab]:has-text('Newsletter')" },
      wait(500),
      { kind: 'select', selector: field('How fans sign up', 'select'), value: 'inline_form' },
      { kind: 'fill', selector: field('Message to fans', 'textarea'), value: 'Get show dates and new songs from The Porch Lights first. No spam, ever.' },
      wait(800),
      shot('26-advanced-newsletter-set'),
      ev("!!document.querySelector('.ec-editor__preview-region .extrch-link-page-subscribe-inline-form-container')"),
      { kind: 'click', selector: ".ec-editor__header button[type='submit']" },
      wait(6000),
      ev("(document.querySelector('.ec-editor__actions [role=status]')||{textContent:'no-status'}).textContent"),
      ev("(document.querySelector(\".ec-editor__header button[type='submit']\")||{}).textContent"),
      shot('27-after-save'),
      ev("(document.querySelector('.ec-editor__header a')||{}).href||'no-public-url'"),
      nav('http://extrachill.link/the-porch-lights'),
      wait(3000),
      here,
      shot('28-public-page-after-save'),
      ev("getComputedStyle(document.body).backgroundColor"),
      ev("(document.querySelector('.extrch-subscribe-inline-form-container')||{innerText:'no-inline-form'}).innerText.replace(/\\s+/g,' ')"),
      ev("Array.from(document.querySelectorAll('a')).map(a=>a.textContent.trim()+' -> '+a.getAttribute('href')).slice(0,40)"),
      wait(4000),
      ev("(document.querySelector('.extrch-link-page-edit-btn')||{}).href||'no-owner-edit-button'"),
      shot('29-public-page-owner-view'),
      nav('http://extrachill.link/edit'),
      { kind: 'waitFor', selector: '.ec-editor', timeout: '90s' },
      { kind: 'click', selector: ".ec-lpe-tabs [role=tab]:has-text('Customize')" },
      ev(`(document.querySelector("${field('Background Color')}")||{value:'missing'}).value`),
      shot('30-editor-after-reload'),
      noErrors,
    ],
  }),
  // 6. A fan subscribes through the inline form the musician just turned on.
  step('fan-subscribes-inline', {
    url: 'http://extrachill.link/the-porch-lights',
    hosts: ['extrachill.link', 'artist.extrachill.com', 'extrachill.com'],
    viewport: '390x844',
    steps: [
      nav('http://extrachill.link/the-porch-lights'),
      wait(2500),
      here,
      ev("document.documentElement ? document.documentElement.outerHTML.length : -1"),
      ev("(async()=>{const r=await fetch('/the-porch-lights/',{redirect:'manual'});const t=await r.text();return {status:r.status,type:r.type,len:t.length,head:t.slice(0,300)}})()"),
      shot('31-fan-view-mobile'),
      overflow,
      ev("document.body.dataset.extrchSubscribeApiUrl||'no-subscribe-url'"),
      { kind: 'fill', selector: ".extrch-subscribe-inline-form-container input[name='subscriber_email']", value: 'fan-of-porch-lights@example.test' },
      { kind: 'click', selector: ".extrch-subscribe-inline-form-container button[type='submit']" },
      { kind: 'waitFor', selector: ".extrch-subscribe-inline-form-container .extrch-form-message:not(:empty)", timeout: '45s' },
      ev("(document.querySelector('.extrch-subscribe-inline-form-container .extrch-form-message')||{textContent:'no-message'}).textContent.trim()"),
      shot('32-fan-after-subscribe'),
      noErrors,
    ],
  }),
  step('musician-editor-mobile', {
    url: 'http://extrachill.link/edit',
    hosts: ALL,
    auth: 601,
    viewport: '390x844',
    steps: [
      nav('http://artist.extrachill.com/'),
      nav('http://extrachill.link/edit'),
      { kind: 'waitFor', selector: '.ec-editor', timeout: '90s' },
      wait(1500),
      overflow,
      shot('33-editor-mobile'),
      ev("(()=>{const p=document.querySelector('.ec-editor__preview-region');return p?getComputedStyle(p).display+' '+p.getBoundingClientRect().top:'no-preview'})()"),
      noErrors,
    ],
  }),
  // 7. Logged-out musician opens the editor directly (bookmark / old link).
  step('logged-out-editor-entry', {
    url: 'http://extrachill.link/edit',
    hosts: ALL,
    steps: [
      nav('http://extrachill.link/edit'),
      wait(6000),
      here,
      text(600),
      shot('34-editor-logged-out'),
      noErrors,
    ],
  }),
];

const journey = {
  schema: 'extrachill-network/journey/v1',
  id: 'musician-link-page-onboarding',
  title: 'Non-technical musician: discover, join, set up and customize a Link Page',
  description:
    "Walks a non-technical musician through both real acquisition doors (a Link Page's 'Powered by Extra Chill' footer to extrachill.com/power/, and extrachill.link/join), live registration and onboarding, artist-profile creation, every place in the network that should lead to the Link Page editor, and the extrachill.link/edit editor itself: adding a link, changing colors, and turning on the inline newsletter form with a custom description. A fan then subscribes through that form. Server-side grade confirms what persisted.",
  sites: ['extrachill.com', 'extrachill.link', 'artist.extrachill.com', 'community.extrachill.com', 'auth.extrachill.com'],
  runtimeEnv: { WP_AGENT_RUNTIME: '1' },
  fixtureMuPlugins: ['sandbox-fixture.php'],
  seed: { codeFile: 'seed.php', timeoutMs: 120000 },
  grade: { codeFile: 'grade.php', timeoutMs: 120000 },
  steps,
};
writeFileSync(out, `${JSON.stringify(journey, null, 2)}\n`);
writeFileSync(out.replace('journey.json','evidence/observation-labels.json'), JSON.stringify(labels, null, 2)+'\n');
console.log('wrote', out, steps.length, 'steps');
