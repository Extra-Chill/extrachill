import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useState } from '@wordpress/element';

import { studioClient } from '../../app/client';
import InstagramPane from './instagram';

const SocialsPane = () => {
	const [ platforms, setPlatforms ] = useState( {} );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );

	useEffect( () => {
		const loadPlatforms = async () => {
			setIsLoading( true );
			setError( '' );

			try {
				const data = await studioClient.socials.getPlatforms();
				setPlatforms( data && typeof data === 'object' ? data : {} );
			} catch ( fetchError ) {
				setPlatforms( {} );
				setError( fetchError?.message || __( 'Unable to load social platforms.', 'extrachill-studio' ) );
			} finally {
				setIsLoading( false );
			}
		};

		loadPlatforms();
	}, [] );

	const availablePlatforms = Object.entries( platforms ).map( ( [ slug, config ] ) => ( {
		slug,
		label: config?.label || slug,
		authenticated: config?.authenticated || false,
		username: config?.username || null,
		type: config?.type || 'publish',
	} ) );

	const connectedPlatforms = availablePlatforms.filter( ( item ) => item.authenticated );
	const publishPlatforms = availablePlatforms.filter( ( item ) => item.type !== 'fetch' );

	if ( isLoading ) {
		return createElement(
			'div',
			{ className: 'ec-studio-pane ec-studio-pane--socials' },
			createElement(
				'div',
				{ className: 'ec-studio-panel' },
				createElement( 'p', { className: 'ec-studio-message ec-studio-message--info' }, __( 'Loading social platforms…', 'extrachill-studio' ) )
			)
		);
	}

	if ( error ) {
		return createElement(
			'div',
			{ className: 'ec-studio-pane ec-studio-pane--socials' },
			createElement(
				'div',
				{ className: 'ec-studio-panel' },
				createElement( 'p', { className: 'ec-studio-message ec-studio-message--error' }, error )
			)
		);
	}

	return createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--socials' },
		createElement(
			'div',
			{ className: 'ec-studio-pane__grid' },
			createElement(
				'div',
				{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Platforms', 'extrachill-studio' ) ),
			createElement( 'h3', null, sprintf( __( '%d platforms available', 'extrachill-studio' ), publishPlatforms.length ) ),
			createElement( 'p', null, sprintf( __( '%d connected, %d publish-capable.', 'extrachill-studio' ), connectedPlatforms.length, publishPlatforms.length ) ),
				createElement(
					'ul',
					{ className: 'ec-studio-social-platforms' },
					...availablePlatforms.map( ( item ) => createElement(
						'li',
						{ key: item.slug, className: 'ec-studio-social-platforms__item' },
						createElement(
							'span',
							{ className: 'ec-studio-social-platforms__name' },
							item.label,
							item.type === 'fetch'
								? createElement( 'span', { className: 'ec-studio-social-platforms__badge' }, __( 'read-only', 'extrachill-studio' ) )
								: null
						),
						createElement(
							'span',
							{ className: `ec-studio-social-platforms__status ${ item.authenticated ? 'is-connected' : 'is-disconnected' }` },
							item.authenticated
								? sprintf( __( 'Connected as @%s', 'extrachill-studio' ), item.username || 'unknown' )
								: __( 'Not connected', 'extrachill-studio' )
						)
					) )
				)
			),
			createElement(
				'div',
				{ className: 'ec-studio-panel' },
			createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Workflows', 'extrachill-studio' ) ),
			createElement( 'h3', null, __( 'Publishing workflows', 'extrachill-studio' ) ),
			createElement( 'p', null, __( 'Each connected platform has its own publishing workflow below. New platforms appear automatically when connected in Data Machine.', 'extrachill-studio' ) ),
			createElement(
				'ul',
				null,
				createElement( 'li', null, sprintf( __( '%d of %d platform(s) connected.', 'extrachill-studio' ), connectedPlatforms.length, availablePlatforms.length ) ),
				createElement( 'li', null, __( 'Instagram — publish posts, manage comments, submit drafts for review.', 'extrachill-studio' ) )
			)
			)
		),
		createElement( InstagramPane )
	);
};

export default SocialsPane;
