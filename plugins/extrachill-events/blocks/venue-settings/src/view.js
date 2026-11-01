/**
 * WordPress dependencies
 */
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';

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
import { ClaimPanel, ClaimsTab } from './claims-tab';
import {
	ManagedIdentitySelector,
	PromoterWorkspacePanel,
	SharedLinkPageEditor,
	VenuePromoterRelationships,
} from './managed-workspace';
import { CalendarFeedTab } from './calendar-feed-tab';
import { GraphicsTab } from './graphics-tab';
import { ProfileTab } from './profile-tab';
import { LoadingPanel, Status } from './status';
import { TeamTab } from './team-tab';
import { VenueWorkspaceHeader } from './venue-workspace-header';
import { profileChanges, sameDocument } from './state';

// Extensions wrap the shell with feature-owned state. Tabs consume that state
// through their extension id, without the shell importing feature modules.
function ExtensionProviders( {
	extensions,
	index = 0,
	values = {},
	children,
	...props
} ) {
	const extension = extensions[ index ];
	if ( ! extension ) {
		return children( values );
	}
	const Component = extension.component;
	return (
		<Component { ...props }>
			{ ( value ) => (
				<ExtensionProviders
					{ ...props }
					extensions={ extensions }
					index={ index + 1 }
					values={ { ...values, [ extension.id ]: value } }
				>
					{ children }
				</ExtensionProviders>
			) }
		</Component>
	);
}

export function VenueSettingsApp( { context } ) {
	const [ extensions ] = useState( () =>
		applyFilters( 'extrachill.venueSettings.extensions', [], context )
	);
	const selected = context.selected_venue;
	const workspace = context.workspace || {
		identities: [],
		selection: { reference: '', state: 'empty' },
		granted_venues: [],
		promoter_relationships: [],
	};
	const promoterMode = workspace.selection?.type === 'promoter';
	const [ activeTab, setActiveTab ] = useState( 'calendar' );
	const [ loadErrors, setLoadErrors ] = useState( {} );
	const [ profiles, setProfiles ] = useState( {} );
	const [ profileBaselines, setProfileBaselines ] = useState( {} );
	const [ members, setMembers ] = useState( {} );
	const [ invitations, setInvitations ] = useState( {} );
	const [ claims, setClaims ] = useState( [] );
	const [ profileStatuses, setProfileStatuses ] = useState( {} );
	const [ savingProfiles, setSavingProfiles ] = useState( {} );
	const [ extensionDirty, setExtensionDirty ] = useState( {} );
	const [ linkPageDirtyByIdentity, setLinkPageDirtyByIdentity ] = useState(
		{}
	);
	const profileRequestIds = useRef( {} );
	const teamRequestIds = useRef( {} );
	let scopedVenues = selected ? [ selected ] : context.venues;
	if ( promoterMode ) {
		scopedVenues = [];
	}
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
	const venueDirty = scopedVenues.some( ( venue ) => {
		const profile = profiles[ venue.id ];
		const profileBaseline = profileBaselines[ venue.id ];
		return Boolean(
			profile &&
				profileBaseline &&
				! sameDocument( profile, profileBaseline )
		);
	} );
	const linkPageDirty = Object.values( linkPageDirtyByIdentity ).some(
		Boolean
	);
	const dirty =
		linkPageDirty ||
		venueDirty ||
		Object.values( extensionDirty ).some( Boolean );
	const setLinkPageDirty = ( reference, value ) =>
		setLinkPageDirtyByIdentity( ( current ) => ( {
			...current,
			[ reference ]: value,
		} ) );

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
		if ( promoterMode || ! context.user.is_admin ) {
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
	const loadVenue = async ( venue ) => {
		if ( canAccess( venue ) ) {
			await loadProfile( venue );
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
			url.searchParams.set( 'identity', `venue:${ venueId }` );
		}
		window.location.assign( url.toString() );
	};
	const switchIdentity = ( reference ) => {
		if (
			dirty &&
			// eslint-disable-next-line no-alert -- Native navigation guard is keyboard and screen-reader accessible.
			! window.confirm( 'Discard unsaved changes and switch identities?' )
		) {
			return;
		}
		const url = new URL( context.route_url );
		if ( reference ) {
			url.searchParams.set( 'identity', reference );
			if ( reference.startsWith( 'venue:' ) ) {
				url.searchParams.set( 'venue_id', reference.slice( 6 ) );
			}
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

	const tabContext = { context, accessibleVenues, manageableVenues };
	const tabs = applyFilters(
		'extrachill.venueSettings.tabs',
		[
			...( accessibleVenues.length
				? [
						{ id: 'venue', label: 'Venue' },
						{ id: 'calendar-feed', label: 'Calendar Feed' },
						{ id: 'graphics', label: 'Graphics' },
						{ id: 'link-page', label: 'Link Page' },
				  ]
				: [] ),
			...( manageableVenues.length
				? [ { id: 'team', label: 'Team' } ]
				: [] ),
		],
		tabContext
	).filter( ( tab ) => ! tab.isVisible || tab.isVisible( tabContext ) );
	const resolvedActiveTab = tabs.some( ( tab ) => tab.id === activeTab )
		? activeTab
		: tabs[ 0 ]?.id;
	const renderVenuePanel = ( venue, tab, values ) => {
		const errors = loadErrors[ venue.id ] || {};
		const idPrefix = selected ? '' : `venue-${ venue.id }-`;
		const definition = tabs.find( ( item ) => item.id === tab );
		if ( definition?.component ) {
			const Component = definition.component;
			return (
				<Component
					venue={ venue }
					tab={ tab }
					idPrefix={ idPrefix }
					context={ context }
					extension={ values[ definition.extension ] }
				/>
			);
		}
		if ( tab === 'link-page' ) {
			return (
				<SharedLinkPageEditor
					identityType="venue"
					identityId={ venue.id }
					identityName={ venue.name }
					initialStatus={ venue.link_page?.status || 'unavailable' }
					onDirtyChange={ ( value ) =>
						setLinkPageDirty( `venue:${ venue.id }`, value )
					}
				/>
			);
		}
		if ( tab === 'calendar-feed' ) {
			return <CalendarFeedTab venue={ venue } />;
		}
		if ( tab === 'graphics' ) {
			return <GraphicsTab venue={ venue } />;
		}
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
	const renderPanel = ( tab, values ) => {
		const definition = tabs.find( ( item ) => item.id === tab );
		if ( definition?.scope === 'workspace' && definition.component ) {
			const Component = definition.component;
			return (
				<Component
					context={ context }
					venues={ accessibleVenues }
					extension={ values[ definition.extension ] }
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
				{ renderVenuePanel( venue, tab, values ) }
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
	const renderWorkspace = ( values ) => {
		if ( promoterMode ) {
			return (
				<PromoterWorkspacePanel
					workspace={ workspace }
					onLinkPageDirtyChange={ ( value ) =>
						setLinkPageDirty(
							`promoter:${ workspace.promoter?.id || 0 }`,
							value
						)
					}
				/>
			);
		}
		if ( selected && ! canAccess( selected ) && ! canManage( selected ) ) {
			if ( context.user.is_admin ) {
				return claimsQueue;
			}
			return (
				<ClaimPanel
					venues={ context.claim_venues }
					membership={ selected }
					initialVenueId={ context.requested_venue_id }
					missingVenueUrl={ context.missing_venue_url }
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
					missingVenueUrl={ context.missing_venue_url }
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
					renderPanel={ ( tab ) => renderPanel( tab, values ) }
					mobileBreakpoint={ 720 }
					syncWithHash
					contextSurface="venue-settings"
				/>
				{ selected?.is_owner && (
					<VenuePromoterRelationships
						relationships={ workspace.promoter_relationships || [] }
					/>
				) }
			</>
		);
	};

	return (
		<BlockShell className="ec-venue-settings__shell">
			<BlockShellInner>
				<ManagedIdentitySelector
					identities={ workspace.identities || [] }
					selectedReference={ workspace.selection?.reference || '' }
					onChange={ switchIdentity }
				/>
				{ ! promoterMode && (
					<VenueWorkspaceHeader
						venues={ context.venues }
						selected={ selected }
						onSwitchVenue={ switchVenue }
					/>
				) }
				<ExtensionProviders
					extensions={ extensions }
					context={ context }
					venues={ accessibleVenues }
					profiles={ profiles }
					onDirtyChange={ ( id, value ) =>
						setVenueState( setExtensionDirty, id, value )
					}
				>
					{ renderWorkspace }
				</ExtensionProviders>
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
