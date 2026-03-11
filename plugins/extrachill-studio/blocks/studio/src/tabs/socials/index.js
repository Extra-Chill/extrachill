import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useState } from '@wordpress/element';

import { studioClient } from '../../app/client';
import InstagramPane from './instagram';

const SocialsPane = () => {
	const [ authStatuses, setAuthStatuses ] = useState( [] );
	const [ authError, setAuthError ] = useState( '' );
	const [ isCheckingAuth, setIsCheckingAuth ] = useState( true );

	useEffect( () => {
		const loadAuthStatus = async () => {
			setIsCheckingAuth( true );
			setAuthError( '' );

			try {
				const statuses = await studioClient.socials.getAuthStatus();
				setAuthStatuses( Array.isArray( statuses ) ? statuses : [] );
			} catch ( fetchError ) {
				setAuthStatuses( [] );
				setAuthError( fetchError?.message || __( 'Unable to load Instagram auth status.', 'extrachill-studio' ) );
			} finally {
				setIsCheckingAuth( false );
			}
		};

		loadAuthStatus();
	}, [] );

	const connectedPlatforms = authStatuses.filter( ( statusItem ) => statusItem.authenticated );
	const availablePlatforms = authStatuses.length > 0 ? authStatuses : [
		{ platform: 'instagram', authenticated: false, username: null },
		{ platform: 'threads', authenticated: false, username: null },
		{ platform: 'facebook', authenticated: false, username: null },
		{ platform: 'bluesky', authenticated: false, username: null },
		{ platform: 'pinterest', authenticated: false, username: null },
	];

	return createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--socials' },
		createElement(
			'div',
			{ className: 'ec-studio-pane__grid' },
			createElement(
				'div',
				{ className: 'ec-studio-panel' },
				createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Socials overview', 'extrachill-studio' ) ),
				createElement( 'h3', null, __( 'Studio treats socials as one app area', 'extrachill-studio' ) ),
				createElement( 'p', null, __( 'The Studio block stays whole while each social workflow remains modular inside it. Instagram is the first live path, and the rest can slot in here next.', 'extrachill-studio' ) ),
				createElement(
					'ul',
					{ className: 'ec-studio-social-platforms' },
					...availablePlatforms.map( ( statusItem ) => createElement(
						'li',
						{ key: statusItem.platform, className: 'ec-studio-social-platforms__item' },
						createElement( 'span', { className: 'ec-studio-social-platforms__name' }, statusItem.platform ),
						createElement(
							'span',
							{ className: `ec-studio-social-platforms__status ${ statusItem.authenticated ? 'is-connected' : 'is-disconnected' }` },
							statusItem.authenticated
								? sprintf( __( 'Connected as @%s', 'extrachill-studio' ), statusItem.username || 'unknown' )
								: __( 'Not connected yet', 'extrachill-studio' )
						)
					) )
				)
			),
			createElement(
				'div',
				{ className: 'ec-studio-panel' },
				createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Current focus', 'extrachill-studio' ) ),
				createElement( 'h3', null, __( 'Instagram is the first live workflow', 'extrachill-studio' ) ),
				createElement( 'p', null, __( 'The backend is already authenticated and ready, so this tab focuses on getting the first real operator flow in place before expanding to other networks.', 'extrachill-studio' ) ),
				createElement(
					'ul',
					null,
					createElement( 'li', null, sprintf( __( '%d connected platform(s) detected.', 'extrachill-studio' ), connectedPlatforms.length ) ),
					createElement( 'li', null, __( 'Instagram publishing is wired now.', 'extrachill-studio' ) ),
					createElement( 'li', null, __( 'Additional platform panes can be added here without splitting the Studio block apart.', 'extrachill-studio' ) )
				)
			)
		),
		createElement( InstagramPane )
	);
};

export default SocialsPane;
