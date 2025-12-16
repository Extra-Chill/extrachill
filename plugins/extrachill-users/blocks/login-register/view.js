(function () {
    'use strict';

    function getRestRoot() {
        const link = document.querySelector('link[rel="https://api.w.org/"]');
        if (link && link.href) {
            return link.href;
        }

        return new URL('/wp-json/', window.location.origin).toString();
    }

    function uuidv4() {
        if (!window.crypto || !window.crypto.getRandomValues) {
            return '';
        }

        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);

        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;

        const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0'));
        return (
            hex.slice(0, 4).join('') +
            '-' +
            hex.slice(4, 6).join('') +
            '-' +
            hex.slice(6, 8).join('') +
            '-' +
            hex.slice(8, 10).join('') +
            '-' +
            hex.slice(10, 16).join('')
        );
    }

    function getDeviceId() {
        try {
            const key = 'extrachill_device_id';
            const existing = window.localStorage.getItem(key);
            if (existing) {
                return existing;
            }

            const generated = uuidv4();
            if (!generated) {
                return '';
            }

            window.localStorage.setItem(key, generated);
            return generated;
        } catch (err) {
            return '';
        }
    }

    function renderNotice(container, type, message) {
        if (!container) {
            return;
        }

        let notice = container.querySelector('[data-ec-auth-notice="1"]');
        if (!notice) {
            notice = document.createElement('div');
            notice.dataset.ecAuthNotice = '1';
            container.prepend(notice);
        }

        notice.className = 'notice notice-' + type;
        notice.innerHTML = '';

        const p = document.createElement('p');
        p.textContent = message;
        notice.appendChild(p);
    }

    function initRegisterTabLinks() {
        document.querySelectorAll('.js-switch-to-register').forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();

                const tabsComponent = link.closest('.shared-tabs-component');
                if (!tabsComponent) {
                    return;
                }

                const registerTabButton = tabsComponent.querySelector('.shared-tab-button[data-tab="tab-register"]');
                if (!registerTabButton) {
                    return;
                }

                registerTabButton.click();

                const url = window.location.pathname + window.location.search.split('#')[0] + '#tab-register';
                if (window.history && window.history.pushState) {
                    window.history.pushState(null, '', url);
                } else {
                    window.location.hash = '#tab-register';
                }
            });
        });
    }

    function setSubmitting(submitButton, label) {
        if (!submitButton) {
            return () => {};
        }

        const original = submitButton.value !== undefined ? submitButton.value : submitButton.textContent;
        submitButton.disabled = true;

        if (submitButton.value !== undefined) {
            submitButton.value = label;
        } else {
            submitButton.textContent = label;
        }

        return () => {
            submitButton.disabled = false;
            if (submitButton.value !== undefined) {
                submitButton.value = original || '';
            } else {
                submitButton.textContent = original || '';
            }
        };
    }

    function getFormValue(form, selector) {
        const el = form.querySelector(selector);
        return el ? el.value || '' : '';
    }

    function getFormChecked(form, selector) {
        const el = form.querySelector(selector);
        return el ? !!el.checked : false;
    }

    function initLoginForm() {
        document.addEventListener(
            'submit',
            (event) => {
                const form = event.target;
                if (!form || form.id !== 'loginform') {
                    return;
                }

                event.preventDefault();

                const identifier = getFormValue(form, 'input[name="log"]');
                const password = getFormValue(form, 'input[name="pwd"]');
                const remember = getFormChecked(form, 'input[name="rememberme"]');
                const redirectTo = getFormValue(form, 'input[name="redirect_to"]') || window.location.href;

                if (!identifier || !password) {
                    renderNotice(form.closest('.login-register-form'), 'error', 'Username and password are required.');
                    return;
                }

                const deviceId = getDeviceId();
                if (!deviceId) {
                    renderNotice(form.closest('.login-register-form'), 'error', 'Unable to generate a device ID.');
                    return;
                }

                const submitButton = form.querySelector('input[type="submit"], button[type="submit"]');
                const restore = setSubmitting(submitButton, 'Logging in\u2026');

                const url = new URL('extrachill/v1/auth/login', getRestRoot());

                fetch(url.toString(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        identifier: identifier,
                        password: password,
                        device_id: deviceId,
                        remember: remember,
                        set_cookie: true,
                        device_name: 'Web'
                    })
                })
                    .then(async (response) => {
                        const data = await response.json().catch(() => null);
                        if (!response.ok) {
                            const message = data && data.message ? data.message : 'Login failed. Please try again.';
                            throw new Error(message);
                        }
                        return data;
                    })
                    .then(() => {
                        window.location.assign(redirectTo);
                    })
                    .catch((err) => {
                        const message = err && err.message ? err.message : 'Login failed. Please try again.';
                        renderNotice(form.closest('.login-register-form'), 'error', message);
                        restore();
                    });
            },
            true
        );
    }

    function initRegisterForm() {
        document.addEventListener(
            'submit',
            (event) => {
                const form = event.target;
                if (!form || form.id === 'loginform') {
                    return;
                }

                if (!form.querySelector('input[name="action"][value="extrachill_register_user"]')) {
                    return;
                }

                event.preventDefault();

                const username = getFormValue(form, 'input[name="extrachill_username"]');
                const email = getFormValue(form, 'input[name="extrachill_email"]');
                const password = getFormValue(form, 'input[name="extrachill_password"]');
                const passwordConfirm = getFormValue(form, 'input[name="extrachill_password_confirm"]');
                const turnstileResponse = getFormValue(form, 'input[name="cf-turnstile-response"]');
                const registrationPage = getFormValue(form, 'input[name="source_url"]');
                const successRedirectUrl = getFormValue(form, 'input[name="success_redirect_url"]');
                const inviteToken = getFormValue(form, 'input[name="invite_token"]');
                const inviteArtistIdRaw = getFormValue(form, 'input[name="invite_artist_id"]');
                const userIsArtist = getFormChecked(form, 'input[name="user_is_artist"]');
                const userIsProfessional = getFormChecked(form, 'input[name="user_is_professional"]');

                if (!username || !email || !password || !passwordConfirm) {
                    renderNotice(form.closest('.login-register-form'), 'error', 'All fields are required.');
                    return;
                }

                if (!turnstileResponse) {
                    renderNotice(form.closest('.login-register-form'), 'error', 'Captcha verification required. Please complete the challenge and try again.');
                    return;
                }

                const deviceId = getDeviceId();
                if (!deviceId) {
                    renderNotice(form.closest('.login-register-form'), 'error', 'Unable to generate a device ID.');
                    return;
                }

                const submitButton = form.querySelector('input[type="submit"], button[type="submit"]');
                const restore = setSubmitting(submitButton, 'Creating account\u2026');

                const inviteArtistId = inviteArtistIdRaw ? parseInt(inviteArtistIdRaw, 10) : 0;
                const fromJoin = new URL(window.location.href).searchParams.get('from_join') || '';

                const url = new URL('extrachill/v1/auth/register', getRestRoot());

                fetch(url.toString(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        username: username,
                        email: email,
                        password: password,
                        password_confirm: passwordConfirm,
                        turnstile_response: turnstileResponse,
                        device_id: deviceId,
                        device_name: 'Web',
                        set_cookie: true,
                        remember: true,
                        registration_page: registrationPage,
                        success_redirect_url: successRedirectUrl,
                        invite_token: inviteToken,
                        invite_artist_id: inviteArtistId,
                        user_is_artist: userIsArtist,
                        user_is_professional: userIsProfessional,
                        from_join: fromJoin
                    })
                })
                    .then(async (response) => {
                        const data = await response.json().catch(() => null);
                        if (!response.ok) {
                            const message = data && data.message ? data.message : 'Registration failed. Please try again.';
                            throw new Error(message);
                        }
                        return data;
                    })
                    .then((data) => {
                        const redirectTo = data && data.redirect_url ? data.redirect_url : (successRedirectUrl || window.location.href);
                        window.location.assign(redirectTo);
                    })
                    .catch((err) => {
                        const message = err && err.message ? err.message : 'Registration failed. Please try again.';
                        renderNotice(form.closest('.login-register-form'), 'error', message);
                        restore();
                    });
            },
            true
        );
    }

    function init() {
        initRegisterTabLinks();
        initLoginForm();
        initRegisterForm();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
