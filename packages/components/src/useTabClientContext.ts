import { useEffect } from 'react';
import type { TabItem } from './Tabs.tsx';

/**
 * Generic active-tab context broadcast for tabbed surfaces.
 *
 * Any tabbed block on the platform can tell agent consumers (e.g. Roadie's
 * frontend chat) which tab the user is looking at — without coupling the tabs
 * to the chat layer in either direction. The transport is the shared,
 * browser-only client-context registry published on
 * `window.ecClientContextRegistry` by `@extrachill/chat`. We duck-type that
 * registry here rather than importing `@extrachill/chat`, so this package
 * stays dependency-free of the chat layer.
 *
 * Contract:
 *   - Producer (this hook): registers a provider describing the active tab.
 *   - Consumer (the chat widget): reads a merged snapshot and forwards it with
 *     each message. The active tab is volatile browser UI state — there is no
 *     server endpoint to fetch it from, so riding the registry is the only
 *     path. The backend independently enriches from the URL/IDs it already
 *     knows; this hook only contributes the volatile half.
 *
 * If the registry is absent (no chat widget on the page), this is a silent
 * no-op — nothing to register against, no error.
 */

/**
 * Minimal shape of a client-context provider, mirrored from `@extrachill/chat`
 * without importing it. Keep in sync with that package's public contract.
 */
interface ClientContextProvider {
	id: string;
	priority?: number;
	getContext: () => Record< string, unknown > | null;
}

/** Minimal shape of the registry we consume off the window global. */
interface ClientContextRegistry {
	registerProvider: ( provider: ClientContextProvider ) => () => void;
	notify: () => void;
}

declare global {
	interface Window {
		ecClientContextRegistry?: ClientContextRegistry;
	}
}

export interface UseTabClientContextOptions {
	/**
	 * A stable identifier for the tabbed surface, e.g. `studio`,
	 * `community-settings`, `seo`. Namespaces the provider id so multiple
	 * tabbed surfaces on one page don't collide, and gives the consumer a
	 * human-readable surface name.
	 */
	surface: string;
	/** The currently active tab id. */
	active: string;
	/** The full tab list, used to resolve the active tab's label. */
	tabs: TabItem[];
	/**
	 * Provider priority. Higher wins when several providers publish context.
	 * Defaults to 10 so a surface-specific provider (e.g. Compose's draft
	 * details, priority 100) can layer richer context on top.
	 */
	priority?: number;
}

/**
 * Register an active-tab client-context provider for the lifetime of the
 * calling component. Re-registers whenever the active tab (or surface) changes
 * so the published context always reflects the current tab.
 *
 * @param options Surface id, active tab, tab list, and optional priority.
 */
export function useTabClientContext( {
	surface,
	active,
	tabs,
	priority = 10,
}: UseTabClientContextOptions ): void {
	useEffect( () => {
		if ( typeof window === 'undefined' ) {
			return undefined;
		}

		// An empty surface means broadcasting is disabled (the caller opted
		// out). Bail here rather than at the call site so the hook is always
		// invoked unconditionally, per the rules of hooks.
		if ( ! surface ) {
			return undefined;
		}

		const registry = window.ecClientContextRegistry;
		if ( ! registry ) {
			// No chat/agent consumer on this page — nothing to broadcast to.
			return undefined;
		}

		const activeTab = tabs.find( ( tab ) => tab.id === active ) ?? null;

		const unregister = registry.registerProvider( {
			id: `tabs.${ surface }`,
			priority,
			getContext: () => ( {
				kind: 'tabs',
				surface,
				activeTab: active,
				activeTabLabel: activeTab?.label ?? null,
				availableTabs: tabs.map( ( tab ) => ( {
					id: tab.id,
					label: tab.label,
				} ) ),
			} ),
		} );

		// Nudge consumers to re-read now that this surface's context exists.
		registry.notify();

		return () => {
			unregister();
		};
	}, [ surface, active, tabs, priority ] );
}
