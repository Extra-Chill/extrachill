import { useEffect, useId } from 'react';
import type { TabItem } from './Tabs.tsx';

/**
 * Generic active-tab context broadcast for tabbed surfaces.
 *
 * Every tabbed surface on the platform broadcasts which tab the user is
 * viewing — by default, with no per-block configuration — so agent consumers
 * (e.g. Roadie's frontend chat) can see it. The decision to *consume* that
 * context lives entirely with the consumer (the chat widget opts in per
 * agent/integration via its own `clientContext` flag); producers never decide
 * who listens. A block can opt OUT in the rare case it shouldn't broadcast,
 * but that is the exception, not the norm.
 *
 * The transport is the shared, browser-only client-context registry published
 * on `window.ecClientContextRegistry` by `@extrachill/chat`. We duck-type that
 * registry here rather than importing `@extrachill/chat`, so this package stays
 * dependency-free of the chat layer.
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
	/** The currently active tab id. */
	active: string;
	/** The full tab list, used to resolve the active tab's label. */
	tabs: TabItem[];
	/**
	 * Optional human-readable surface name (e.g. `studio`,
	 * `community-settings`). Purely a label for the consumer — when omitted the
	 * broadcast still happens; the surface is reported as `null`. Provider ids
	 * are always made unique per mounted instance regardless, so multiple
	 * tabbed surfaces on one page never collide.
	 */
	surface?: string;
	/**
	 * Opt OUT of broadcasting. Defaults to `true` (broadcast on). Set `false`
	 * only for a surface that must not publish its active tab.
	 */
	enabled?: boolean;
	/**
	 * Provider priority. Higher wins when several providers publish context.
	 * Defaults to 10 so a surface-specific provider (e.g. an editor's draft
	 * details, priority 100) can layer richer context on top.
	 */
	priority?: number;
}

/**
 * Broadcast the active tab into the shared client-context registry for the
 * lifetime of the calling component. Re-registers whenever the active tab (or
 * surface) changes so the published context always reflects the current tab.
 *
 * On by default — pass `enabled: false` to suppress.
 *
 * @param options Active tab, tab list, optional surface label / priority, and
 *                the `enabled` opt-out.
 */
export function useTabClientContext( {
	active,
	tabs,
	surface,
	enabled = true,
	priority = 10,
}: UseTabClientContextOptions ): void {
	// Stable, unique-per-instance id so several tabbed surfaces on one page
	// register distinct providers instead of clobbering each other.
	const instanceId = useId();

	useEffect( () => {
		if ( typeof window === 'undefined' || ! enabled ) {
			return undefined;
		}

		const registry = window.ecClientContextRegistry;
		if ( ! registry ) {
			// No chat/agent consumer on this page — nothing to broadcast to.
			return undefined;
		}

		const activeTab = tabs.find( ( tab ) => tab.id === active ) ?? null;

		const unregister = registry.registerProvider( {
			id: `tabs.${ instanceId }`,
			priority,
			getContext: () => ( {
				kind: 'tabs',
				surface: surface ?? null,
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
	}, [ instanceId, surface, active, tabs, enabled, priority ] );
}
