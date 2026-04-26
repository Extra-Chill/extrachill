import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useMemo, useState } from '@wordpress/element';
import type { ComponentType, ReactElement } from 'react';
import { InlineStatus, Panel, PanelHeader } from '@extrachill/components';
import type { SocialPlatformConfig } from '@extrachill/api-client';

import { studioClient } from '../../app/client';
import type { StudioPaneProps } from '../../types/studio';
import SocialsSidebar from './sidebar';
import type { SidebarPlatform } from './sidebar';
import PlatformPublishPane from './publish';
import CommentsView from './comments';
import GiveawayView from '../giveaway';

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;

/**
 * View component registry.
 *
 * Maps capability slugs to their rendering components. When a handler declares
 * a capability the client doesn't have a component for yet, the UI shows a
 * placeholder instead of silently failing.
 *
 * To support a new capability, add a component here and import it.
 */
const VIEW_REGISTRY: Record< string, ComponentType< any > > = {
	publish: PlatformPublishPane,
	comments: CommentsView,
	giveaway: GiveawayView,
};

const SocialsPane = ( { context }: StudioPaneProps ): ReactElement | null => {
	const allowedSlugs = context.socialPlatforms;
	const [ platforms, setPlatforms ] = useState< SocialPlatformConfig[] >( [] );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ activePlatform, setActivePlatform ] = useState< string | null >( null );
	const [ activeCapability, setActiveCapability ] = useState( 'publish' );

	useEffect( () => {
		const loadPlatforms = async (): Promise< void > => {
			setIsLoading( true );
			setError( '' );

			try {
				const response = await studioClient.socials.getPlatforms();
				setPlatforms( Array.isArray( response?.platforms ) ? response.platforms : [] );
			} catch ( fetchError ) {
				setPlatforms( [] );
				setError( ( fetchError as Error )?.message || __( 'Unable to load social platforms.', 'extrachill-studio' ) );
			} finally {
				setIsLoading( false );
			}
		};

		loadPlatforms();
	}, [] );

	/**
	 * Filter to authenticated platforms within the optional allowlist.
	 *
	 * Server controls sort order (authenticated-first then alphabetical) and
	 * pre-filters fetch handlers, so the client just renders in array order.
	 */
	const availablePlatforms: SocialPlatformConfig[] = useMemo( () => {
		return platforms.filter( ( platform ) => {
			if ( ! platform.authenticated ) {
				return false;
			}
			if ( allowedSlugs.length > 0 && ! allowedSlugs.includes( platform.slug ) ) {
				return false;
			}
			return true;
		} );
	}, [ platforms, allowedSlugs ] );

	/** Shape platforms for the sidebar component. */
	const sidebarPlatforms: SidebarPlatform[] = useMemo(
		() => availablePlatforms.map( ( p ) => ( {
			slug: p.slug,
			label: p.label,
			username: p.username,
			capabilities: p.capabilities,
		} ) ),
		[ availablePlatforms ]
	);

	// Auto-select first platform on load.
	useEffect( () => {
		if ( ! activePlatform && availablePlatforms.length > 0 ) {
			setActivePlatform( availablePlatforms[ 0 ].slug );
		}
	}, [ activePlatform, availablePlatforms.length ] );

	// Reset capability if the newly selected platform doesn't support the current one.
	useEffect( () => {
		if ( ! activePlatform ) {
			return;
		}

		const platform = availablePlatforms.find( ( p ) => p.slug === activePlatform );
		if ( platform && ! platform.capabilities.some( ( c ) => c.slug === activeCapability ) ) {
			setActiveCapability( platform.capabilities[ 0 ]?.slug || 'publish' );
		}
	}, [ activePlatform ] );

	const handleSidebarSelect = ( platformSlug: string, capability: string ): void => {
		setActivePlatform( platformSlug );
		setActiveCapability( capability );
	};

	// ── Loading / Error / Empty states ──

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

	if ( availablePlatforms.length === 0 ) {
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

	// ── Active platform and view rendering ──

	const selectedPlatform = availablePlatforms.find( ( p ) => p.slug === activePlatform ) || availablePlatforms[ 0 ];

	const renderContent = (): ReactElement => {
		const ViewComponent = VIEW_REGISTRY[ activeCapability ];

		if ( ! ViewComponent ) {
			// Handler declared a capability the client doesn't have a view for yet.
			return h(
				PanelView,
				{ className: 'ec-studio-panel', compact: true },
				h( InlineStatusView, { tone: 'info', className: 'ec-studio-message' },
					sprintf( __( 'The "%s" view is not available in this version of Studio.', 'extrachill-studio' ), activeCapability )
				)
			);
		}

		// Props vary by view — publish/comments get platform props, giveaway gets context.
		if ( activeCapability === 'giveaway' ) {
			return h( ViewComponent, {
				key: `giveaway-${ selectedPlatform.slug }`,
				context,
			} );
		}

		return h( ViewComponent, {
			key: `${ activeCapability }-${ selectedPlatform.slug }`,
			slug: selectedPlatform.slug,
			label: selectedPlatform.label,
			username: selectedPlatform.username,
			config: selectedPlatform,
		} );
	};

	return h(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--socials ec-studio-socials-layout' },
		h( SocialsSidebar, {
			platforms: sidebarPlatforms,
			activePlatform: activePlatform,
			activeCapability,
			onSelect: handleSidebarSelect,
		} ),
		h(
			'div',
			{ className: 'ec-studio-socials-content' },
			renderContent()
		)
	);
};

export default SocialsPane;
