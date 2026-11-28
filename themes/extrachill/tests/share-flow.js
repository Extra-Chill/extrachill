const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const load = (name) => fs.readFileSync(path.join(__dirname, '../assets/js', name), 'utf8');
const miniDropdown = load('mini-dropdown.js');
const share = load('share.js');
const page = 'https://events.extrachill.com/events/meetup/';
const title = 'Meet & Greet';
const nativeUrl = page + '?utm_source=native&utm_medium=share&utm_campaign=share';

function fixture(shareApi) {
    let expanded = 'false';
    const dropdown = {
        getAttribute: () => expanded,
        setAttribute: (name, value) => { expanded = value; }
    };
    const toggle = {
        closest: (selector) => selector === '.share-dropdown' ? dropdown : null,
        dataset: { shareTitle: title, nativeShareUrl: nativeUrl }
    };
    const captures = [];
    const bubbles = [];
    const events = [];
    const document = {
        addEventListener(type, listener, capture) {
            if (type === 'click') {
                (capture ? captures : bubbles).push(listener);
            }
        },
        querySelectorAll: () => [dropdown]
    };
    const navigator = {
        ...shareApi,
        sendBeacon(endpoint, data) {
            assert.equal(endpoint, '/wp-json/extrachill/v1/analytics/click');
            events.push(data);
            return true;
        }
    };
    const context = vm.createContext({ document, navigator, Blob, window: { location: { href: page } } });
    vm.runInContext(miniDropdown, context);
    vm.runInContext(share, context);

    function click(matches) {
        const event = {
            target: { closest: (selector) => matches[selector] || null },
            defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; }
        };
        return Promise.all([...captures, ...bubbles].map((listener) => listener(event)));
    }

    return {
        clickToggle: () => click({
            '.share-dropdown .ec-mini-dropdown-toggle': toggle,
            '.ec-mini-dropdown-toggle': toggle,
            '.ec-mini-dropdown': dropdown
        }),
        pickX: () => click({
            '.share-dropdown a[data-share-destination]': { dataset: { shareDestination: 'twitter' } },
            '.ec-mini-dropdown': dropdown
        }),
        isOpen: () => expanded === 'true',
        payloads: () => Promise.all(events.map(async (event) => JSON.parse(await event.text())))
    };
}

async function run() {
    let resolveShare;
    const success = fixture({ share(data) {
        assert.equal(data.title, title);
        assert.equal(data.url, nativeUrl);
        return new Promise((resolve) => { resolveShare = resolve; });
    } });
    const pending = success.clickToggle();
    assert.equal(typeof resolveShare, 'function', 'native sheet starts within the click');
    assert.equal(success.isOpen(), false, 'native share does not open the menu');
    await success.clickToggle();
    resolveShare();
    await pending;
    const successEvents = await success.payloads();
    assert.deepEqual(successEvents.map((event) => event.share_destination), ['toggle', 'native']);
    assert.equal(successEvents[1].destination_url, nativeUrl);

    const cancelled = fixture({ share: () => Promise.reject({ name: 'AbortError' }) });
    await cancelled.clickToggle();
    assert.equal(cancelled.isOpen(), false);
    assert.deepEqual((await cancelled.payloads()).map((event) => event.share_destination), ['toggle', 'native_cancelled']);

    const fallbackApis = [
        undefined,
        { share: () => Promise.reject(new Error('Not allowed')) },
        { share() { throw new Error('Unavailable'); } },
        { canShare: () => false, share: () => Promise.resolve() }
    ];
    for (const api of fallbackApis) {
        const fallback = fixture(api);
        await fallback.clickToggle();
        assert.equal(fallback.isOpen(), true);
        await fallback.clickToggle();
        assert.equal(fallback.isOpen(), false);
        await fallback.clickToggle();
        await fallback.pickX();
        assert.equal(fallback.isOpen(), false);
        assert.deepEqual((await fallback.payloads()).map((event) => event.share_destination), ['toggle', 'toggle', 'twitter']);
    }

    process.stdout.write('Share flow covers native success, cancellation, and fallback menu analytics.\n');
}

run().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
