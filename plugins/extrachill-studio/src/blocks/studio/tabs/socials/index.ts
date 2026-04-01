import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useState } from '@wordpress/element';
import type { ReactElement } from 'react';
import { Badge, InlineStatus, Panel, PanelHeader } from '@extrachill/components';
import type { SocialPlatformsResponse } from '@extrachill/api-client';

import { studioClient } from '../../app/client';
import type { StudioPaneProps } from '../../types/studio';
import PlatformPublishPane from './publish';
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
	config: SocialPlatformConfig;
}

const SocialsPane = ( _props: StudioPaneProps ): ReactElement | null => {
	const [ platforms, setPlatforms ] = useState< SocialPlatformsResponse >( {} );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ activePlatform, setActivePlatform ] = useState< string | null >( null );

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
			config: platformConfig || { label: slug },
		};
	} );

	const connectedPlatforms = availablePlatforms.filter( ( item ) => item.authenticated );
	const publishablePlatforms = connectedPlatforms.filter( ( item ) => item.type !== 'fetch' );

	// Auto-select first publishable platform if none is active.
	useEffect( () => {
		if ( ! activePlatform && publishablePlatforms.length > 0 ) {
			setActivePlatform( publishablePlatforms[ 0 ].slug );
		}
	}, [ activePlatform, publishablePlatforms.length ] );

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

	const selectedPlatform = publishablePlatforms.find( ( p ) => p.slug === activePlatform ) || null;

	return h(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--socials' },
		h(
			'div',
			{ className: 'ec-studio-pane__grid' },
			h(
				PanelView,
				{ className: 'ec-studio-panel', compact: true },
				h( PanelHeader, {
					description: sprintf(
						__( '%d platform(s) connected, %d publish-capable. Select a platform below to compose and publish.', 'extrachill-studio' ),
						connectedPlatforms.length,
						publishablePlatforms.length
					),
				} ),
				createElement(
					'ul',
					{ className: 'ec-studio-social-platforms' },
					...availablePlatforms.map( ( item ) => createElement(
						'li',
						{
							key: item.slug,
							className: [
								'ec-studio-social-platforms__item',
								item.authenticated && item.type !== 'fetch' ? 'ec-studio-social-platforms__item--clickable' : '',
								activePlatform === item.slug ? 'ec-studio-social-platforms__item--active' : '',
							].filter( Boolean ).join( ' ' ),
							onClick: item.authenticated && item.type !== 'fetch'
								? () => setActivePlatform( item.slug )
								: undefined,
							role: item.authenticated && item.type !== 'fetch' ? 'button' : undefined,
							tabIndex: item.authenticated && item.type !== 'fetch' ? 0 : undefined,
						},
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
				createElement( 'p', null, __( 'Connected platforms appear automatically when authenticated in Data Machine. Select a platform from the list to compose and publish content.', 'extrachill-studio' ) ),
				publishablePlatforms.length === 0
					? h( InlineStatusView, { tone: 'warning', className: 'ec-studio-message' }, __( 'No publish-capable platforms are connected yet. Authenticate a platform in Data Machine Socials to get started.', 'extrachill-studio' ) )
					: null
			)
		),
		selectedPlatform
			? h( PlatformPublishPane, {
				key: selectedPlatform.slug,
				slug: selectedPlatform.slug,
				label: selectedPlatform.label,
				username: selectedPlatform.username,
				config: selectedPlatform.config,
			} )
			: null
	);
};

export default SocialsPane;
