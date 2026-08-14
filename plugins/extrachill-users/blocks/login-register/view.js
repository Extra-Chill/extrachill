/**
 * External dependencies
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
	BlockShell,
	BlockShellInner,
	Panel,
	ResponsiveTabs,
} from '@extrachill/components';
import '@extrachill/components/styles/components.scss';

export function GoogleButtons( { redirectUrl, registration = false } ) {
	const buttonLabel = registration ? 'Sign up with Google' : 'Continue with Google';
	const googleText = registration ? 'signup_with' : 'continue_with';

	// When redirectUrl is set, this subsite is NOT the canonical Google
	// origin — render a styled link that sends the user to the canonical
	// login page instead of trying to render the GIS button here (which
	// would silently fail because GIS rejects origins not registered in
	// GCP). When redirectUrl is null, we're on the canonical origin and
	// the GIS button container is populated by google-signin.js.
	return (
		<>
			<div className="social-login-divider">
				<span>or</span>
			</div>
			<div className="social-login-buttons">
				{ redirectUrl ? (
					<a
						href={ redirectUrl }
						className="google-signin-button google-signin-button--redirect"
						role="button"
					>
						<span className="google-signin-button__icon" aria-hidden="true">
							<svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
								<path d="M17.64 9.205c0-.639-.057-1.252-.164-1.841H9v3.481h4.844a4.14 4.14 0 01-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/>
								<path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 009 18z" fill="#34A853"/>
								<path d="M3.964 10.71A5.41 5.41 0 013.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 000 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>
								<path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0A8.997 8.997 0 00.957 4.958L3.964 7.29C4.672 5.163 6.656 3.58 9 3.58z" fill="#EA4335"/>
							</svg>
						</span>
						<span className="google-signin-button__label">{ buttonLabel }</span>
					</a>
				) : (
					<div className="google-signin-button" data-google-text={ googleText }></div>
				) }
			</div>
		</>
	);
}

/**
 * Capture last-touch source attribution from the browser at registration time.
 *
 * Reads the external referrer (document.referrer, only available client-side)
 * and any utm_* query parameters from the current URL. The referrer is omitted
 * when it points back to the same host (internal navigation) so we only attribute
 * genuine external sources. Returns an object that is safe to spread into the
 * registration request body — both fields are optional downstream.
 *
 * @return {{referrer: string, utm: Object}} Attribution payload (referrer may be
 *   empty; utm may be an empty object).
 */
function captureAttribution() {
	let referrer = '';
	try {
		const raw = document.referrer || '';
		if ( raw ) {
			const referrerHost = new URL( raw ).host;
			if ( referrerHost && referrerHost !== window.location.host ) {
				referrer = raw;
			}
		}
	} catch {
		referrer = '';
	}

	const utm = {};
	try {
		const params = new URLSearchParams( window.location.search );
		[ 'source', 'medium', 'campaign', 'term', 'content' ].forEach( ( key ) => {
			const value = params.get( `utm_${ key }` );
			if ( value ) {
				utm[ key ] = value;
			}
		} );
	} catch {
		// Leave utm empty on any parsing failure.
	}

	return { referrer, utm };
}

function renderTurnstile( container ) {
	if ( ! container || ! window.turnstile ) {
		return;
	}

	const widget = container.querySelector( '.cf-turnstile' );
	if ( ! widget ) {
		return;
	}

	if ( widget.dataset.ecTurnstileRendered === '1' ) {
		return;
	}

	if ( typeof window.turnstile.render === 'function' ) {
		window.turnstile.render( widget );
		widget.dataset.ecTurnstileRendered = '1';
	}
}

function LoggedInCard( { config } ) {
	return (
		<BlockShell>
			<BlockShellInner maxWidth="narrow">
				<Panel>
					<div className="logged-in-avatar" dangerouslySetInnerHTML={ { __html: config.avatarHtml } } />
					<h3>{ config.displayName }</h3>
					<p className="logged-in-status">You are logged in</p>
					<div className="logged-in-actions">
						<a href={ config.profileUrl } className="button-1 button-medium">View Profile</a>
						<a href={ config.homeUrl } className="button-2 button-medium">Go to Homepage</a>
						<a href={ config.logoutUrl } className="button-3 button-medium">Log Out</a>
					</div>
				</Panel>
			</BlockShellInner>
		</BlockShell>
	);
}

export function LoginPanel( { config, notice, setNotice } ) {
	const panelRef = useRef( null );

	useEffect( () => {
		renderTurnstile( panelRef.current );
	} );

	const handleSubmit = async ( event ) => {
		event.preventDefault();
		setNotice( null );

		const form = event.currentTarget;
		const formData = new window.FormData( form );
		const identifier = String( formData.get( 'log' ) || '' ).trim();
		const password = String( formData.get( 'pwd' ) || '' );

		if ( ! identifier || ! password ) {
			setNotice( { type: 'error', message: 'Username and password are required.' } );
			return;
		}

		const turnstileResponse = String( formData.get( 'cf-turnstile-response' ) || '' );
		const turnstileWidget = form.querySelector( '.cf-turnstile' );
		if ( turnstileWidget && ! turnstileResponse ) {
			setNotice( { type: 'error', message: 'Captcha verification required. Please complete the challenge and try again.' } );
			return;
		}

		const utils = window.ECAuthUtils;
		const deviceId = utils?.getDeviceId ? utils.getDeviceId() : '';
		if ( ! deviceId ) {
			setNotice( { type: 'error', message: 'Unable to generate a device ID.' } );
			return;
		}

		const remember = formData.get( 'rememberme' ) === 'forever';
		const redirectTo = String( formData.get( 'redirect_to' ) || window.location.href );
		const submitButton = form.querySelector( 'input[type="submit"], button[type="submit"]' );
		const restore = utils?.setSubmitting ? utils.setSubmitting( submitButton, 'Logging in…' ) : () => {};

		try {
			const url = new URL( 'extrachill/v1/auth/login', utils.getRestRoot() );
			const response = await fetch( url.toString(), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					identifier,
					password,
					turnstile_response: turnstileResponse,
					device_id: deviceId,
					remember,
					set_cookie: true,
					device_name: 'Web',
					redirect_to: redirectTo,
				} ),
			} );

			const data = await response.json();
			if ( ! response.ok ) {
				throw new Error( data?.message || 'Login failed. Please try again.' );
			}

			// Two-Factor Authentication: redirect to the 2FA challenge page.
			if ( data?.requires_2fa && data?.redirect_url ) {
				window.location.assign( data.redirect_url );
				return;
			}

			window.location.assign( data?.redirect_url || config.loginRedirectUrl );
		} catch ( error ) {
			const message = error instanceof Error ? error.message : 'Login failed. Please try again.';
			setNotice( {
				type: 'error',
				message,
				html: ` ${ message } <a href="${ config.resetPasswordUrl }">Forgot your password?</a>`,
			} );
			restore();

			if ( turnstileWidget && window.turnstile ) {
				window.turnstile.reset( turnstileWidget );
			}
		}
	};

	return (
		<Panel>
			<div className="login-register-form" ref={ panelRef }>
				{ notice && (
					<div className={ `ec-auth-notice ec-auth-notice--${ notice.type }` }>
						<p dangerouslySetInnerHTML={ notice.html ? { __html: notice.html } : undefined }>
							{ notice.html ? undefined : notice.message }
						</p>
					</div>
				) }
				<form id="loginform" onSubmit={ handleSubmit }>
					<input type="hidden" name="redirect_to" value={ config.loginRedirectUrl } />
					<input type="hidden" name="success_redirect_url" value={ config.successRedirectUrl } />
					<label htmlFor="user_login">Username</label>
					<input type="text" name="log" id="user_login" className="input" placeholder="Your username" required />
					<label htmlFor="user_pass">Password</label>
					<input type="password" name="pwd" id="user_pass" className="input" placeholder="Your password" required />
					<div className="login-remember-me">
						<label htmlFor="rememberme">
							<input type="checkbox" id="rememberme" name="rememberme" value="forever" />
							Remember me
						</label>
					</div>
					<input type="submit" className="button-2 button-medium" value="Log In" />
					<div className="login-register-turnstile" dangerouslySetInnerHTML={ { __html: config.turnstileHtml } } />
					<div className="login-forgot-password">
						<a href={ config.resetPasswordUrl }>Forgot your password?</a>
					</div>
				</form>
				{ config.googleOAuthEnabled && <GoogleButtons redirectUrl={ config.googleSignInRedirectUrl } /> }
				<p className="login-register-prompt">
					Don&apos;t have an account? <a href="#tab-register">Register here</a>
				</p>
			</div>
		</Panel>
	);
}

export function RegisterPanel( { config, notice, setNotice } ) {
	const panelRef = useRef( null );

	useEffect( () => {
		renderTurnstile( panelRef.current );
	} );

	const handleSubmit = async ( event ) => {
		event.preventDefault();
		setNotice( null );

		const form = event.currentTarget;
		const formData = new window.FormData( form );
		const email = String( formData.get( 'extrachill_email' ) || '' ).trim();
		const password = String( formData.get( 'extrachill_password' ) || '' );
		const passwordConfirm = String( formData.get( 'extrachill_password_confirm' ) || '' );

		if ( ! email || ! password || ! passwordConfirm ) {
			setNotice( { type: 'error', message: 'All fields are required.' } );
			return;
		}

		const turnstileResponse = String( formData.get( 'cf-turnstile-response' ) || '' );
		const turnstileWidget = form.querySelector( '.cf-turnstile' );
		if ( turnstileWidget && ! turnstileResponse ) {
			setNotice( { type: 'error', message: 'Captcha verification required. Please complete the challenge and try again.' } );
			return;
		}

		const utils = window.ECAuthUtils;
		const deviceId = utils?.getDeviceId ? utils.getDeviceId() : '';
		if ( ! deviceId ) {
			setNotice( { type: 'error', message: 'Unable to generate a device ID.' } );
			return;
		}

		const inviteToken = String( formData.get( 'invite_token' ) || '' );
		const inviteArtistId = Number( formData.get( 'invite_artist_id' ) || 0 );
		const submitButton = form.querySelector( 'input[type="submit"], button[type="submit"]' );
		const restore = utils?.setSubmitting ? utils.setSubmitting( submitButton, 'Creating account…' ) : () => {};
		const fromJoin = Boolean( config.fromJoin );
		const { referrer, utm } = captureAttribution();

		try {
			const url = new URL( 'extrachill/v1/auth/register', utils.getRestRoot() );
			const response = await fetch( url.toString(), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					email,
					password,
					password_confirm: passwordConfirm,
					turnstile_response: turnstileResponse,
					device_id: deviceId,
					device_name: 'Web',
					set_cookie: true,
					remember: true,
					registration_page: config.currentUrl,
					registration_source: 'web',
					registration_method: 'standard',
					success_redirect_url: config.successRedirectUrl,
					invite_token: inviteToken,
					invite_artist_id: inviteArtistId,
					from_join: fromJoin,
					referrer,
					utm,
				} ),
			} );

			const data = await response.json();
			if ( ! response.ok ) {
				throw new Error( data?.message || 'Registration failed. Please try again.' );
			}

			window.location.assign( data?.redirect_url || config.successRedirectUrl || window.location.href );
		} catch ( error ) {
			setNotice( {
				type: 'error',
				message: error instanceof Error ? error.message : 'Registration failed. Please try again.',
			} );
			restore();

			if ( turnstileWidget && window.turnstile ) {
				window.turnstile.reset( turnstileWidget );
			}
		}
	};

	return (
		<Panel>
			<div className="login-register-form" ref={ panelRef }>
				{ notice && (
					<div className={ `ec-auth-notice ec-auth-notice--${ notice.type }` }>
						<p>{ notice.message }</p>
					</div>
				) }
				<form onSubmit={ handleSubmit }>
					<input type="hidden" name="success_redirect_url" value={ config.successRedirectUrl } />
					{ config.inviteToken && <input type="hidden" name="invite_token" value={ config.inviteToken } /> }
					{ config.inviteArtistId ? <input type="hidden" name="invite_artist_id" value={ config.inviteArtistId } /> : null }
					<label htmlFor="extrachill_email">Email</label>
					<input type="email" name="extrachill_email" id="extrachill_email" placeholder="you@example.com" required defaultValue={ config.invitedEmail } />
					<label htmlFor="extrachill_password">Password</label>
					<input type="password" name="extrachill_password" id="extrachill_password" placeholder="Create a password" required minLength={ 8 } />
					<label htmlFor="extrachill_password_confirm">Confirm Password</label>
					<input type="password" name="extrachill_password_confirm" id="extrachill_password_confirm" placeholder="Repeat your password" required minLength={ 8 } />
					<div className="registration-submit-section">
						<input type="submit" name="extrachill_register" className="button-1 button-medium" value="Join Now" />
					</div>
					<div className="login-register-turnstile" dangerouslySetInnerHTML={ { __html: config.turnstileHtml } } />
				</form>
				{ config.googleOAuthEnabled && <GoogleButtons redirectUrl={ config.googleSignInRedirectUrl } registration={ true } /> }
			</div>
		</Panel>
	);
}

function isGoogleIdentityReady() {
	return Boolean( window.google && window.google.accounts && window.google.accounts.id );
}

/**
 * Wait for Google Identity Services library to load.
 *
 * The gsi/client script is enqueued in the footer alongside our view.js, and on
 * cold loads it may still be in-flight when LoginRegisterApp mounts. Without
 * waiting, ECGoogleSignIn.init() bails early and the user sees an empty button
 * slot until they hard-refresh. Resolves immediately when ready, listens for the
 * script's load event when possible, and falls back to short polling.
 *
 * @param {number} timeoutMs Maximum wait time before giving up.
 * @return {Promise<boolean>} Resolves true if ready, false on timeout.
 */
function waitForGoogleIdentity( timeoutMs = 5000 ) {
	return new Promise( ( resolve ) => {
		if ( isGoogleIdentityReady() ) {
			resolve( true );
			return;
		}

		let settled = false;
		const finish = ( ok ) => {
			if ( settled ) {
				return;
			}
			settled = true;
			resolve( ok );
		};

		const scriptEl = document.querySelector( 'script[src*="accounts.google.com/gsi/client"]' );
		if ( scriptEl ) {
			scriptEl.addEventListener( 'load', () => finish( isGoogleIdentityReady() ), { once: true } );
			scriptEl.addEventListener( 'error', () => finish( false ), { once: true } );
		}

		const pollMs = 100;
		let elapsed = 0;
		const interval = window.setInterval( () => {
			if ( isGoogleIdentityReady() ) {
				window.clearInterval( interval );
				finish( true );
				return;
			}
			elapsed += pollMs;
			if ( elapsed >= timeoutMs ) {
				window.clearInterval( interval );
				finish( false );
			}
		}, pollMs );
	} );
}

export function LoginRegisterApp( { config } ) {
	const [ activeTab, setActiveTab ] = useState( 'login' );
	const [ loginNotice, setLoginNotice ] = useState( null );
	const [ registerNotice, setRegisterNotice ] = useState(
		config.initialNotice ? { type: config.initialNotice.type, message: config.initialNotice.message } : null
	);
	const [ googleReady, setGoogleReady ] = useState( () => isGoogleIdentityReady() );

	useEffect( () => {
		if ( ! config.googleOAuthEnabled ) {
			return;
		}

		let cancelled = false;
		const initWhenReady = async () => {
			const ready = await waitForGoogleIdentity();
			if ( cancelled ) {
				return;
			}
			if ( ! ready ) {
				return;
			}
			setGoogleReady( true );
			if ( window.ECGoogleSignIn && window.ecGoogleConfig ) {
				window.ECGoogleSignIn.init( window.ecGoogleConfig );
			}
		};

		initWhenReady();

		return () => {
			cancelled = true;
		};
	}, [ config.googleOAuthEnabled ] );

	useEffect( () => {
		if ( ! googleReady ) {
			return;
		}
		if ( window.ECGoogleSignIn && typeof window.ECGoogleSignIn.renderAllButtons === 'function' ) {
			window.ECGoogleSignIn.renderAllButtons();
		}
	}, [ activeTab, googleReady ] );

	const tabs = useMemo(
		() => [
			{ id: 'login', label: 'Login' },
			{ id: 'register', label: 'Register' },
		],
		[]
	);

	if ( config.loggedIn ) {
		return <LoggedInCard config={ config } />;
	}

	return (
		<BlockShell>
			<BlockShellInner maxWidth="narrow">
				<ResponsiveTabs
					tabs={ tabs }
					active={ activeTab }
					onChange={ setActiveTab }
					renderPanel={ ( id ) =>
						id === 'login' ? (
							<LoginPanel config={ config } notice={ loginNotice } setNotice={ setLoginNotice } />
						) : (
							<RegisterPanel config={ config } notice={ registerNotice } setNotice={ setRegisterNotice } />
						)
					}
					showDesktopTabs={ true }
					syncWithHash={ true }
				/>
			</BlockShellInner>
		</BlockShell>
	);
}

function init() {
	const container = document.querySelector( '[data-ec-login-register-root]' );
	if ( ! container ) {
		return;
	}

	const config = JSON.parse( container.dataset.ecLoginRegisterConfig || '{}' );
	createRoot( container ).render( <LoginRegisterApp config={ config } /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
