import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ComboboxControl } from '@wordpress/components';
import { WPNativeClient } from 'wp-native-client';
import { WpApiFetchTransport } from 'wp-native-client/wordpress';
import { BlockShell, BlockShellInner, ResponsiveTabs, Panel, PanelHeader, ActionRow, FieldGroup, BlockShellHeader, BlockIntro } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';
import { cssVar, spacing, colors } from '@extrachill/tokens';
import type {
	UserSettings,
	UserProfile,
	ChangeEmailResponse,
	ChangePasswordResponse,
	UserSubscriptions,
	FollowedArtist,
	RequestArtistAccessResponse,
	NotificationPreferences,
} from '../../types/users';

const client = new WPNativeClient( new WpApiFetchTransport( apiFetch ), { validateAbilityNames: false } );
const LOCATION_SEARCH_DEBOUNCE_MS = 250;

interface EventLocation {
	term_id: number;
	name: string;
	slug: string;
	archive_url: string;
	coordinates: { lat: number; lon: number } | null;
	hierarchy: {
		region: string;
		state: string;
		label: string;
	};
}

interface EventLocationsResponse {
	locations: EventLocation[];
	location: EventLocation | null;
}

const styles = {
	button: {
		opacity: 0.7,
	},
	checkboxList: { listStyle: 'none', padding: 0, margin: 0 },
	checkboxItem: {
		padding: `${ cssVar( spacing.spacingSm ) } 0`,
		borderBottom: `1px solid ${ cssVar( colors.borderColor ) }`,
		display: 'flex',
		alignItems: 'center',
		gap: cssVar( spacing.spacingSm ),
	},
	mutedText: { color: cssVar( colors.mutedText ) },
} as const;

function Notice( { type, message }: { type: 'success' | 'error'; message: string } ) {
	return (
		<div className={ `notice notice-${ type }` }>
			<p>{ message }</p>
		</div>
	);
}

function AccountTab( { settings, onUpdate }: { settings: UserSettings; onUpdate: ( updated: UserSettings ) => void } ) {
	const [ firstName, setFirstName ] = useState( settings.first_name );
	const [ lastName, setLastName ] = useState( settings.last_name );
	const [ displayName, setDisplayName ] = useState( settings.display_name );
	const [ defaultEventLocationSlug, setDefaultEventLocationSlug ] = useState< string | null >( settings.default_event_location?.slug ?? null );
	const [ locationOptions, setLocationOptions ] = useState< Array<{ label: string; value: string }> >( settings.default_event_location ? [ { label: settings.default_event_location.name, value: settings.default_event_location.slug } ] : [] );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState< { type: 'success' | 'error'; message: string } | null >( null );
	const locationSearchTimeout = useRef< number | null >( null );
	const locationSearchRequest = useRef( 0 );

	const searchLocations = useCallback( ( search: string ) => {
		const request = ++locationSearchRequest.current;
		if ( locationSearchTimeout.current !== null ) {
			window.clearTimeout( locationSearchTimeout.current );
		}
		const trimmed = search.trim();
		if ( ! trimmed ) {
			setLocationOptions( settings.default_event_location ? [ { label: settings.default_event_location.name, value: settings.default_event_location.slug } ] : [] );
			return;
		}

		locationSearchTimeout.current = window.setTimeout( () => {
			client.execute< EventLocationsResponse >( 'extrachill/events-locations', { mode: 'search', search: trimmed, limit: 10 } )
				.then( ( result ) => {
					if ( request === locationSearchRequest.current ) {
						setLocationOptions( result.locations.map( ( location ) => ( { label: location.hierarchy.label, value: location.slug } ) ) );
					}
				} )
				.catch( () => {
					if ( request === locationSearchRequest.current ) setLocationOptions( [] );
				} );
		}, LOCATION_SEARCH_DEBOUNCE_MS );

	}, [ settings.default_event_location ] );

	useEffect( () => () => {
		if ( locationSearchTimeout.current !== null ) {
			window.clearTimeout( locationSearchTimeout.current );
		}
	}, [] );

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			const result = await client.execute< UserSettings & { message?: string } >( 'extrachill/update-user-settings', { first_name: firstName, last_name: lastName, display_name: displayName, default_event_location: defaultEventLocationSlug ?? '' } );
			onUpdate( result );
			setDefaultEventLocationSlug( result.default_event_location?.slug ?? null );
			setNotice( { type: 'success', message: result.message || 'Account details updated.' } );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Update failed.' } );
		}
		setSaving( false );
	}, [ firstName, lastName, displayName, defaultEventLocationSlug, onUpdate ] );

	return (
		<Panel>
			{ notice && <Notice type={ notice.type } message={ notice.message } /> }
			<FieldGroup label="First Name" htmlFor="ec-first-name">
				<input id="ec-first-name" type="text" value={ firstName } onChange={ ( e ) => setFirstName( e.target.value ) } />
			</FieldGroup>
			<FieldGroup label="Last Name" htmlFor="ec-last-name">
				<input id="ec-last-name" type="text" value={ lastName } onChange={ ( e ) => setLastName( e.target.value ) } />
			</FieldGroup>
			<FieldGroup label="Display Name" htmlFor="ec-display-name">
				<select id="ec-display-name" value={ displayName } onChange={ ( e ) => setDisplayName( e.target.value ) }>
					{ settings.display_name_options.map( ( option ) => <option key={ option } value={ option }>{ option }</option> ) }
				</select>
			</FieldGroup>
			<FieldGroup help="Used when you have not chosen an event location or shared your browser location. This does not change your public Local Scene.">
				<ComboboxControl
					label="Default Event Market"
					value={ defaultEventLocationSlug }
					options={ locationOptions }
					onFilterValueChange={ searchLocations }
					onChange={ ( slug ) => setDefaultEventLocationSlug( slug ?? null ) }
					allowReset={ true }
					placeholder="Search event markets"
				/>
			</FieldGroup>
			<ActionRow>
				<button className="button-1 button-small" style={ saving ? styles.button : undefined } onClick={ handleSave } disabled={ saving }>{ saving ? 'Saving...' : 'Save Account Details' }</button>
			</ActionRow>
		</Panel>
	);
}

function SecurityTab( { settings, onSettingsChange }: { settings: UserSettings; onSettingsChange: ( updated: UserSettings ) => void } ) {
	const [ newEmail, setNewEmail ] = useState( '' );
	const [ currentPassword, setCurrentPassword ] = useState( '' );
	const [ newPassword, setNewPassword ] = useState( '' );
	const [ confirmPassword, setConfirmPassword ] = useState( '' );
	const [ emailSaving, setEmailSaving ] = useState( false );
	const [ passwordSaving, setPasswordSaving ] = useState( false );
	const [ notice, setNotice ] = useState< { type: 'success' | 'error'; message: string } | null >( null );

	const handleEmailChange = useCallback( async () => {
		setEmailSaving( true );
		setNotice( null );
		try {
			const result = await client.execute< ChangeEmailResponse >( 'extrachill/change-user-email', { new_email: newEmail } );
			setNotice( { type: 'success', message: result.message } );
			setNewEmail( '' );
			onSettingsChange( { ...settings, pending_email: result.pending_email } );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Email change failed.' } );
		}
		setEmailSaving( false );
	}, [ newEmail, settings, onSettingsChange ] );

	const handlePasswordChange = useCallback( async () => {
		setPasswordSaving( true );
		setNotice( null );
		try {
			const result = await client.execute< ChangePasswordResponse >( 'extrachill/change-user-password', { current_password: currentPassword, new_password: newPassword, confirm_password: confirmPassword } );
			setNotice( { type: 'success', message: result.message } );
			setCurrentPassword( '' );
			setNewPassword( '' );
			setConfirmPassword( '' );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Password change failed.' } );
		}
		setPasswordSaving( false );
	}, [ currentPassword, newPassword, confirmPassword ] );

	return (
		<Panel>
			{ notice && <Notice type={ notice.type } message={ notice.message } /> }
			<FieldGroup label="Current Email Address">
				<input type="email" value={ settings.email } disabled />
				{ settings.pending_email && <div className="notice notice-info"><p>Email change pending - verification sent to <strong>{ settings.pending_email }</strong></p><p><small>Check your inbox and click the verification link.</small></p></div> }
			</FieldGroup>
			<FieldGroup label="New Email Address" htmlFor="ec-new-email" help="A verification email will be sent to your new address. Your current email will remain active until verification is complete.">
				<input id="ec-new-email" type="email" value={ newEmail } onChange={ ( e ) => setNewEmail( e.target.value ) } placeholder="Enter new email address" />
			</FieldGroup>
			<ActionRow><button className="button-1 button-small" style={ emailSaving || ! newEmail ? styles.button : undefined } onClick={ handleEmailChange } disabled={ emailSaving || ! newEmail }>{ emailSaving ? 'Sending...' : 'Change Email' }</button></ActionRow>
			<hr style={ { border: 'none', borderTop: `1px solid ${ cssVar( colors.borderColor ) }`, margin: `${ cssVar( spacing.spacingLg ) } 0` } } />
			<FieldGroup label="Current Password" htmlFor="ec-current-pass" required>
				<input id="ec-current-pass" type="password" value={ currentPassword } onChange={ ( e ) => setCurrentPassword( e.target.value ) } autoComplete="current-password" />
			</FieldGroup>
			<FieldGroup label="New Password" htmlFor="ec-new-pass">
				<input id="ec-new-pass" type="password" value={ newPassword } onChange={ ( e ) => setNewPassword( e.target.value ) } autoComplete="new-password" />
			</FieldGroup>
			<FieldGroup label="Confirm New Password" htmlFor="ec-confirm-pass">
				<input id="ec-confirm-pass" type="password" value={ confirmPassword } onChange={ ( e ) => setConfirmPassword( e.target.value ) } autoComplete="new-password" />
			</FieldGroup>
			<ActionRow><button className="button-1 button-small" style={ passwordSaving || ! currentPassword || ! newPassword ? styles.button : undefined } onClick={ handlePasswordChange } disabled={ passwordSaving || ! currentPassword || ! newPassword }>{ passwordSaving ? 'Changing...' : 'Change Password' }</button></ActionRow>
		</Panel>
	);
}

function SubscriptionsTab() {
	const [ data, setData ] = useState< UserSubscriptions | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState< { type: 'success' | 'error'; message: string } | null >( null );
	const [ consented, setConsented ] = useState< Set<number> >( new Set() );

	useEffect( () => {
		client.execute< UserSubscriptions >( 'extrachill/get-subscriptions' ).then( ( result ) => {
			setData( result );
			const ids = new Set<number>();
			result.followed_artists.forEach( ( a: FollowedArtist ) => { if ( a.email_consent ) ids.add( a.artist_id ); } );
			setConsented( ids );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [] );

	const toggleConsent = useCallback( ( artistId: number ) => {
		setConsented( ( prev ) => {
			const next = new Set( prev );
			next.has( artistId ) ? next.delete( artistId ) : next.add( artistId );
			return next;
		} );
	}, [] );

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			await client.execute( 'extrachill/update-subscriptions', { consented_artists: Array.from( consented ) } );
			setNotice( { type: 'success', message: 'Subscription preferences updated.' } );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Update failed.' } );
		}
		setSaving( false );
	}, [ consented ] );

	if ( loading ) return <div className="notice notice-info"><p>Loading subscriptions...</p></div>;
	const artists = data?.followed_artists || [];

	return (
		<Panel>
			<PanelHeader description="Manage email consent for bands you follow. Unchecking will prevent a band from seeing your email or including it in their exports." />
			{ notice && <Notice type={ notice.type } message={ notice.message } /> }
			{ artists.length === 0 ? <p style={ styles.mutedText }>You are not currently following any bands.</p> : <>
				<ul style={ styles.checkboxList }>
					{ artists.map( ( artist: FollowedArtist ) => <li key={ artist.artist_id } style={ styles.checkboxItem }><input type="checkbox" id={ `ec-consent-${ artist.artist_id }` } checked={ consented.has( artist.artist_id ) } onChange={ () => toggleConsent( artist.artist_id ) } /><label htmlFor={ `ec-consent-${ artist.artist_id }` } style={ { fontWeight: 'normal', cursor: 'pointer' } }>Share my email with <a href={ artist.url } target="_blank" rel="noopener noreferrer" style={ { color: cssVar( colors.linkColor ) } }>{ artist.name }</a></label></li> ) }
				</ul>
				<ActionRow><button className="button-1 button-small" style={ saving ? styles.button : undefined } onClick={ handleSave } disabled={ saving }>{ saving ? 'Saving...' : 'Save Preferences' }</button></ActionRow>
			</>}
		</Panel>
	);
}

function NotificationsTab() {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ emailsEnabled, setEmailsEnabled ] = useState( true );
	const [ autoSubscribe, setAutoSubscribe ] = useState( true );
	const [ notice, setNotice ] = useState< { type: 'success' | 'error'; message: string } | null >( null );

	useEffect( () => {
		client.execute< NotificationPreferences >( 'extrachill/get-notification-preferences' ).then( ( result ) => {
			setEmailsEnabled( result.emails_enabled );
			setAutoSubscribe( result.auto_subscribe_replies );
			setLoading( false );
		} ).catch( () => setLoading( false ) );
	}, [] );

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			await client.execute( 'extrachill/update-notification-preferences', { emails_enabled: emailsEnabled, auto_subscribe_replies: autoSubscribe } );
			setNotice( { type: 'success', message: 'Notification preferences updated.' } );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Update failed.' } );
		}
		setSaving( false );
	}, [ emailsEnabled, autoSubscribe ] );

	if ( loading ) return <div className="notice notice-info"><p>Loading notification preferences...</p></div>;

	return (
		<Panel>
			<PanelHeader description="Control how Extra Chill keeps you in the loop." />
			{ notice && <Notice type={ notice.type } message={ notice.message } /> }
			<ul style={ styles.checkboxList }>
				<li style={ styles.checkboxItem }>
					<input type="checkbox" id="ec-notif-emails" checked={ emailsEnabled } onChange={ ( e ) => setEmailsEnabled( e.target.checked ) } />
					<label htmlFor="ec-notif-emails" style={ { fontWeight: 'normal', cursor: 'pointer' } }>
						<strong>Email notifications</strong>
						<div style={ styles.mutedText }>Receive email digests for your notifications.</div>
					</label>
				</li>
				<li style={ styles.checkboxItem }>
					<input type="checkbox" id="ec-notif-auto-subscribe" checked={ autoSubscribe } onChange={ ( e ) => setAutoSubscribe( e.target.checked ) } />
					<label htmlFor="ec-notif-auto-subscribe" style={ { fontWeight: 'normal', cursor: 'pointer' } }>
						<strong>Auto-subscribe to replies</strong>
						<div style={ styles.mutedText }>Automatically follow topics you reply to.</div>
					</label>
				</li>
			</ul>
			<ActionRow><button className="button-1 button-small" style={ saving ? styles.button : undefined } onClick={ handleSave } disabled={ saving }>{ saving ? 'Saving...' : 'Save Preferences' }</button></ActionRow>
		</Panel>
	);
}

function ArtistPlatformTab( { artistAccess, artistSiteUrl, hasArtists, canCreateArtists }: { artistAccess: { status: string; type: string; request_type?: string; requested_at?: number }; artistSiteUrl: string; hasArtists: boolean; canCreateArtists: boolean } ) {
	const [ accessType, setAccessType ] = useState<'artist' | 'professional'>( 'artist' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ notice, setNotice ] = useState< { type: 'success' | 'error'; message: string } | null >( null );
	const [ currentStatus, setCurrentStatus ] = useState( artistAccess.status );

	const handleRequest = useCallback( async () => {
		setSubmitting( true );
		setNotice( null );
		try {
			const result = await client.execute< RequestArtistAccessResponse >( 'extrachill/request-artist-access', { type: accessType } );
			setNotice( { type: 'success', message: result.message } );
			setCurrentStatus( 'pending' );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Request failed.' } );
		}
		setSubmitting( false );
	}, [ accessType ] );

	return (
		<Panel>
			{ notice && <Notice type={ notice.type } message={ notice.message } /> }
			{ currentStatus === 'approved' && <div className="notice notice-success"><p><strong>You have artist platform access!</strong></p><p>You can create artist profiles and link pages on extrachill.link.</p>{ hasArtists ? <ActionRow><a href={ `${ artistSiteUrl }/manage-artist/` } className="button-1 button-small">Manage Artist</a></ActionRow> : canCreateArtists ? <ActionRow><a href={ `${ artistSiteUrl }/create-artist/` } className="button-1 button-small">Create Artist Profile</a></ActionRow> : null }</div> }
			{ currentStatus === 'pending' && <div className="notice notice-info"><p><strong>Your request is pending admin review.</strong></p>{ artistAccess.request_type && <p>You requested access as "{ artistAccess.request_type === 'artist' ? 'I am a musician' : 'I work in the music industry' }"{ artistAccess.requested_at ? ` on ${ new Date( artistAccess.requested_at * 1000 ).toLocaleDateString() }` : '' }.</p> }<p>An administrator will review your request shortly.</p></div> }
			{ currentStatus === 'none' && <><p style={ { ...styles.mutedText, marginBottom: cssVar( spacing.spacingMd ) } }>Get access to create artist profiles and link pages on extrachill.link.</p><fieldset><legend>Select which best describes you:</legend><p><label><input type="radio" name="ec-artist-access-type" value="artist" checked={ accessType === 'artist' } onChange={ () => setAccessType( 'artist' ) } />I am a musician</label></p><p><label><input type="radio" name="ec-artist-access-type" value="professional" checked={ accessType === 'professional' } onChange={ () => setAccessType( 'professional' ) } />I work in the music industry</label></p></fieldset><ActionRow><button className="button-1 button-small" style={ submitting ? styles.button : undefined } onClick={ handleRequest } disabled={ submitting }>{ submitting ? 'Submitting...' : 'Request Access' }</button></ActionRow></> }
		</Panel>
	);
}

type TabId = 'account-details' | 'security' | 'subscriptions' | 'notifications' | 'artist-platform';

function UserSettingsApp( { artistSiteUrl, hasArtists, canCreateArtists, userId }: { artistSiteUrl: string; hasArtists: boolean; canCreateArtists: boolean; userId: number } ) {
	const [ activeTab, setActiveTab ] = useState<TabId>( 'account-details' );
	const [ settings, setSettings ] = useState<UserSettings | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ artistAccess, setArtistAccess ] = useState<{ status: string; type: string; request_type?: string; requested_at?: number }>( { status: 'none', type: '' } );

	useEffect( () => {
		Promise.all( [
			client.execute< UserSettings >( 'extrachill/get-user-settings' ),
			client.execute< UserProfile >( 'extrachill/get-user-profile', { user_id: userId } ),
		] ).then( ( [ settingsData, profileData ] ) => {
			setSettings( settingsData );
			setArtistAccess( profileData.artist_access );
			setLoading( false );
		} ).catch( ( err ) => {
			setError( err instanceof Error ? err.message : 'Failed to load settings.' );
			setLoading( false );
		} );
	}, [] );

	const switchTab = useCallback( ( tab: TabId ) => { setActiveTab( tab ); }, [] );
	if ( loading ) return <div className="notice notice-info"><p>Loading settings...</p></div>;
	if ( error || ! settings ) return <Notice type="error" message={ error || 'Failed to load settings.' } />;

	const tabs: Array<{ id: TabId; label: string }> = [
		{ id: 'account-details', label: 'Account Details' },
		{ id: 'security', label: 'Security' },
		{ id: 'subscriptions', label: 'Subscriptions' },
		{ id: 'notifications', label: 'Notifications' },
		{ id: 'artist-platform', label: 'Artist Platform' },
	];

	const renderTabPanel = ( id: string ) => {
		switch ( id as TabId ) {
			case 'account-details':
				return <AccountTab settings={ settings } onUpdate={ setSettings } />;
			case 'security':
				return <SecurityTab settings={ settings } onSettingsChange={ setSettings } />;
			case 'subscriptions':
				return <SubscriptionsTab />;
			case 'notifications':
				return <NotificationsTab />;
			case 'artist-platform':
				return <ArtistPlatformTab artistAccess={ artistAccess } artistSiteUrl={ artistSiteUrl } hasArtists={ hasArtists } canCreateArtists={ canCreateArtists } />;
			default:
				return null;
		}
	};

	return (
		<BlockShell>
			<BlockShellInner maxWidth="narrow">
				<BlockShellHeader title="Settings" description="Manage your account, security, subscriptions, notifications, and artist platform access." />
				<ResponsiveTabs
					tabs={ tabs }
					active={ activeTab }
					onChange={ ( id ) => switchTab( id as TabId ) }
					renderPanel={ renderTabPanel }
					syncWithHash={ true }
					showDesktopTabs={ true }
				/>
			</BlockShellInner>
		</BlockShell>
	);
}

function init(): void {
	document.querySelectorAll<HTMLElement>( '.wp-block-extrachill-user-settings' ).forEach( ( container ) => {
		if ( container.dataset.initialized === '1' ) return;
		container.dataset.initialized = '1';
		const artistSiteUrl = container.dataset.artistSiteUrl || 'https://artist.extrachill.com';
		const hasArtists = container.dataset.hasArtists === '1';
		const canCreateArtists = container.dataset.canCreateArtists === '1';
		const userId = Number( container.dataset.userId || '0' );
		const root = createRoot( container );
		root.render( <UserSettingsApp artistSiteUrl={ artistSiteUrl } hasArtists={ hasArtists } canCreateArtists={ canCreateArtists } userId={ userId } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
