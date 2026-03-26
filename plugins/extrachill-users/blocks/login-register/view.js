import { useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BlockShell, BlockShellHeader, BlockShellInner, Panel, ResponsiveTabs } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';

function GoogleButtons() {
	return (
		<>
			<div className="social-login-divider">
				<span>or</span>
			</div>
			<div className="social-login-buttons">
				<div className="google-signin-button"></div>
			</div>
		</>
	);
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
		<BlockShell className="login-already-logged-in-card">
			<BlockShellInner maxWidth="narrow">
				<Panel className="login-already-logged-in-card__panel">
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

function LoginPanel( { config, notice, setNotice } ) {
	const handleSubmit = async ( event ) => {
		event.preventDefault();
		setNotice( null );

		const form = event.currentTarget;
		const formData = new window.FormData( form );
		const identifier = String( formData.get( 'log' ) || '' ).trim();
		const password = String( formData.get( 'pwd' ) || '' );
		const remember = formData.get( 'rememberme' ) === 'forever';
		const redirectTo = String( formData.get( 'redirect_to' ) || window.location.href );

		if ( ! identifier || ! password ) {
			setNotice( { type: 'error', message: 'Username and password are required.' } );
			return;
		}

		const utils = window.ECAuthUtils;
		const deviceId = utils?.getDeviceId ? utils.getDeviceId() : '';
		if ( ! deviceId ) {
			setNotice( { type: 'error', message: 'Unable to generate a device ID.' } );
			return;
		}

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
					device_id: deviceId,
					remember,
					set_cookie: true,
					device_name: 'Web',
				} ),
			} );

			const data = await response.json();
			if ( ! response.ok ) {
				throw new Error( data?.message || 'Login failed. Please try again.' );
			}

			window.location.assign( redirectTo );
		} catch ( error ) {
			const message = error instanceof Error ? error.message : 'Login failed. Please try again.';
			setNotice( {
				type: 'error',
				message,
				html: ` ${ message } <a href="${ config.resetPasswordUrl }">Forgot your password?</a>`,
			} );
			restore();
		}
	};

	return (
		<Panel>
			<div className="login-register-form">
				{ notice && (
					<div className={ `ec-auth-notice ec-auth-notice--${ notice.type }` }>
						<p dangerouslySetInnerHTML={ notice.html ? { __html: notice.html } : undefined }>
							{ notice.html ? undefined : notice.message }
						</p>
					</div>
				) }
				<form id="loginform" onSubmit={ handleSubmit }>
					<input type="hidden" name="redirect_to" value={ config.loginRedirectUrl } />
					<label htmlFor="user_login">Username</label>
					<input type="text" name="log" id="user_login" className="input" placeholder="Your username" required />
					<label htmlFor="user_pass">Password</label>
					<input type="password" name="pwd" id="user_pass" className="input" placeholder="Your password" required />
					<div className="login-remember-me">
						<label>
							<input type="checkbox" name="rememberme" value="forever" />
							Remember me
						</label>
					</div>
					<input type="submit" className="button-2 button-medium" value="Log In" />
					<div className="login-forgot-password">
						<a href={ config.resetPasswordUrl }>Forgot your password?</a>
					</div>
				</form>
				{ config.googleOAuthEnabled && <GoogleButtons /> }
				<p className="login-register-prompt">
					Don't have an account? <a href="#tab-register">Register here</a>
				</p>
			</div>
		</Panel>
	);
}

function RegisterPanel( { config, notice, setNotice } ) {
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
		const turnstileResponse = String( formData.get( 'cf-turnstile-response' ) || '' );
		const inviteToken = String( formData.get( 'invite_token' ) || '' );
		const inviteArtistId = Number( formData.get( 'invite_artist_id' ) || 0 );

		if ( ! email || ! password || ! passwordConfirm ) {
			setNotice( { type: 'error', message: 'All fields are required.' } );
			return;
		}

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

		const submitButton = form.querySelector( 'input[type="submit"], button[type="submit"]' );
		const restore = utils?.setSubmitting ? utils.setSubmitting( submitButton, 'Creating account…' ) : () => {};
		const fromJoin = new URL( window.location.href ).searchParams.get( 'from_join' ) === 'true';

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

			const turnstileWidget = form.querySelector( '.cf-turnstile' );
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
				{ config.googleOAuthEnabled && <GoogleButtons /> }
			</div>
		</Panel>
	);
}

function LoginRegisterApp( { config } ) {
	const [ activeTab, setActiveTab ] = useState( 'login' );
	const [ loginNotice, setLoginNotice ] = useState( null );
	const [ registerNotice, setRegisterNotice ] = useState(
		config.initialNotice ? { type: config.initialNotice.type, message: config.initialNotice.message } : null
	);

	useEffect( () => {
		if ( window.ECGoogleSignIn && window.ecGoogleConfig ) {
			window.ECGoogleSignIn.init( window.ecGoogleConfig );
		}
	}, [] );

	useEffect( () => {
		if ( window.ECGoogleSignIn && typeof window.ECGoogleSignIn.renderAllButtons === 'function' ) {
			window.ECGoogleSignIn.renderAllButtons();
		}
	}, [ activeTab ] );

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
		<BlockShell className="login-register-shell">
			<BlockShellInner maxWidth="narrow">
				<BlockShellHeader
					title="Login or Register"
					description="Access the Extra Chill community and artist platform."
				/>
				<ResponsiveTabs
					className="login-register-shell__tabs"
					innerMaxWidth="narrow"
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
					tabsClassName="ec-shell-tabs"
					hashPrefix="tab-"
					syncWithHash
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
