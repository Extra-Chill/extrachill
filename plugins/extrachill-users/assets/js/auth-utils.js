/**
 * Shared authentication utilities for ExtraChill Users blocks.
 *
 * Exposes ECAuthUtils global object with common functions used across
 * login-register, onboarding, and google-signin modules.
 */
(function () {
    'use strict';

    function normalizeRestRoot(root) {
        if (!root) {
            return '';
        }

        try {
            const url = new URL(root, window.location.origin);
            if (!url.pathname.endsWith('/')) {
                url.pathname += '/';
            }
            return url.toString();
        } catch (err) {
            return '';
        }
    }

    function getRestRoot() {
        if (window.wpApiSettings && window.wpApiSettings.root) {
            const normalized = normalizeRestRoot(window.wpApiSettings.root);
            if (normalized) {
                return normalized;
            }
        }

        const link = document.querySelector('link[rel="https://api.w.org/"]');
        if (link && link.href) {
            const linkRoot = normalizeRestRoot(link.href);
            if (linkRoot) {
                return linkRoot;
            }
        }

        return normalizeRestRoot(new URL('/wp-json/', window.location.origin).toString());
    }

    function uuidv4() {
        if (!window.crypto || !window.crypto.getRandomValues) {
            return '';
        }

        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);

        // Set RFC 4122 version (4) and variant (10xx) bits — bitwise required by spec.
        // eslint-disable-next-line no-bitwise
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        // eslint-disable-next-line no-bitwise
        bytes[8] = (bytes[8] & 0x3f) | 0x80;

        const hex = Array.from(bytes, function (b) {
            return b.toString(16).padStart(2, '0');
        });

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

    function renderNotice(container, type, message, allowHtml) {
        if (!container) {
            return;
        }

		let notice = container.querySelector('[data-ec-auth-notice="1"]');
		if (!notice) {
			notice = document.createElement('div');
			notice.dataset.ecAuthNotice = '1';
			notice.className = 'ec-auth-notice';
			container.prepend(notice);
		}

		notice.className = 'ec-auth-notice ec-auth-notice--' + type;
		notice.innerHTML = '';

        const p = document.createElement('p');
        if (allowHtml) {
            p.innerHTML = message;
        } else {
            p.textContent = message;
        }
        notice.appendChild(p);
    }

    function clearNotice(container) {
        if (!container) {
            return;
        }

        const notice = container.querySelector('[data-ec-auth-notice="1"]');
        if (notice) {
            notice.remove();
        }
    }

    function setSubmitting(button, label) {
        if (!button) {
            return function () {};
        }

        const original = button.value !== undefined ? button.value : button.textContent;
        button.disabled = true;

        if (button.value !== undefined) {
            button.value = label;
        } else {
            button.textContent = label;
        }

        return function () {
            button.disabled = false;
            if (button.value !== undefined) {
                button.value = original || '';
            } else {
                button.textContent = original || '';
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

    function getCommunityUrl() {
        return 'https://community.extrachill.com';
    }

    window.ECAuthUtils = {
        getRestRoot,
        getDeviceId,
        renderNotice,
        clearNotice,
        setSubmitting,
        getFormValue,
        getFormChecked,
        getCommunityUrl
    };
})();
