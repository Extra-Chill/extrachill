/**
 * Network pane — the team-facing platform-analytics dashboard.
 *
 * Renders four charts from the now-live analytics REST routes:
 *   1. Sessions over time (GA4 date_stats)        — admin-only for now, degrades
 *   2. Surface growth     (get-surface-growth)     — first-party, whole team
 *   3. Retention          (get-retention-stats)    — first-party, whole team
 *   4. Top content → platform (get-conversion-map) — first-party, whole team
 *
 * The three first-party (extrachill-analytics) charts work for the entire team
 * today; the GA4 sessions chart gracefully degrades to an "admins / coming soon"
 * placeholder until the read-only analytics cap + Extra Chill team grant land.
 *
 * While this pane is mounted it broadcasts `surface: 'network'` into the shared
 * client-context registry at a higher priority than the tab-level `studio`
 * surface, so Roadie's chat knows the team member is looking at network
 * analytics — mirroring how the Compose pane layers its richer draft context on
 * top of the generic tab broadcast.
 */
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import {
	getOrCreateClientContextRegistry,
	registerClientContextProvider,
} from '@extrachill/chat';

import type { StudioPaneProps } from '../../types/studio';
import { ConversionMapChart } from './conversion-map-chart';
import { RetentionChart } from './retention-chart';
import { SessionsChart } from './sessions-chart';
import { SurfaceGrowthChart } from './surface-growth-chart';

const CLIENT_CONTEXT_PROVIDER_ID = 'extrachill-studio.network';

/**
 * Resolve the current site host from the context site URL (GA query scope).
 * @param siteUrl
 */
const resolveHost = ( siteUrl: string ): string => {
	try {
		return new URL( siteUrl ).host;
	} catch {
		return '';
	}
};

const NetworkPane = ( { context }: StudioPaneProps ): ReactElement => {
	const host = resolveHost( context.siteUrl );

	// Broadcast `surface: 'network'` to Roadie while this pane is active.
	useEffect( () => {
		const unregister = registerClientContextProvider( {
			id: CLIENT_CONTEXT_PROVIDER_ID,
			priority: 50,
			getContext: () => ( {
				kind: 'studio-pane',
				surface: 'network',
				description:
					'Team analytics dashboard: traffic, surface growth, retention, and editorial→platform conversion.',
			} ),
		} );
		getOrCreateClientContextRegistry().notify();

		return () => {
			unregister();
		};
	}, [] );

	return (
		<div className="ec-studio-pane ec-studio-pane--network">
			<p className="ec-studio-network__intro">
				{ __(
					'Platform health at a glance — first-party traffic, growth, retention, and conversion across the network.',
					'extrachill-studio'
				) }
			</p>
			<div className="ec-studio-network__grid">
				<SessionsChart host={ host } />
				<SurfaceGrowthChart />
				<RetentionChart />
				<ConversionMapChart />
			</div>
		</div>
	);
};

export default NetworkPane;
