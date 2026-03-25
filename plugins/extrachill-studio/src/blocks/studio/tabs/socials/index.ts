import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useState } from '@wordpress/element';
import type { ReactElement } from 'react';
import { Badge, InlineStatus, Panel, PanelHeader } from '@extrachill/components';
import type { SocialPlatformsResponse } from '@extrachill/api-client';

import { studioClient } from '../../app/client';
import type { StudioPaneProps } from '../../types/studio';
import InstagramPane from './instagram';

interface PlatformEntry {
	slug: string;
	label: string;
	authenticated: boolean;
	username: string | null;
	type: string;
}

const SocialsPane = ( _props: StudioPaneProps ): ReactElement | null => {
	const [ platforms, setPlatforms ] = useState< SocialPlatformsResponse >( {} );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );

	useEffect( () => {
		const loadPlatforms = async (): Promise< void > => {
			setIsLoading( true );
			setError( '' );

			try {
				const data = await studioClient.socials.getPlatforms();
				setPlatforms( data && typeof data === 'object' ? data : {} );
			} catch ( fetchError ) {
				setPlatforms( {} );
				setError( ( fetchError as Error )?.message || __( 'Unable to load social platforms.', 'extrachill-studio' ) );
			} finally {
				setIsLoading( false );
			}
		};

		loadPlatforms();
	}, [] );

	const availablePlatforms: PlatformEntry[] = Object.entries( platforms ).map( ( [ slug, config ] ) => ( {
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
				Panel,
				{ className: 'ec-studio-panel', compact: true },
				createElement( InlineStatus, { tone: 'info', className: 'ec-studio-message' }, __( 'Loading social platforms…', 'extrachill-studio' ) )
			)
		);
	}

	if ( error ) {
		return createElement(
			'div',
			{ className: 'ec-studio-pane ec-studio-pane--socials' },
			createElement(
				Panel,
				{ className: 'ec-studio-panel', compact: true },
				createElement( InlineStatus, { tone: 'error', className: 'ec-studio-message' }, error )
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
				Panel,
				{ className: 'ec-studio-panel', compact: true },
				createElement( PanelHeader, { title: sprintf( __( '%d platforms available', 'extrachill-studio' ), publishPlatforms.length ) } ),
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
								? createElement( Badge, { tone: 'muted', variant: 'outline', className: 'ec-studio-social-platforms__badge' }, __( 'read-only', 'extrachill-studio' ) )
								: null
						),
						createElement(
							Badge,
							{
								tone: item.authenticated ? 'success' : 'muted',
								variant: item.authenticated ? 'subtle' : 'outline',
								className: 'ec-studio-social-platforms__status',
							},
							item.authenticated
								? sprintf( __( 'Connected as @%s', 'extrachill-studio' ), item.username || 'unknown' )
								: __( 'Not connected', 'extrachill-studio' )
						)
					) )
				)
			),
			createElement(
				Panel,
				{ className: 'ec-studio-panel', compact: true },
				createElement( PanelHeader, { title: __( 'Publishing workflows', 'extrachill-studio' ) } ),
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
