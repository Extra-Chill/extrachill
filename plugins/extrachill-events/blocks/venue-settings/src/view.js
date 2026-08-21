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
import { BookingFormTab } from './booking-form-tab';
import { BookingTab } from './booking-tab';
import { ClaimPanel, ClaimsTab } from './claims-tab';
import { ProfileTab } from './profile-tab';
import { LoadingPanel, Status } from './status';
import { TeamTab } from './team-tab';
import { VenueWorkspaceHeader } from './venue-workspace-header';
import { editableConfig, profileChanges, sameDocument } from './state';

export function VenueSettingsApp( { context } ) {
	const selected = context.selected_venue;
	const [ activeTab, setActiveTab ] = useState( 'calendar' );
	const [ loadErrors, setLoadErrors ] = useState( {} );
	const [ profiles, setProfiles ] = useState( {} );
	const [ profileBaselines, setProfileBaselines ] = useState( {} );
	const [ configs, setConfigs ] = useState( {} );
	const [ configBaselines, setConfigBaselines ] = useState( {} );
	const [ configRevisions, setConfigRevisions ] = useState( {} );
	const [ members, setMembers ] = useState( {} );
	const [ invitations, setInvitations ] = useState( {} );
	const [ claims, setClaims ] = useState( [] );
	const [ profileStatuses, setProfileStatuses ] = useState( {} );
	const [ configStatuses, setConfigStatuses ] = useState( {} );
	const [ savingProfiles, setSavingProfiles ] = useState( {} );
	const [ savingConfigs, setSavingConfigs ] = useState( {} );
	const profileRequestIds = useRef( {} );
	const configRequestIds = useRef( {} );
	const teamRequestIds = useRef( {} );
	const scopedVenues = selected ? [ selected ] : context.venues;
	const canAccess = ( venue ) =>
		typeof venue.can_access === 'boolean'
			? venue.can_access
			: selected?.id === venue.id && Boolean( context.can_access );
	const canManage = ( venue ) =>
		typeof venue.can_manage === 'boolean'
			? venue.can_manage
			: selected?.id === venue.id && Boolean( context.can_manage );
	const accessibleVenues = scopedVenues.filter( canAccess );
	const manageableVenues = scopedVenues.filter( canManage );
	const setVenueState = ( setter, venueId, value ) =>
		setter( ( current ) => ( { ...current, [ venueId ]: value } ) );
	const setVenueError = ( venueId, key, value ) =>
		setLoadErrors( ( current ) => ( {
			...current,
			[ venueId ]: { ...current[ venueId ], [ key ]: value },
		} ) );
	const dirty = scopedVenues.some( ( venue ) => {
		const profile = profiles[ venue.id ];
		const profileBaseline = profileBaselines[ venue.id ];
		const config = configs[ venue.id ];
		const configBaseline = configBaselines[ venue.id ];
		return (
			Boolean(
				profile &&
					profileBaseline &&
					! sameDocument( profile, profileBaseline )
			) ||
			Boolean(
				config &&
					configBaseline &&
					! sameDocument( config, configBaseline )
			)
		);
	} );

	const loadTeam = async ( venue ) => {
		if ( ! canManage( venue ) ) {
			return;
		}
		const currentRequest = ( teamRequestIds.current[ venue.id ] || 0 ) + 1;
		teamRequestIds.current[ venue.id ] = currentRequest;
		const [ memberResult, invitationResult ] = await Promise.allSettled( [
			runAbility( 'extrachill/list-venue-memberships', {
				venue_term_id: venue.id,
			} ),
			runAbility( 'extrachill/list-venue-invitations', {
				venue_term_id: venue.id,
			} ),
		] );
		if ( currentRequest !== teamRequestIds.current[ venue.id ] ) {
			return;
		}
		if ( memberResult.status === 'fulfilled' ) {
			setVenueState( setMembers, venue.id, memberResult.value );
		}
		if ( invitationResult.status === 'fulfilled' ) {
			setVenueState( setInvitations, venue.id, invitationResult.value );
		}
		setVenueError(
			venue.id,
			'team',
			memberResult.status === 'rejected' ||
				invitationResult.status === 'rejected'
				? 'Some team records could not be loaded.'
				: null
		);
	};
	const loadClaims = async () => {
		if ( ! context.user.is_admin ) {
			return;
		}
		try {
			setClaims(
				await runAbility( 'extrachill/list-venue-claims', {
					status: 'pending',
				} )
			);
			setLoadErrors( ( current ) => ( { ...current, claims: null } ) );
		} catch ( error ) {
			setLoadErrors( ( current ) => ( {
				...current,
				claims: errorDetails( error ).message,
			} ) );
		}
	};
	const loadProfile = async ( venue ) => {
		const currentRequest =
			( profileRequestIds.current[ venue.id ] || 0 ) + 1;
		profileRequestIds.current[ venue.id ] = currentRequest;
		setVenueError( venue.id, 'profile', null );
		try {
			const result = await runAbility( 'extrachill/get-venue-profile', {
				venue_term_id: venue.id,
			} );
			if ( currentRequest !== profileRequestIds.current[ venue.id ] ) {
				return;
			}
			setVenueState( setProfiles, venue.id, result );
			setVenueState( setProfileBaselines, venue.id, result );
		} catch ( error ) {
			if ( currentRequest !== profileRequestIds.current[ venue.id ] ) {
				return;
			}
			setVenueError( venue.id, 'profile', errorDetails( error ).message );
		}
	};
	const loadConfig = async ( venue ) => {
		const currentRequest =
			( configRequestIds.current[ venue.id ] || 0 ) + 1;
		configRequestIds.current[ venue.id ] = currentRequest;
		setVenueError( venue.id, 'config', null );
		try {
			const result = await runAbility(
				'extrachill/get-venue-booking-config',
				{
					venue_term_id: venue.id,
				}
			);
			if ( currentRequest !== configRequestIds.current[ venue.id ] ) {
				return;
			}
			setVenueState( setConfigRevisions, venue.id, result.revision );
			const editable = editableConfig( result );
			setVenueState( setConfigs, venue.id, editable );
			setVenueState( setConfigBaselines, venue.id, editable );
		} catch ( error ) {
			if ( currentRequest !== configRequestIds.current[ venue.id ] ) {
				return;
			}
			setVenueError( venue.id, 'config', errorDetails( error ).message );
		}
	};
	const loadVenue = async ( venue ) => {
		if ( canAccess( venue ) ) {
			await Promise.all( [ loadProfile( venue ), loadConfig( venue ) ] );
		}
		await loadTeam( venue );
	};

	useEffect( () => {
		Promise.all( scopedVenues.map( loadVenue ) );
		loadClaims();
		// The scope is immutable for this mount; switching performs a full route navigation.
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

	const switchVenue = ( venueId ) => {
		if (
			dirty &&
			// eslint-disable-next-line no-alert -- Native navigation guard is keyboard and screen-reader accessible.
			! window.confirm( 'Discard unsaved changes and switch venues?' )
		) {
			return;
		}
		const url = new URL( context.route_url );
		if ( venueId ) {
			url.searchParams.set( 'venue_id', venueId );
		}
		window.location.assign( url.toString() );
	};
	const saveProfile = async ( venue ) => {
		const profile = profiles[ venue.id ];
		const baseline = profileBaselines[ venue.id ];
		setVenueState( setSavingProfiles, venue.id, true );
		setVenueState( setProfileStatuses, venue.id, null );
		try {
			const result = await runAbility(
				'extrachill/update-venue-profile',
				{
					venue_term_id: venue.id,
					expected_revision: baseline.revision,
					profile: profileChanges( profile, baseline ),
				}
			);
			setVenueState( setProfiles, venue.id, result.profile );
			setVenueState( setProfileBaselines, venue.id, result.profile );
			setVenueState( setProfileStatuses, venue.id, {
				tone: 'success',
				message: 'Venue profile saved.',
			} );
		} catch ( error ) {
			const details = errorDetails( error );
			setVenueState( setProfileStatuses, venue.id, {
				tone: details.status === 409 ? 'warning' : 'error',
				message:
					details.status === 409
						? `${ details.message } Reload before saving again.`
						: details.message,
			} );
		} finally {
			setVenueState( setSavingProfiles, venue.id, false );
		}
	};
	const saveConfig = async ( venue ) => {
		const config = configs[ venue.id ];
		setVenueState( setSavingConfigs, venue.id, true );
		setVenueState( setConfigStatuses, venue.id, null );
		try {
			const result = await runAbility(
				'extrachill/update-venue-booking-config',
				{
					venue_term_id: venue.id,
					expected_revision: configRevisions[ venue.id ],
					config,
				}
			);
			const editable = editableConfig( result );
			setVenueState( setConfigRevisions, venue.id, result.revision );
			setVenueState( setConfigs, venue.id, editable );
			setVenueState( setConfigBaselines, venue.id, editable );
			setVenueState( setConfigStatuses, venue.id, {
				tone: 'success',
				message: 'Venue settings saved.',
			} );
		} catch ( error ) {
			const details = errorDetails( error );
			setVenueState( setConfigStatuses, venue.id, {
				tone: details.status === 409 ? 'warning' : 'error',
				message:
					details.status === 409
						? `${ details.message } Reload before saving again.`
						: details.message,
			} );
		} finally {
			setVenueState( setSavingConfigs, venue.id, false );
		}
	};

	const tabs = [
		...( accessibleVenues.length
			? [
					{ id: 'calendar', label: 'Bookings' },
					{ id: 'venue', label: 'Venue' },
					{ id: 'booking-form', label: 'Booking Form' },
					{ id: 'settings', label: 'Booking Rules' },
			  ]
			: [] ),
		...( manageableVenues.length ? [ { id: 'team', label: 'Team' } ] : [] ),
	];
	const resolvedActiveTab = tabs.some( ( tab ) => tab.id === activeTab )
		? activeTab
		: tabs[ 0 ]?.id;
	const renderVenuePanel = ( venue, tab ) => {
		const venueContext = {
			...context,
			selected_venue: venue,
			can_access: canAccess( venue ),
			can_manage: canManage( venue ),
			booking_id: selected?.id === venue.id ? context.booking_id : 0,
			booking_url: venue.booking_url || context.booking_url || '',
			support_events:
				venue.support_events ||
				( selected?.id === venue.id ? context.support_events : [] ),
		};
		const errors = loadErrors[ venue.id ] || {};
		const config = configs[ venue.id ];
		const idPrefix = selected ? '' : `venue-${ venue.id }-`;
		if ( tab === 'venue' ) {
			if ( errors.profile ) {
				return (
					<Panel>
						<InlineStatus tone="error">
							{ errors.profile }
						</InlineStatus>
						<button
							type="button"
							className="button-2"
							onClick={ () => loadProfile( venue ) }
						>
							Retry profile
						</button>
					</Panel>
				);
			}
			return profiles[ venue.id ] ? (
				<ProfileTab
					profile={ profiles[ venue.id ] }
					baseline={ profileBaselines[ venue.id ] }
					setProfile={ ( value ) =>
						setVenueState( setProfiles, venue.id, value )
					}
					onSave={ () => saveProfile( venue ) }
					saving={ Boolean( savingProfiles[ venue.id ] ) }
					status={ profileStatuses[ venue.id ] }
					idPrefix={ idPrefix }
				/>
			) : (
				<LoadingPanel label="Loading profile..." />
			);
		}
		if ( tab === 'settings' || tab === 'booking-form' ) {
			if ( errors.config ) {
				return (
					<Panel>
						<InlineStatus tone="error">
							{ errors.config }
						</InlineStatus>
						<button
							type="button"
							className="button-2"
							onClick={ () => loadConfig( venue ) }
						>
							Retry booking settings
						</button>
					</Panel>
				);
			}
			if ( ! config ) {
				return <LoadingPanel label="Loading venue settings..." />;
			}
			return tab === 'booking-form' ? (
				<BookingFormTab
					config={ config }
					baseline={ configBaselines[ venue.id ] }
					setConfig={ ( value ) =>
						setVenueState( setConfigs, venue.id, value )
					}
					onInitializeConfig={ ( value ) => {
						setVenueState( setConfigs, venue.id, value );
						setVenueState( setConfigBaselines, venue.id, value );
					} }
					onSave={ () => saveConfig( venue ) }
					saving={ Boolean( savingConfigs[ venue.id ] ) }
					status={ configStatuses[ venue.id ] }
					bookingUrl={ venueContext.booking_url }
					venueName={ venue.name }
					profile={ profiles[ venue.id ] }
					idPrefix={ idPrefix }
				/>
			) : (
				<BookingTab
					config={ config }
					baseline={ configBaselines[ venue.id ] }
					setConfig={ ( value ) =>
						setVenueState( setConfigs, venue.id, value )
					}
					onSave={ () => saveConfig( venue ) }
					saving={ Boolean( savingConfigs[ venue.id ] ) }
					status={ configStatuses[ venue.id ] }
					idPrefix={ idPrefix }
				/>
			);
		}
		if ( tab === 'team' ) {
			return (
				<>
					<Status
						state={
							errors.team
								? { tone: 'warning', message: errors.team }
								: null
						}
						onRetry={ () => loadTeam( venue ) }
					/>
					<TeamTab
						venueId={ venue.id }
						members={ members[ venue.id ] || [] }
						invitations={ invitations[ venue.id ] || [] }
						onRefresh={ () => loadTeam( venue ) }
						idPrefix={ idPrefix }
					/>
				</>
			);
		}
		return null;
	};
	const renderPanel = ( tab ) => {
		if ( tab === 'calendar' ) {
			return (
				<BookingConsole
					context={ context }
					venues={ accessibleVenues }
					defaultDeals={ Object.fromEntries(
						accessibleVenues.map( ( venue ) => [
							venue.id,
							configs[ venue.id ]?.default_deal,
						] )
					) }
					supportEvents={ accessibleVenues.flatMap( ( venue ) =>
						(
							venue.support_events ||
							( selected?.id === venue.id
								? context.support_events
								: [] )
						).map( ( event ) => ( {
							...event,
							venue_term_id: venue.id,
							venue_name: venue.name,
						} ) )
					) }
				/>
			);
		}
		const venues = tab === 'team' ? manageableVenues : accessibleVenues;
		return venues.map( ( venue ) => (
			<section
				className="ec-venue-settings__venue-scope"
				key={ venue.id }
				aria-labelledby={
					selected ? undefined : `venue-scope-${ tab }-${ venue.id }`
				}
			>
				{ ! selected && (
					<h2 id={ `venue-scope-${ tab }-${ venue.id }` }>
						{ venue.name }
					</h2>
				) }
				{ renderVenuePanel( venue, tab ) }
			</section>
		) );
	};
	const claimsQueue = context.user.is_admin ? (
		<>
			<Status
				state={
					loadErrors.claims
						? { tone: 'error', message: loadErrors.claims }
						: null
				}
				onRetry={ loadClaims }
			/>
			{ claims.length > 0 && (
				<ClaimsTab
					claims={ claims }
					venues={ context.claim_venues }
					onRefresh={ loadClaims }
				/>
			) }
		</>
	) : null;
	const renderWorkspace = () => {
		if ( selected && ! canAccess( selected ) && ! canManage( selected ) ) {
			if ( context.user.is_admin ) {
				return claimsQueue;
			}
			return (
				<ClaimPanel
					venues={ context.claim_venues }
					membership={ selected }
					initialVenueId={ context.requested_venue_id }
				/>
			);
		}
		if ( tabs.length === 0 ) {
			return context.user.is_admin ? (
				claimsQueue
			) : (
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
				<ResponsiveTabs
					tabs={ tabs }
					active={ resolvedActiveTab }
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
