/**
 * WordPress dependencies
 */
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	BlockShell,
	BlockShellInner,
	InlineStatus,
	Panel,
	ResponsiveTabs,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { errorDetails, runAbility } from './api';
import { BookingConsole } from './booking-console';
import { BookingTab } from './booking-tab';
import { ClaimPanel, ClaimsTab } from './claims-tab';
import { IntakeTab } from './intake-tab';
import { ProfileTab } from './profile-tab';
import { LoadingPanel, Status } from './status';
import { TeamTab } from './team-tab';
import { VenueWorkspaceHeader } from './venue-workspace-header';
import { editableConfig, profileChanges, sameDocument } from './state';

export { venueSubscriberCsv } from './team-tab';

function AllVenues( { venues, routeUrl } ) {
	return (
		<Panel>
			<h2>All venues</h2>
			<p>
				Open a venue to manage its calendar, bookings, profile, and
				team.
			</p>
			<ul className="ec-venue-settings__records">
				{ venues.map( ( venue ) => {
					const url = new URL( routeUrl );
					url.searchParams.set( 'venue_id', venue.id );
					return (
						<li key={ venue.id }>
							<span>
								<strong>{ venue.name }</strong>{ ' ' }
								<small>
									{ venue.status }
									{ venue.is_owner ? ' - owner' : '' }
								</small>
							</span>
							<a
								className="button-2 button-small"
								href={ url.toString() }
							>
								Open workspace
							</a>
						</li>
					);
				} ) }
			</ul>
		</Panel>
	);
}

export function VenueSettingsApp( { context } ) {
	const selected = context.selected_venue;
	const [ activeTab, setActiveTab ] = useState( 'calendar' );
	const [ loading, setLoading ] = useState( Boolean( context.can_access ) );
	const [ loadErrors, setLoadErrors ] = useState( {} );
	const [ profile, setProfile ] = useState( null );
	const [ profileBaseline, setProfileBaseline ] = useState( null );
	const [ config, setConfig ] = useState( null );
	const [ configBaseline, setConfigBaseline ] = useState( null );
	const [ configRevision, setConfigRevision ] = useState( 0 );
	const [ members, setMembers ] = useState( [] );
	const [ invitations, setInvitations ] = useState( [] );
	const [ subscribers, setSubscribers ] = useState( [] );
	const [ claims, setClaims ] = useState( [] );
	const [ profileStatus, setProfileStatus ] = useState( null );
	const [ configStatus, setConfigStatus ] = useState( null );
	const [ savingProfile, setSavingProfile ] = useState( false );
	const [ savingConfig, setSavingConfig ] = useState( false );
	const profileRequestId = useRef( 0 );
	const configRequestId = useRef( 0 );
	const dirty =
		Boolean(
			profile &&
				profileBaseline &&
				! sameDocument( profile, profileBaseline )
		) ||
		Boolean(
			config && configBaseline && ! sameDocument( config, configBaseline )
		);

	const loadTeam = async () => {
		if ( ! selected || ! context.can_manage ) {
			return;
		}
		const [ memberResult, invitationResult, subscriberResult ] =
			await Promise.allSettled( [
				runAbility( 'extrachill/list-venue-memberships', {
					venue_term_id: selected.id,
				} ),
				runAbility( 'extrachill/list-venue-invitations', {
					venue_term_id: selected.id,
				} ),
				runAbility( 'extrachill/list-venue-email-subscribers', {
					venue_term_id: selected.id,
				} ),
			] );
		if ( memberResult.status === 'fulfilled' ) {
			setMembers( memberResult.value );
		}
		if ( invitationResult.status === 'fulfilled' ) {
			setInvitations( invitationResult.value );
		}
		if ( subscriberResult.status === 'fulfilled' ) {
			setSubscribers( subscriberResult.value.subscribers || [] );
		}
		setLoadErrors( ( current ) => ( {
			...current,
			team:
				memberResult.status === 'rejected' ||
				invitationResult.status === 'rejected' ||
				subscriberResult.status === 'rejected'
					? 'Some team records could not be loaded.'
					: null,
		} ) );
	};
	const loadClaims = async () => {
		if ( ! context.user.is_admin ) {
			return;
		}
		try {
			setClaims( await runAbility( 'extrachill/list-venue-claims' ) );
			setLoadErrors( ( current ) => ( { ...current, claims: null } ) );
		} catch ( error ) {
			setLoadErrors( ( current ) => ( {
				...current,
				claims: errorDetails( error ).message,
			} ) );
		}
	};
	const loadProfile = async () => {
		const currentRequest = ++profileRequestId.current;
		setLoadErrors( ( current ) => ( { ...current, profile: null } ) );
		try {
			const result = await runAbility( 'extrachill/get-venue-profile', {
				venue_term_id: selected.id,
			} );
			if ( currentRequest !== profileRequestId.current ) {
				return;
			}
			setProfile( result );
			setProfileBaseline( result );
		} catch ( error ) {
			if ( currentRequest !== profileRequestId.current ) {
				return;
			}
			setLoadErrors( ( current ) => ( {
				...current,
				profile: errorDetails( error ).message,
			} ) );
		}
	};
	const loadConfig = async () => {
		const currentRequest = ++configRequestId.current;
		setLoadErrors( ( current ) => ( { ...current, config: null } ) );
		try {
			const result = await runAbility(
				'extrachill/get-venue-booking-config',
				{
					venue_term_id: selected.id,
				}
			);
			if ( currentRequest !== configRequestId.current ) {
				return;
			}
			setConfigRevision( result.revision );
			const editable = editableConfig( result );
			setConfig( editable );
			setConfigBaseline( editable );
		} catch ( error ) {
			if ( currentRequest !== configRequestId.current ) {
				return;
			}
			setLoadErrors( ( current ) => ( {
				...current,
				config: errorDetails( error ).message,
			} ) );
		}
	};
	const loadVenue = async () => {
		if ( ! selected || ! context.can_access ) {
			return;
		}
		setLoading( true );
		setLoadErrors( {} );
		setProfile( null );
		setConfig( null );
		await Promise.all( [ loadProfile(), loadConfig() ] );
		setLoading( false );
		await Promise.all( [ loadTeam(), loadClaims() ] );
	};

	useEffect( () => {
		if ( context.can_access ) {
			loadVenue();
		} else {
			loadClaims();
		}
		// The selected venue is immutable for this mount; switching performs a full route navigation.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
	useEffect( () => {
		const warn = ( event ) => {
			if ( ! dirty ) {
				return;
			}
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	const switchVenue = ( event ) => {
		if (
			dirty &&
			// eslint-disable-next-line no-alert -- Native navigation guard is keyboard and screen-reader accessible.
			! window.confirm( 'Discard unsaved changes and switch venues?' )
		) {
			return;
		}
		const venueId = Number( event.target.value );
		const url = new URL( context.route_url );
		if ( venueId ) {
			url.searchParams.set( 'venue_id', venueId );
		}
		window.location.assign( url.toString() );
	};
	const saveProfile = async () => {
		setSavingProfile( true );
		setProfileStatus( null );
		try {
			const result = await runAbility(
				'extrachill/update-venue-profile',
				{
					venue_term_id: selected.id,
					expected_revision: profileBaseline.revision,
					profile: profileChanges( profile, profileBaseline ),
				}
			);
			setProfile( result.profile );
			setProfileBaseline( result.profile );
			setProfileStatus( {
				tone: 'success',
				message: 'Venue profile saved.',
			} );
		} catch ( error ) {
			const details = errorDetails( error );
			setProfileStatus( {
				tone: details.status === 409 ? 'warning' : 'error',
				message:
					details.status === 409
						? `${ details.message } Reload before saving again.`
						: details.message,
			} );
		} finally {
			setSavingProfile( false );
		}
	};
	const saveConfig = async () => {
		setSavingConfig( true );
		setConfigStatus( null );
		try {
			const result = await runAbility(
				'extrachill/update-venue-booking-config',
				{
					venue_term_id: selected.id,
					expected_revision: configRevision,
					config,
				}
			);
			const editable = editableConfig( result );
			setConfigRevision( result.revision );
			setConfig( editable );
			setConfigBaseline( editable );
			setConfigStatus( {
				tone: 'success',
				message: 'Venue settings saved.',
			} );
		} catch ( error ) {
			const details = errorDetails( error );
			setConfigStatus( {
				tone: details.status === 409 ? 'warning' : 'error',
				message:
					details.status === 409
						? `${ details.message } Reload before saving again.`
						: details.message,
			} );
		} finally {
			setSavingConfig( false );
		}
	};

	const tabs = [
		{ id: 'calendar', label: 'Calendar' },
		{ id: 'venue', label: 'Venue' },
		{ id: 'settings', label: 'Settings' },
		...( context.can_manage ? [ { id: 'team', label: 'Team' } ] : [] ),
	];
	const renderPanel = ( tab ) => {
		if ( tab === 'calendar' ) {
			return (
				<BookingConsole
					key={ selected.id }
					context={ context }
					defaultDeal={ config?.default_deal }
					supportEvents={ context.support_events }
				/>
			);
		}
		if ( tab === 'venue' ) {
			if ( loadErrors.profile ) {
				return (
					<Panel>
						<InlineStatus tone="error">
							{ loadErrors.profile }
						</InlineStatus>
						<button
							type="button"
							className="button-2"
							onClick={ loadProfile }
						>
							Retry profile
						</button>
					</Panel>
				);
			}
			return profile ? (
				<ProfileTab
					profile={ profile }
					baseline={ profileBaseline }
					setProfile={ setProfile }
					onSave={ saveProfile }
					saving={ savingProfile }
					status={ profileStatus }
				/>
			) : (
				<LoadingPanel label="Loading profile..." />
			);
		}
		if ( tab === 'settings' ) {
			if ( loadErrors.config ) {
				return (
					<Panel>
						<InlineStatus tone="error">
							{ loadErrors.config }
						</InlineStatus>
						<button
							type="button"
							className="button-2"
							onClick={ loadConfig }
						>
							Retry booking settings
						</button>
					</Panel>
				);
			}
			return config ? (
				<BookingTab
					config={ config }
					baseline={ configBaseline }
					setConfig={ setConfig }
					onSave={ saveConfig }
					saving={ savingConfig }
					status={ configStatus }
					bookingUrl={ context.booking_url }
					venueName={ selected.name }
				>
					<IntakeTab config={ config } setConfig={ setConfig } />
				</BookingTab>
			) : (
				<LoadingPanel label="Loading venue settings..." />
			);
		}
		if ( tab === 'team' ) {
			return (
				<>
					<Status
						state={
							loadErrors.team
								? { tone: 'warning', message: loadErrors.team }
								: null
						}
						onRetry={ loadTeam }
					/>
					<TeamTab
						venueId={ selected.id }
						members={ members }
						invitations={ invitations }
						subscribers={ subscribers }
						onRefresh={ loadTeam }
					/>
				</>
			);
		}
		return null;
	};
	const claimsQueue =
		context.user.is_admin && claims.length > 0 ? (
			<ClaimsTab
				claims={ claims }
				venues={ context.claim_venues }
				onRefresh={ loadClaims }
			/>
		) : null;
	const renderWorkspace = () => {
		if ( ! selected && context.venues.length > 0 ) {
			return (
				<>
					{ claimsQueue }
					<AllVenues
						venues={ context.venues }
						routeUrl={ context.route_url }
					/>
				</>
			);
		}
		if ( ! selected || ! context.can_access ) {
			if ( context.user.is_admin ) {
				return (
					<>
						<Status
							state={
								loadErrors.claims
									? {
											tone: 'error',
											message: loadErrors.claims,
									  }
									: null
							}
							onRetry={ loadClaims }
						/>
						{ claimsQueue }
					</>
				);
			}
			return (
				<ClaimPanel
					venues={ context.claim_venues }
					membership={ selected }
					initialVenueId={ context.requested_venue_id }
				/>
			);
		}

		return (
			<>
				{ claimsQueue }
				{ loading && (
					<p className="screen-reader-text" aria-live="polite">
						Loading venue workspace.
					</p>
				) }
				<ResponsiveTabs
					tabs={ tabs }
					active={ activeTab }
					onChange={ setActiveTab }
					renderPanel={ renderPanel }
					mobileBreakpoint={ 720 }
					syncWithHash
					contextSurface="venue-settings"
				/>
			</>
		);
	};

	return (
		<BlockShell className="ec-venue-settings__shell">
			<BlockShellInner>
				<VenueWorkspaceHeader
					venues={ context.venues }
					selected={ selected }
					onSwitchVenue={ switchVenue }
				/>
				{ renderWorkspace() }
			</BlockShellInner>
		</BlockShell>
	);
}

document
	.querySelectorAll( '.ec-venue-settings__root' )
	.forEach( ( element ) => {
		const contextElement = document.getElementById(
			element.dataset.contextId
		);
		if ( ! contextElement ) {
			return;
		}
		try {
			createRoot( element ).render(
				<VenueSettingsApp
					context={ JSON.parse( contextElement.textContent ) }
				/>
			);
		} catch {
			element.innerHTML =
				'<div class="ec-inline-status ec-inline-status--error" role="alert">Venue settings could not start. Refresh the page to try again.</div>';
		}
	} );
