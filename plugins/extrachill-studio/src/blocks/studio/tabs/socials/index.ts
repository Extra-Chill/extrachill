import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useMemo, useState } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import { InlineStatus, Panel, PanelHeader, Tabs, Toolbar } from '@extrachill/components';
import type { SocialPlatformsResponse } from '@extrachill/api-client';
import type { TabItem } from '@extrachill/components';

import { studioClient } from '../../app/client';
import type { StudioPaneProps } from '../../types/studio';
import type { SocialPlatformConfig } from '../../types/externals';
import PlatformPublishPane from './publish';
import GiveawayView from '../giveaway';

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;
const ToolbarView = Toolbar as unknown as ( props: any ) => ReactElement;
const TabsView = Tabs as unknown as ( props: any ) => ReactElement;

interface PlatformEntry {
	slug: string;
	label: string;
	authenticated: boolean;
	username: string | null;
	type: string;
	config: SocialPlatformConfig;
}

/** Views available per platform. Giveaway only shows for Instagram. */
const getViewsForPlatform = ( platformSlug: string | null ): TabItem[] => {
	const views: TabItem[] = [
		{ id: 'publish', label: __( 'Publish', 'extrachill-studio' ) },
	];

	if ( platformSlug === 'instagram' ) {
		views.push( { id: 'giveaway', label: __( 'Giveaway', 'extrachill-studio' ) } );
	}

	return views;
};

const SocialsPane = ( { context }: StudioPaneProps ): ReactElement | null => {
	const allowedSlugs = context.socialPlatforms;
	const [ platforms, setPlatforms ] = useState< SocialPlatformsResponse >( {} );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ activePlatform, setActivePlatform ] = useState< string | null >( null );
	const [ activeView, setActiveView ] = useState( 'publish' );

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

	const publishablePlatforms: PlatformEntry[] = useMemo( () => {
		const available = Object.entries( platforms ).map( ( [ slug, config ] ) => {
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

		return available.filter( ( item ) => {
			if ( ! item.authenticated || item.type === 'fetch' ) {
				return false;
			}
			if ( allowedSlugs.length > 0 && ! allowedSlugs.includes( item.slug ) ) {
				return false;
			}
			return true;
		} );
	}, [ platforms, allowedSlugs ] );

	// Auto-select first publishable platform.
	useEffect( () => {
		if ( ! activePlatform && publishablePlatforms.length > 0 ) {
			setActivePlatform( publishablePlatforms[ 0 ].slug );
		}
	}, [ activePlatform, publishablePlatforms.length ] );

	// Reset view to publish if the active view is not available for the new platform.
	useEffect( () => {
		const views = getViewsForPlatform( activePlatform );
		if ( ! views.some( ( v ) => v.id === activeView ) ) {
			setActiveView( 'publish' );
		}
	}, [ activePlatform ] );

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

	if ( publishablePlatforms.length === 0 ) {
		return h(
			'div',
			{ className: 'ec-studio-pane ec-studio-pane--socials' },
			h(
				PanelView,
				{ className: 'ec-studio-panel', compact: true },
				h( PanelHeader, {
					description: __( 'No social platforms are connected yet.', 'extrachill-studio' ),
				} ),
				h( InlineStatusView, { tone: 'warning', className: 'ec-studio-message' }, __( 'Authenticate a social platform in Data Machine Socials to get started.', 'extrachill-studio' ) )
			)
		);
	}

	const selectedPlatform = publishablePlatforms.find( ( p ) => p.slug === activePlatform ) || publishablePlatforms[ 0 ];
	const views = getViewsForPlatform( selectedPlatform.slug );

	const handlePlatformChange = ( event: ChangeEvent< HTMLSelectElement > ): void => {
		setActivePlatform( event.target.value );
	};

	// Platform dropdown for the toolbar's actions slot.
	const platformDropdown = createElement(
		'select',
		{
			className: 'ec-toolbar__select',
			value: selectedPlatform.slug,
			onChange: handlePlatformChange,
			'aria-label': __( 'Select platform', 'extrachill-studio' ),
		},
		...publishablePlatforms.map( ( item ) =>
			createElement( 'option', { key: item.slug, value: item.slug },
				sprintf( '%s — @%s', item.label, item.username || 'unknown' )
			)
		)
	);

	// Render the active view content.
	const renderView = (): ReactElement | null => {
		switch ( activeView ) {
			case 'publish':
				return h( PlatformPublishPane, {
					key: selectedPlatform.slug,
					slug: selectedPlatform.slug,
					label: selectedPlatform.label,
					username: selectedPlatform.username,
					config: selectedPlatform.config,
				} );

			case 'giveaway':
				return h( GiveawayView, {
					key: `giveaway-${ selectedPlatform.slug }`,
					context,
				} );

			default:
				return null;
		}
	};

	return h(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--socials' },
		h(
			ToolbarView,
			{
				actions: platformDropdown,
			},
			h( TabsView, {
				tabs: views,
				active: activeView,
				onChange: setActiveView,
			} )
		),
		renderView()
	);
};

export default SocialsPane;
