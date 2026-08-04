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
/**
 * WordPress dependencies
 */
import { useEffect, useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';

/**
 * External dependencies
 */
import type { ReactElement, ReactNode } from 'react';
import {
	getOrCreateClientContextRegistry,
	registerClientContextProvider,
} from '@extrachill/chat';
import { ResponsiveTabs, Toolbar } from '@extrachill/components';

/**
 * Internal dependencies
 */
import type { StudioPaneProps } from '../../types/studio';
import { ConversionMapChart } from './conversion-map-chart';
import { RetentionChart } from './retention-chart';
import { SessionsChart } from './sessions-chart';
import { SurfaceGrowthChart } from './surface-growth-chart';

const CLIENT_CONTEXT_PROVIDER_ID = 'extrachill-studio.network';

const REPORTS = [
	{ id: 'sessions', label: __( 'Sessions', 'extrachill-studio' ) },
	{ id: 'growth', label: __( 'Growth', 'extrachill-studio' ) },
	{ id: 'retention', label: __( 'Retention', 'extrachill-studio' ) },
	{ id: 'conversion', label: __( 'Conversion', 'extrachill-studio' ) },
];

const DATE_RANGES = [
	{ value: 28, label: __( 'Last 4 weeks', 'extrachill-studio' ) },
	{ value: 84, label: __( 'Last 12 weeks', 'extrachill-studio' ) },
	{ value: 364, label: __( 'Last 52 weeks', 'extrachill-studio' ) },
];

const NetworkPane = ( { context }: StudioPaneProps ): ReactElement => {
	const [ activeReport, setActiveReport ] = useState( 'sessions' );
	const [ days, setDays ] = useState( 28 );
	const [ selectedBlogId, setSelectedBlogId ] = useState( 0 );
	const [ conversionAuthorScope, setConversionAuthorScope ] = useState( 'all' );
	const controlId = useId().replace( /:/g, '' );
	const rangeControlId = `${ controlId }-range`;
	const siteControlId = `${ controlId }-site`;
	const authorControlId = `${ controlId }-author`;
	const currentUserId = useSelect( ( selectStore ) => {
		const currentUser = (
			selectStore( 'core' ) as {
				getCurrentUser?: () => { id?: number } | undefined;
			}
		).getCurrentUser?.();
		return Number( currentUser?.id ?? 0 );
	}, [] );
	const conversionAuthorId =
		conversionAuthorScope === 'mine' ? currentUserId : 0;
	const selectedSite = context.networkSites.find(
		( site ) => site.id === selectedBlogId
	);

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

	const supportsSiteScope =
		activeReport === 'sessions' || activeReport === 'retention';
	let scopeDescription: string = __(
		'Scope: entire network',
		'extrachill-studio'
	);
	if ( activeReport === 'growth' ) {
		scopeDescription = __(
			'Scope: five measured publishing surfaces',
			'extrachill-studio'
		);
	} else if ( activeReport === 'conversion' ) {
		scopeDescription = conversionAuthorId
			? __( 'Scope: my main-blog posts to Events, Community, and Artist', 'extrachill-studio' )
			: __( 'Scope: all main-blog posts to Events, Community, and Artist', 'extrachill-studio' );
	} else if ( selectedSite ) {
		scopeDescription =
			activeReport === 'retention'
				? `${ __( 'Scope:', 'extrachill-studio' ) } ${
						selectedSite.name
				  } ${ __(
						'(cross-site return remains network-wide)',
						'extrachill-studio'
				  ) }`
				: `${ __( 'Scope:', 'extrachill-studio' ) } ${
						selectedSite.name
				  }`;
	}

	const controls = (
		<Toolbar
			className="ec-studio-network__toolbar"
			actions={
				<>
					<label
						className="ec-studio-network__filter"
						htmlFor={ rangeControlId }
					>
						<span>{ __( 'Range', 'extrachill-studio' ) }</span>
						<select
							id={ rangeControlId }
							className="ec-toolbar__select"
							value={ days }
							onChange={ ( event ) =>
								setDays( Number( event.currentTarget.value ) )
							}
						>
							{ DATE_RANGES.map( ( range ) => (
								<option
									key={ range.value }
									value={ range.value }
								>
									{ range.label }
								</option>
							) ) }
						</select>
					</label>
					{ supportsSiteScope ? (
						<label
							className="ec-studio-network__filter"
							htmlFor={ siteControlId }
						>
							<span>{ __( 'Site', 'extrachill-studio' ) }</span>
							<select
								id={ siteControlId }
								className="ec-toolbar__select"
								value={ selectedBlogId }
								onChange={ ( event ) =>
									setSelectedBlogId(
										Number( event.currentTarget.value )
									)
								}
							>
								<option value={ 0 }>
									{ __(
										'Entire network',
										'extrachill-studio'
									) }
								</option>
								{ context.networkSites.map( ( site ) => (
									<option key={ site.id } value={ site.id }>
										{ site.name }
									</option>
								) ) }
							</select>
						</label>
					) : null }
					{ activeReport === 'conversion' ? (
						<label
							className="ec-studio-network__filter"
							htmlFor={ authorControlId }
						>
							<span>{ __( 'Posts', 'extrachill-studio' ) }</span>
							<select
								id={ authorControlId }
								className="ec-toolbar__select"
								value={ conversionAuthorScope }
								onChange={ ( event ) =>
									setConversionAuthorScope( event.currentTarget.value )
								}
							>
								<option value="all">
									{ __( 'All posts', 'extrachill-studio' ) }
								</option>
								<option value="mine" disabled={ ! currentUserId }>
									{ __( 'My posts', 'extrachill-studio' ) }
								</option>
							</select>
						</label>
					) : null }
				</>
			}
		>
			<span className="ec-studio-network__scope">
				{ scopeDescription }
			</span>
		</Toolbar>
	);

	const renderReport = ( reportId: string ): ReactNode => {
		let report: ReactNode;
		switch ( reportId ) {
			case 'growth':
				report = <SurfaceGrowthChart days={ days } />;
				break;
			case 'retention':
				report = (
					<RetentionChart days={ days } blogId={ selectedBlogId } />
				);
				break;
			case 'conversion':
				report = (
					<ConversionMapChart
						days={ days }
						authorId={ conversionAuthorId }
					/>
				);
				break;
			case 'sessions':
			default:
				report = (
					<SessionsChart days={ days } host={ selectedSite?.host } />
				);
		}

		return (
			<div className="ec-studio-network__report">
				{ controls }
				{ report }
			</div>
		);
	};

	return (
		<div className="ec-studio-pane ec-studio-pane--network">
			<p className="ec-studio-network__intro">
				{ __(
					'Choose a report, range, and supported site scope. Each view uses the full workspace width.',
					'extrachill-studio'
				) }
			</p>
			<ResponsiveTabs
				tabs={ REPORTS }
				active={ activeReport }
				onChange={ setActiveReport }
				renderPanel={ renderReport }
				syncWithHash
				hashPrefix="network-report-"
				contextSurface="studio-network"
			/>
		</div>
	);
};

export default NetworkPane;
