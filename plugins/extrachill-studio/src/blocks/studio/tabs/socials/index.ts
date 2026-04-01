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

const SocialsPane = ( { context }: StudioPaneProps ): ReactElement | null => {
	const allowedSlugs = context.socialPlatforms;
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

	// Only show authenticated, publish-capable platforms. Event scrapers,
	// read-only fetchers, and unauthenticated platforms are hidden entirely.
	// If an allowlist is configured via the extrachill_studio_social_platforms
	// filter, only those slugs are shown.
	const publishablePlatforms = availablePlatforms.filter( ( item ) => {
		if ( ! item.authenticated || item.type === 'fetch' ) {
			return false;
		}
		if ( allowedSlugs.length > 0 && ! allowedSlugs.includes( item.slug ) ) {
			return false;
		}
		return true;
	} );

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
					description: publishablePlatforms.length > 0
						? __( 'Select a platform to compose and publish.', 'extrachill-studio' )
						: __( 'No social platforms are connected yet.', 'extrachill-studio' ),
				} ),
				publishablePlatforms.length > 0
					? createElement(
						'ul',
						{ className: 'ec-studio-social-platforms' },
						...publishablePlatforms.map( ( item ) => createElement(
							'li',
							{
								key: item.slug,
								className: [
									'ec-studio-social-platforms__item',
									'ec-studio-social-platforms__item--clickable',
									activePlatform === item.slug ? 'ec-studio-social-platforms__item--active' : '',
								].filter( Boolean ).join( ' ' ),
								onClick: () => setActivePlatform( item.slug ),
								role: 'button',
								tabIndex: 0,
							},
							createElement(
								'span',
								{ className: 'ec-studio-social-platforms__name' },
								item.label
							),
							createElement(
								BadgeView,
								{
									tone: 'success',
									variant: 'subtle',
									className: 'ec-studio-social-platforms__status',
								},
								sprintf( __( '@%s', 'extrachill-studio' ), item.username || 'unknown' )
							)
						) )
					)
					: null
			),
			publishablePlatforms.length === 0
				? h(
					PanelView,
					{ className: 'ec-studio-panel', compact: true },
					h( InlineStatusView, { tone: 'warning', className: 'ec-studio-message' }, __( 'Authenticate a social platform in Data Machine Socials to get started.', 'extrachill-studio' ) )
				)
				: null
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
