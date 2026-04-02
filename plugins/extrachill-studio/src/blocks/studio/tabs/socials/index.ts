import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useMemo, useState } from '@wordpress/element';
import type { ComponentType, ReactElement } from 'react';
import { InlineStatus, Panel, PanelHeader } from '@extrachill/components';
import type { SocialPlatformsResponse } from '@extrachill/api-client';

import { studioClient } from '../../app/client';
import type { StudioPaneProps } from '../../types/studio';
import type { SocialPlatformConfig } from '../../types/externals';
import SocialsSidebar from './sidebar';
import type { SidebarPlatform, CapabilityEntry } from './sidebar';
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

/** Fallback when the handler doesn't declare capabilities. */
const DEFAULT_CAPABILITIES: CapabilityEntry[] = [
	{ slug: 'publish', label: 'Publish' },
];

interface PlatformEntry {
	slug: string;
	label: string;
	authenticated: boolean;
	username: string | null;
	type: string;
	capabilities: CapabilityEntry[];
	config: SocialPlatformConfig;
}

/**
 * Normalize capabilities from the server response.
 *
 * Handles both the structured format ({ slug, label }[]) and a bare
 * string[] fallback for backwards compatibility with older handler versions.
 */
const normalizeCapabilities = ( raw: unknown ): CapabilityEntry[] => {
	if ( ! Array.isArray( raw ) || raw.length === 0 ) {
		return DEFAULT_CAPABILITIES;
	}

	return raw.map( ( entry ) => {
		if ( typeof entry === 'string' ) {
			// Bare string fallback — capitalize for display.
			return { slug: entry, label: entry.charAt( 0 ).toUpperCase() + entry.slice( 1 ) };
		}

		if ( entry && typeof entry === 'object' && typeof entry.slug === 'string' ) {
			return { slug: entry.slug, label: entry.label || entry.slug };
		}

		return { slug: String( entry ), label: String( entry ) };
	} );
};

const SocialsPane = ( { context }: StudioPaneProps ): ReactElement | null => {
	const allowedSlugs = context.socialPlatforms;
	const [ platforms, setPlatforms ] = useState< SocialPlatformsResponse >( {} );
	const [ error, setError ] = useState( '' );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ activePlatform, setActivePlatform ] = useState< string | null >( null );
	const [ activeCapability, setActiveCapability ] = useState( 'publish' );

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

	/** Filter to authenticated, non-fetch platforms within the allowlist. */
	const availablePlatforms: PlatformEntry[] = useMemo( () => {
		const entries = Object.entries( platforms ).map( ( [ slug, config ] ) => {
			const cfg = config as SocialPlatformConfig | undefined;

			return {
				slug,
				label: cfg?.label || slug,
				authenticated: cfg?.authenticated || false,
				username: cfg?.username || null,
				type: cfg?.type || 'publish',
				capabilities: normalizeCapabilities( cfg?.capabilities ),
				config: cfg || { label: slug },
			};
		} );

		return entries.filter( ( item ) => {
			if ( ! item.authenticated || item.type === 'fetch' ) {
				return false;
			}
			if ( allowedSlugs.length > 0 && ! allowedSlugs.includes( item.slug ) ) {
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
			config: selectedPlatform.config,
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
