"""Generate the private My Shows ratings browser journey (run from any cwd)."""
import json
from pathlib import Path

HOST = 'http://events.extrachill.com'
PAGE = HOST + '/my-shows/?tab=past'
C = '.ec-concert-stats__show-card'
R = '.ec-concert-stats__show-rating'
RECENT = C + ':has(a[href*="goose-charleston-pour-house-fall"])'
STATE = """(()=>{const root=document.querySelector('.ec-concert-stats');return {
 loggedIn:document.body.classList.contains('logged-in'),owner:root?.dataset.isOwn,userId:root?.dataset.userId,
 note:document.querySelector('.ec-concert-stats__ratings-note')?.textContent.trim(),
 cards:[...document.querySelectorAll('.ec-concert-stats__show-card')].map(c=>({
 href:c.querySelector('.ec-concert-stats__show-link')?.href,
 toggle:c.querySelector('.ec-concert-stats__show-rating-toggle')?.textContent.trim()||null,
 stars:c.querySelectorAll('.ec-concert-stats__show-rating-star').length,
 on:c.querySelectorAll('.ec-concert-stats__show-rating-star.is-on').length,
 selected:c.querySelector('.ec-concert-stats__show-rating-star[aria-pressed="true"]')?.getAttribute('aria-label')||null,
 error:c.querySelector('.ec-concert-stats__show-rating-error')?.textContent||null})),
 ratingNodes:document.querySelectorAll('[class*="show-rating"], [data-rating], [class*="rating-badge"], [class*="rating-summary"]').length,
 title:document.title,hasGoose:/Goose/.test(document.body.innerText),url:location.href}})()"""


def observe(label):
    return {'kind': 'evaluate', 'expression': "(async()=>{const value=" + STATE + ";const r=await fetch('/?ec_show_ratings_observe=1',{method:'POST',headers:{'content-type':'application/json'},credentials:'same-origin',body:JSON.stringify({label:" + json.dumps(label) + ",value})});if(!r.ok)throw Error('Observation failed');return true})()"}


def navigate(url):
    return {'kind': 'navigate', 'url': url, 'waitFor': 'networkidle'}


def checkpoint(label):
    return [observe(label), {'kind': 'screenshot', 'name': label}]


def settled():
    # Wait for the real rating fetch and subsequent React update before navigating.
    return {'kind': 'evaluate', 'expression': "(async()=>{for(let i=0;i<200;i++){if(!window.__ratingPending){await new Promise(r=>setTimeout(r,200));if(!window.__ratingPending)return true}await new Promise(r=>setTimeout(r,100))}throw Error('Rating save did not settle')})()"}


def pick(value, label):
    return [
        {'kind': 'click', 'selector': RECENT + f' button[aria-label="{value} stars"]'},
        # Remove touch/focus hover so filled stars reflect saved state, not preview.
        {'kind': 'evaluate', 'expression': "document.activeElement?.blur();document.querySelector('.ec-concert-stats__ratings-note')?.dispatchEvent(new MouseEvent('mouseover',{bubbles:true}));true"},
        settled(), *checkpoint(label),
    ]


owner = [navigate(PAGE), {'kind': 'waitFor', 'selector': RECENT + ' ' + R},
         {'kind': 'evaluate', 'expression': "(()=>{const f=window.fetch;window.__ratingPending=0;window.fetch=(...a)=>{window.__ratingPending++;return f(...a).finally(()=>window.__ratingPending--)};return true})()"},
         *checkpoint('owner-initial'),
         {'kind': 'click', 'selector': RECENT + ' .ec-concert-stats__show-rating-toggle'},
         *checkpoint('owner-picker'), *pick(4, 'owner-four'), *pick(3, 'owner-three'),
         navigate(PAGE), {'kind': 'waitFor', 'selector': RECENT + ' button[aria-pressed="true"]'},
         *checkpoint('owner-reloaded-three'),
         {'kind': 'evaluate', 'expression': "(()=>{const f=window.fetch;window.__ratingPending=0;window.fetch=(...a)=>{window.__ratingPending++;return f(...a).finally(()=>window.__ratingPending--)};return true})()"},
         *pick(3, 'owner-cleared'), navigate(PAGE),
         {'kind': 'waitFor', 'selector': RECENT + ' .ec-concert-stats__show-rating-toggle'},
         *checkpoint('owner-reloaded-clear')]


def privacy(persona):
    steps = []
    for surface, url in [('history', PAGE + '&user_id=711'),
                         ('event', HOST + '/events/goose-charleston-pour-house-summer/'),
                         ('artist', HOST + '/artist/goose/?past=1')]:
        steps.append(navigate(url))
        if surface == 'history':
            steps.append({'kind': 'waitFor', 'selector': C})
        steps.extend(checkpoint(persona + '-' + surface))
    return steps


def step(name, steps, user=None):
    args = [f'url={PAGE}', 'route-host=events.extrachill.com', 'network-policy=block',
            'allow-host=events.extrachill.com', 'viewport=390x844', 'step-timeout=60s',
            'timeout=300s', 'capture=steps,console,errors,network,screenshot,dom-snapshot']
    if user:
        args.extend(['auth=wordpress-admin', f'auth-user-id={user}'])
    args.append('steps-json=' + json.dumps(steps))
    return {'command': 'wordpress.browser-actions', 'allowFailure': True,
            'metadata': {'kind': 'journey-browser-step', 'step': name}, 'args': args}


journey = {
    'schema': 'extrachill-network/journey/v1', 'id': 'show-ratings-fan',
    'title': 'A fan privately rates past shows on My Shows',
    'description': 'On a 390px phone, an owner opens Rate, saves 4 then 3, reloads, clears by tapping 3 again, and reloads. A seeded rating renders immediately. Anonymous and second-user walks verify ratings stay private on public history, event and artist past pages.',
    'sites': ['events.extrachill.com'], 'runtimeEnv': {'WP_AGENT_RUNTIME': '1'},
    'fixtureMuPlugins': ['sandbox-fixture.php'],
    'seed': {'codeFile': 'seed.php', 'timeoutMs': 180000},
    'grade': {'codeFile': 'grade.php', 'timeoutMs': 240000},
    'steps': [step('owner-private-ratings', owner, 711),
              step('visitor-privacy', privacy('visitor')),
              step('other-user-privacy', privacy('other'), 712)],
}
Path(__file__).with_name('journey.json').write_text(json.dumps(journey, indent=2) + '\n')
