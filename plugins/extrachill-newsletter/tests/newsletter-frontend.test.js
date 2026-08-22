const assert = require('node:assert/strict');
const { readFile } = require('node:fs/promises');
const { test } = require('node:test');
const vm = require('node:vm');

const scriptPath = new URL('../assets/js/newsletter.js', `file://${__filename}`);

async function submitWith(response) {
    const feedback = {
        className: 'notice',
        style: { display: 'none' },
        textContent: ''
    };
    const emailInput = { value: 'listener@example.com' };
    const submitButton = { disabled: false, textContent: 'Subscribe' };
    let submitHandler;

    const form = {
        dataset: { newsletterContext: 'homepage' },
        parentNode: { querySelector: () => feedback },
        addEventListener(event, handler) {
            if (event === 'submit') {
                submitHandler = handler;
            }
        },
        querySelector(selector) {
            if (selector.includes('email')) {
                return emailInput;
            }
            if (selector === 'button[type="submit"]') {
                return submitButton;
            }
            if (selector === '[data-newsletter-feedback]') {
                return feedback;
            }
            return null;
        }
    };
    const stored = new Map();
    const localStorage = {
        getItem: key => stored.get(key) ?? null,
        setItem: (key, value) => stored.set(key, value)
    };
    const window = {
        location: { href: 'https://extrachill.com/' },
        localStorage,
        newsletterParams: {
            restNonce: 'nonce',
            restUrl: 'https://extrachill.com/wp-json/'
        }
    };
    const context = vm.createContext({
        console,
        document: {
            querySelectorAll: () => [form],
            readyState: 'complete'
        },
        fetch: async () => response,
        localStorage,
        Promise,
        URL,
        window
    });

    vm.runInContext(await readFile(scriptPath, 'utf8'), context);
    submitHandler({ preventDefault() {} });
    await new Promise(resolve => setImmediate(resolve));
    await new Promise(resolve => setImmediate(resolve));

    return { emailInput, feedback, stored, submitButton };
}

test('renders success and records a genuine subscription', async () => {
    const state = await submitWith({
        ok: true,
        json: async () => ({ success: true, message: 'Welcome!' })
    });

    assert.equal(state.feedback.className, 'notice notice-success');
    assert.equal(state.feedback.textContent, 'Welcome!');
    assert.equal(state.emailInput.value, '');
    assert.equal(state.stored.get('subscribed'), 'true');
    assert.match(state.stored.get('lastSubscribedTime'), /^\d+$/);
    assert.equal(state.submitButton.disabled, false);
});

test('renders an owned error for HTTP success with success false', async () => {
    const state = await submitWith({
        ok: true,
        json: async () => ({ success: false, message: 'Subscription failed, please try again' })
    });

    assert.equal(state.feedback.className, 'notice notice-error');
    assert.equal(state.feedback.textContent, 'Subscription failed, please try again');
    assert.equal(state.emailInput.value, 'listener@example.com');
    assert.equal(state.stored.size, 0);
});

test('renders a generic error for a malformed response', async () => {
    const state = await submitWith({
        ok: true,
        json: async () => {
            throw new SyntaxError('Unexpected token');
        }
    });

    assert.equal(state.feedback.className, 'notice notice-error');
    assert.equal(state.feedback.textContent, 'An error occurred. Please try again.');
    assert.equal(state.emailInput.value, 'listener@example.com');
    assert.equal(state.stored.size, 0);
});

test('renders the server message for a non-2xx response', async () => {
    const state = await submitWith({
        ok: false,
        json: async () => ({ message: 'Security verification failed.' })
    });

    assert.equal(state.feedback.className, 'notice notice-error');
    assert.equal(state.feedback.textContent, 'Security verification failed.');
    assert.equal(state.emailInput.value, 'listener@example.com');
    assert.equal(state.stored.size, 0);
});
