(function () {
    'use strict';

    var utils = window.ECAuthUtils;

    function initRegisterTabLinks() {
        document.querySelectorAll('.js-switch-to-register').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();

                var tabsComponent = link.closest('.shared-tabs-component');
                if (!tabsComponent) {
                    return;
                }

                var registerTabButton = tabsComponent.querySelector('.shared-tab-button[data-tab="tab-register"]');
                if (!registerTabButton) {
                    return;
                }

                registerTabButton.click();

                var url = window.location.pathname + window.location.search.split('#')[0] + '#tab-register';
                if (window.history && window.history.pushState) {
                    window.history.pushState(null, '', url);
                } else {
                    window.location.hash = '#tab-register';
                }
            });
        });
    }

    function initLoginForm() {
        document.addEventListener(
            'submit',
            function (event) {
                var form = event.target;
                if (!form || form.id !== 'loginform') {
                    return;
                }

                event.preventDefault();

                var identifier = utils.getFormValue(form, 'input[name="log"]');
                var password = utils.getFormValue(form, 'input[name="pwd"]');
                var remember = utils.getFormChecked(form, 'input[name="rememberme"]');
                var redirectTo = utils.getFormValue(form, 'input[name="redirect_to"]') || window.location.href;

                if (!identifier || !password) {
                    utils.renderNotice(form.closest('.login-register-form'), 'error', 'Username and password are required.');
                    return;
                }

                var deviceId = utils.getDeviceId();
                if (!deviceId) {
                    utils.renderNotice(form.closest('.login-register-form'), 'error', 'Unable to generate a device ID.');
                    return;
                }

                var submitButton = form.querySelector('input[type="submit"], button[type="submit"]');
                var restore = utils.setSubmitting(submitButton, 'Logging in\u2026');

                var url = new URL('extrachill/v1/auth/login', utils.getRestRoot());

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
                    .then(function (response) {
                        return response.json().then(function (data) {
                            if (!response.ok) {
                                var message = data && data.message ? data.message : 'Login failed. Please try again.';
                                throw new Error(message);
                            }
                            return data;
                        });
                    })
                    .then(function () {
                        window.location.assign(redirectTo);
                    })
                    .catch(function (err) {
                        var message = err && err.message ? err.message : 'Login failed. Please try again.';
                        var helpLink = ' <a href="' + utils.getCommunityUrl() + '/reset-password/">Forgot your password?</a>';
                        utils.renderNotice(form.closest('.login-register-form'), 'error', message + helpLink, true);
                        restore();
                    });
            },
            true
        );
    }

    function initRegisterForm() {
        document.addEventListener(
            'submit',
            function (event) {
                var form = event.target;
                if (!form || form.id === 'loginform') {
                    return;
                }

                if (!form.querySelector('input[name="action"][value="extrachill_register_user"]')) {
                    return;
                }

                event.preventDefault();

                var email = utils.getFormValue(form, 'input[name="extrachill_email"]');
                var password = utils.getFormValue(form, 'input[name="extrachill_password"]');
                var passwordConfirm = utils.getFormValue(form, 'input[name="extrachill_password_confirm"]');
                var turnstileResponse = utils.getFormValue(form, 'input[name="cf-turnstile-response"]');
                var registrationPage = utils.getFormValue(form, 'input[name="source_url"]');
                var successRedirectUrl = utils.getFormValue(form, 'input[name="success_redirect_url"]');
                var inviteToken = utils.getFormValue(form, 'input[name="invite_token"]');
                var inviteArtistIdRaw = utils.getFormValue(form, 'input[name="invite_artist_id"]');

                if (!email || !password || !passwordConfirm) {
                    utils.renderNotice(form.closest('.login-register-form'), 'error', 'All fields are required.');
                    return;
                }

                var turnstileWidget = form.querySelector('.cf-turnstile');
                if (turnstileWidget && !turnstileResponse) {
                    utils.renderNotice(form.closest('.login-register-form'), 'error', 'Captcha verification required. Please complete the challenge and try again.');
                    return;
                }

                var deviceId = utils.getDeviceId();
                if (!deviceId) {
                    utils.renderNotice(form.closest('.login-register-form'), 'error', 'Unable to generate a device ID.');
                    return;
                }

                var submitButton = form.querySelector('input[type="submit"], button[type="submit"]');
                var restore = utils.setSubmitting(submitButton, 'Creating account\u2026');

                var inviteArtistId = inviteArtistIdRaw ? parseInt(inviteArtistIdRaw, 10) : 0;
                var fromJoin = new URL(window.location.href).searchParams.get('from_join') === 'true';

                var url = new URL('extrachill/v1/auth/register', utils.getRestRoot());

                fetch(url.toString(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        email: email,
                        password: password,
                        password_confirm: passwordConfirm,
                        turnstile_response: turnstileResponse,
                        device_id: deviceId,
                        device_name: 'Web',
                        set_cookie: true,
                        remember: true,
                        registration_page: registrationPage,
                        registration_source: 'web',
                        registration_method: 'standard',
                        success_redirect_url: successRedirectUrl,
                        invite_token: inviteToken,
                        invite_artist_id: inviteArtistId,
                        from_join: fromJoin
                    })
                })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            if (!response.ok) {
                                var message = data && data.message ? data.message : 'Registration failed. Please try again.';
                                throw new Error(message);
                            }
                            return data;
                        });
                    })
                    .then(function (data) {
                        var redirectTo = data && data.redirect_url ? data.redirect_url : (successRedirectUrl || window.location.href);
                        window.location.assign(redirectTo);
                    })
                    .catch(function (err) {
                        var message = err && err.message ? err.message : 'Registration failed. Please try again.';
                        utils.renderNotice(form.closest('.login-register-form'), 'error', message);
                        restore();
                    });
            },
            true
        );
    }

    function init() {
        if (!utils) {
            console.error('ECAuthUtils not loaded');
            return;
        }
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
