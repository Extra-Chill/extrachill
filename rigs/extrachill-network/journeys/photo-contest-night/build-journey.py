import json
PAGE = "http://extrachill.com/photo-contest-night/"
def observe(label, js):
    return {"kind": "evaluate", "expression": "(async()=>{let v;try{v=await (async()=>(" + js + "))()}catch(e){v='ERR:'+e.message}try{await fetch('/?ec_photo_contest_observe=1',{method:'POST',headers:{'content-type':'application/json'},credentials:'same-origin',keepalive:true,body:JSON.stringify({label:" + json.dumps(label) + ",url:location.href,value:v})})}catch(e){}return true})()"}
def shot(n): return {"kind": "screenshot", "name": n}
def wait(ms): return {"kind": "waitFor", "waitFor": "duration", "duration": f"{ms}ms"}
C = ".extrachill-blocks-photo-contest-container"
LAYOUT = """(()=>{const q=s=>document.querySelector(s);const r=e=>e?Math.round(e.getBoundingClientRect().top+scrollY):null;const form=q('.extrachill-blocks-photo-contest-form');const cards=[...document.querySelectorAll('.extrachill-blocks-photo-contest-entry')];return {viewportH:innerHeight,pageH:document.documentElement.scrollHeight,formTop:r(form),formAboveFold:form?r(form)<innerHeight:false,ctaTop:r(q('.extrachill-blocks-photo-contest-cta')),galleryTop:r(q('.extrachill-blocks-photo-contest-grid')),entryCards:cards.length,entryNames:cards.map(c=>(c.querySelector('.extrachill-blocks-photo-contest-entry-name')||{}).textContent),notices:[...document.querySelectorAll(C+' .notice')].map(n=>n.textContent.trim()).slice(0,5),focused:!!q('.extrachill-blocks-photo-contest-focused'),seeAll:!!q('.extrachill-blocks-photo-contest-see-all'),h3:[...document.querySelectorAll(C+' h3')].map(h=>h.textContent.trim())}})()""".replace("C+'", "'" + C)
UPLOAD = """(async()=>{const c=document.createElement('canvas');c.width=900;c.height=1200;const x=c.getContext('2d');x.fillStyle='#F25B30';x.fillRect(0,0,900,1200);x.fillStyle='#17304D';x.font='bold 90px sans-serif';x.fillText('PHONE COSTUME '+Date.now(),40,600);const b=await new Promise(r=>c.toBlob(r,'image/jpeg',0.85));const f=new File([b],'IMG_4821.jpg',{type:'image/jpeg'});const i=document.querySelector('.extrachill-blocks-photo-contest-file');const d=new DataTransfer();d.items.add(f);i.files=d.files;i.dispatchEvent(new Event('change',{bubbles:true}));return i.files.length})()"""
TOKEN = """(()=>{const f=document.querySelector('.extrachill-blocks-photo-contest-form');let i=f.querySelector('input[name="cf-turnstile-response"]');const had=!!i;if(!i){i=document.createElement('input');i.type='hidden';i.name='cf-turnstile-response';f.appendChild(i)}i.value='rig-solved-widget-token';return {widgetContainer:!!f.querySelector('.cf-turnstile'),hadTokenInput:had}})()"""
AFTER_SUBMIT = """(async()=>{for(let i=0;i<40;i++){const im=document.querySelector('.extrachill-blocks-photo-contest-qr img');if((im&&im.complete&&im.naturalWidth>0)||document.querySelector('.extrachill-blocks-photo-contest-qr .notice-error'))break;await new Promise(r=>setTimeout(r,250))}const s=document.querySelector('.extrachill-blocks-photo-contest-submitted');const qr=document.querySelector('.extrachill-blocks-photo-contest-qr img');const link=document.querySelector('.extrachill-blocks-photo-contest-submitted .extrachill-blocks-photo-contest-share-link');return {success:s?s.querySelector('h4')?.textContent.trim():null,errors:[...document.querySelectorAll('.extrachill-blocks-photo-contest-form .notice-error')].map(n=>n.textContent.trim()),shareButtons:s?[...s.querySelectorAll('button,a')].map(b=>b.textContent.trim()).filter(Boolean):[],qrShown:!!qr,qrLoaded:qr?qr.complete&&qr.naturalWidth>0:false,qrError:(document.querySelector('.extrachill-blocks-photo-contest-qr .notice-error')||{}).textContent||null,shareLink:link?link.textContent.trim():null,myEntryInGallery:[...document.querySelectorAll('.extrachill-blocks-photo-contest-entry-name')].some(n=>/Rig Phone Entrant/.test(n.textContent))}})()"""

STUB = """(()=>{window.__rigShared=null;window.__rigCopied=null;try{Object.defineProperty(navigator,'share',{configurable:true,value:async d=>{window.__rigShared=d}})}catch(e){}try{Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async t=>{window.__rigCopied=t}}})}catch(e){}return !!navigator.share})()"""
SHARE = """(async()=>{const s=document.querySelector('.extrachill-blocks-photo-contest-submitted');if(!s)return null;const btns=[...s.querySelectorAll('button,a')];const by=t=>btns.find(b=>b.textContent.trim()===t);const sh=by('Share');if(sh){sh.click();await new Promise(r=>setTimeout(r,500))}const cp=by('Copy Link');if(cp){cp.click();await new Promise(r=>setTimeout(r,500))}const link=(s.querySelector('.extrachill-blocks-photo-contest-share-link')||{}).textContent||null;return {buttons:btns.map(b=>b.textContent.trim()).filter(Boolean),nativeShareButton:!!sh,shared:window.__rigShared,copied:window.__rigCopied,copiedLabel:cp?cp.textContent.trim():null,facebook:(by('Facebook')||{}).href||null,x:(by('X')||{}).href||null,link:link&&link.trim()}})()"""
OG = """(async()=>{const l=document.querySelector('.extrachill-blocks-photo-contest-submitted .extrachill-blocks-photo-contest-share-link');if(!l)return null;const u=l.textContent.trim().replace(/^https:/,'http:');const r=await fetch(u,{credentials:'omit'});const d=new DOMParser().parseFromString(await r.text(),'text/html');const m=p=>(d.querySelector('meta[property="'+p+'"]')||{}).content||null;const mine=[...document.querySelectorAll('.extrachill-blocks-photo-contest-entry')].find(c=>/Rig Phone Entrant/.test(c.textContent));return {status:r.status,ogImage:m('og:image'),ogTitle:m('og:title'),ogDescription:m('og:description'),entryImage:mine?(mine.querySelector('img')||{}).src:null}})()"""
RETURN = """(()=>{const C=document.querySelector('.extrachill-blocks-photo-contest-container');const txt=C?C.innerText:'';const panels=[...document.querySelectorAll('.extrachill-blocks-photo-contest-share')].map(p=>p.closest('.extrachill-blocks-photo-contest-entry,.card,.notice,.extrachill-blocks-photo-contest-submitted')||p);const mine=panels.find(p=>/Rig Phone Entrant/.test(p.textContent));return {yourEntryText:/your entry|you're in/i.test(txt),myPanel:!!mine,myVotes:mine?((mine.querySelector('.vote-number')||{}).textContent||null):null,entryForm:!!document.querySelector('.extrachill-blocks-photo-contest-form'),detailsStep:!!document.querySelector('.extrachill-blocks-photo-contest-form input[placeholder="Display name"]'),errors:[...document.querySelectorAll('.extrachill-blocks-photo-contest-form .notice-error')].map(n=>n.textContent.trim())}})()"""
entrant = [
    {"kind": "navigate", "url": PAGE, "waitFor": "load"},
    {"kind": "waitFor", "selector": ".extrachill-blocks-photo-contest-form"},
    wait(1500),
    {"kind": "evaluate", "expression": STUB},
    observe("entrant-landing", LAYOUT),
    shot("e01-landing"),
    {"kind": "fill", "selector": ".extrachill-blocks-photo-contest-form input[type=\"email\"]", "value": "rig-phone-entrant@example.test"},
    {"kind": "click", "selector": ".extrachill-blocks-photo-contest-form .button-1"},
    {"kind": "waitFor", "selector": ".extrachill-blocks-photo-contest-form input[placeholder=\"Display name\"]"},
    shot("e02-details-step"),
    {"kind": "fill", "selector": ".extrachill-blocks-photo-contest-form input[placeholder=\"Display name\"]", "value": "Rig Phone Entrant"},
    {"kind": "fill", "selector": ".extrachill-blocks-photo-contest-form input[placeholder=\"Instagram handle (optional)\"]", "value": "@rigphone"},
    observe("entrant-file-set", UPLOAD),
    observe("entrant-turnstile", TOKEN),
    wait(500),
    shot("e03-filled"),
    {"kind": "click", "selector": ".extrachill-blocks-photo-contest-form .button-1"},
    wait(9000),
    observe("entrant-after-submit", AFTER_SUBMIT),
    shot("e04-after-submit"),
    {"kind": "evaluate", "expression": "(()=>{const e=document.querySelector('.extrachill-blocks-photo-contest-submitted');if(e)e.scrollIntoView();return true})()"},
    shot("e05-share-panel"),
    observe("entrant-share-actions", SHARE),
    observe("entrant-share-preview", OG),
    {"kind": "navigate", "url": PAGE, "waitFor": "load"},
    wait(5000),
    observe("entrant-returns-same-phone", RETURN),
    shot("e06-returns-same-phone"),
]
other_phone = [
    {"kind": "navigate", "url": PAGE, "waitFor": "load"},
    {"kind": "waitFor", "selector": ".extrachill-blocks-photo-contest-form"},
    wait(1500),
    {"kind": "fill", "selector": ".extrachill-blocks-photo-contest-form input[type=\"email\"]", "value": "rig-phone-entrant@example.test"},
    {"kind": "click", "selector": ".extrachill-blocks-photo-contest-form .button-1"},
    wait(5000),
    observe("entrant-other-phone", RETURN),
    shot("e07-other-phone-email-only"),
]
PICK = """(async()=>{const cfg=JSON.parse(document.querySelector('.extrachill-blocks-photo-contest-config').textContent);const r=await fetch('/wp-json/extrachill/v1/content-blocks/photo-contest/entries?post_id='+cfg.postId+'&contest_id='+encodeURIComponent(cfg.contestId));const j=await r.json();const list=Array.isArray(j)?j:(j.entries||[]);const e=list.find(x=>/Mothman/.test(x.display_name))||list[0];sessionStorage.setItem('rigShare',e?e.share_url:'');return {status:r.status,count:list.length,share:e?e.share_url:null}})()"""
GO = "(()=>{const u=sessionStorage.getItem('rigShare');if(u)location.assign(u.replace(/^https:/,'http:'));return !!u})()"
VOTE_STATE = """(()=>{const cards=[...document.querySelectorAll('.extrachill-blocks-photo-contest-entry')];return {focused:!!document.querySelector('.extrachill-blocks-photo-contest-focused'),cards:cards.map(c=>({name:(c.querySelector('.extrachill-blocks-photo-contest-entry-name')||{}).textContent,votes:(c.querySelector('.vote-number')||{}).textContent,button:(c.querySelector('.extrachill-blocks-photo-contest-vote-button')||{}).textContent,disabled:(c.querySelector('.extrachill-blocks-photo-contest-vote-button')||{}).disabled,emailPrompt:!!c.querySelector('.extrachill-blocks-photo-contest-vote-form')})),notices:[...document.querySelectorAll('.extrachill-blocks-photo-contest-container .notice')].map(n=>n.textContent.trim()),entryForm:!!document.querySelector('.extrachill-blocks-photo-contest-form')}})()"""
voter = [
    {"kind": "navigate", "url": PAGE, "waitFor": "load"},
    {"kind": "waitFor", "selector": ".extrachill-blocks-photo-contest-form"},
    observe("voter-pick-share", PICK),
    {"kind": "evaluate", "expression": GO},
    wait(5000),
    {"kind": "waitFor", "selector": ".extrachill-blocks-photo-contest-vote-button"},
    wait(1000),
    observe("voter-focused-landing", LAYOUT),
    observe("voter-focused-state", VOTE_STATE),
    shot("v01-friend-link-landing"),
    {"kind": "click", "selector": ".extrachill-blocks-photo-contest-vote-button"},
    wait(800),
    observe("voter-after-first-tap", VOTE_STATE),
    shot("v02-email-prompt"),
    {"kind": "fill", "selector": ".extrachill-blocks-photo-contest-vote-form input[type=\"email\"]", "value": "rig-friend-voter@example.test"},
    {"kind": "click", "selector": ".extrachill-blocks-photo-contest-vote-form .button-1"},
    wait(4000),
    observe("voter-after-vote", VOTE_STATE),
    shot("v03-after-vote"),
    {"kind": "evaluate", "expression": "(()=>{const b=document.querySelector('.extrachill-blocks-photo-contest-vote-button');if(b&&!b.disabled)b.click();return true})()"},
    wait(2500),
    observe("voter-repeat-attempt", VOTE_STATE),
    {"kind": "evaluate", "expression": "(()=>{const a=document.querySelector('.extrachill-blocks-photo-contest-see-all a')||document.querySelector('.extrachill-blocks-photo-contest-see-all');if(a){a.click();return true}return false})()"},
    wait(4000),
    observe("voter-gallery", LAYOUT),
    observe("voter-gallery-state", VOTE_STATE),
    shot("v04-see-all-gallery"),
    {"kind": "evaluate", "expression": "(()=>{const b=[...document.querySelectorAll('.extrachill-blocks-photo-contest-vote-button')].find(x=>!x.disabled);if(b){b.scrollIntoView({block:'center'});b.click();return b.closest('.extrachill-blocks-photo-contest-entry').querySelector('.extrachill-blocks-photo-contest-entry-name').textContent}return null})()"},
    wait(4000),
    observe("voter-second-entry-one-tap", VOTE_STATE),
    shot("v05-second-vote"),
    {"kind": "evaluate", "expression": "[...document.querySelectorAll('.vote-number')].some(n=>Number(n.textContent)>0)", "assert": True},
]
def step(name, steps):
    return {"command": "wordpress.browser-actions", "allowFailure": True, "metadata": {"kind": "journey-browser-step", "step": name},
            "args": [f"url={PAGE}", "route-host=extrachill.com", "network-policy=block", "allow-host=extrachill.com", "viewport=390x844",
                     "step-timeout=60s", "timeout=300s", "capture=steps,console,errors,network,screenshot,dom-snapshot", "steps-json=" + json.dumps(steps)]}
j = {
  "schema": "extrachill-network/journey/v1",
  "id": "photo-contest-night",
  "title": "Photo contest night: entrant on a phone, then a friend voting from a share link",
  "description": "Two phone-sized personas walk a live extrachill/photo-contest page. (1) An entrant at the venue enters with email, name, Instagram handle and a camera-roll photo through the real multipart upload and the Turnstile-guarded extrachill/v1 submit route, then lands on the share panel with their personal link and in-person QR. (2) A friend, at the venue or not, opens an entrant's personal share link, votes with an email, cannot double-vote, then taps 'See all entries' and votes for a second costume with the remembered email. (3) The entrant shares through the phone share sheet and Copy Link, the link unfurls with their photo, and they come back later (same phone, then another phone with only their email) to find their entry and live votes. The grade checks REST answers, stored entries and vote counts against what each persona saw.",
  "origin": {"repository": "Extra-Chill/extrachill-content-blocks", "issue": "Extra-Chill/extrachill-content-blocks#38"},
  "sites": ["extrachill.com"],
  "fixtureMuPlugins": ["sandbox-fixture.php"],
  "seed": {"codeFile": "seed.php", "timeoutMs": 180000},
  "grade": {"codeFile": "grade.php", "timeoutMs": 120000},
  "steps": [step("entrant-at-venue", entrant), step("friend-voter", voter), step("entrant-checks-in-later", other_phone)],
}
open("journey.json", "w").write(json.dumps(j, indent=2) + "\n")
