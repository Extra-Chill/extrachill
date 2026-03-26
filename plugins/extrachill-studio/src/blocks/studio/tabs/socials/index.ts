import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useState } from '@wordpress/element';
import type { ReactElement } from 'react';
import { Badge, InlineStatus, Panel, PanelHeader } from '@extrachill/components';
import type { SocialPlatformsResponse } from '@extrachill/api-client';

import { studioClient } from '../../app/client';
import type { StudioPaneProps } from '../../types/studio';
import InstagramPane from './instagram';
import type { SocialPlatformConfig } from '../../types/externals';

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;
const BadgeView = Badge as unknown as ( props: any ) => ReactElement;

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

	const availablePlatforms: PlatformEntry[] = Object.entries( platforms ).map( ( [ slug, config ] ) => {
		const platformConfig = config as SocialPlatformConfig | undefined;

		return {
		slug,
		label: platformConfig?.label || slug,
		authenticated: platformConfig?.authenticated || false,
		username: platformConfig?.username || null,
		type: platformConfig?.type || 'publish',
		};
	} );

	const connectedPlatforms = availablePlatforms.filter( ( item ) => item.authenticated );
	const publishPlatforms = availablePlatforms.filter( ( item ) => item.type !== 'fetch' );

	if ( isLoading ) {
		return h(
			'div',
			{ className: 'ec-studio-pane ec-studio-pane--socials' },
				h(
					PanelView,
					{ className: 'ec-studio-panel', compact: true },
					h( InlineStatusView, { tone: 'info', className: 'ec-studio-message' }, __( 'Loading social platforms…', 'extrachill-studio' ) )
			)
		);
	}

	if ( error ) {
		return h(
			'div',
			{ className: 'ec-studio-pane ec-studio-pane--socials' },
				h(
					PanelView,
					{ className: 'ec-studio-panel', compact: true },
					h( InlineStatusView, { tone: 'error', className: 'ec-studio-message' }, error )
			)
		);
	}

	return h(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--socials' },
		h(
			'div',
			{ className: 'ec-studio-pane__grid' },
				h(
					PanelView,
				{ className: 'ec-studio-panel', compact: true },
				h( PanelHeader, null ),
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
								? h( BadgeView, { tone: 'muted', variant: 'outline', className: 'ec-studio-social-platforms__badge' }, __( 'read-only', 'extrachill-studio' ) )
								: null
						),
						createElement(
							BadgeView,
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
				h(
					PanelView,
				{ className: 'ec-studio-panel', compact: true },
				h( PanelHeader, null ),
				createElement( 'p', null, __( 'Each connected platform has its own publishing workflow below. New platforms appear automatically when connected in Data Machine.', 'extrachill-studio' ) ),
				createElement(
					'ul',
					null,
					createElement( 'li', null, sprintf( __( '%d of %d platform(s) connected.', 'extrachill-studio' ), connectedPlatforms.length, availablePlatforms.length ) ),
					createElement( 'li', null, __( 'Instagram — publish posts, manage comments, submit drafts for review.', 'extrachill-studio' ) )
				)
			)
		),
		h( InstagramPane )
	);
};

export default SocialsPane;
