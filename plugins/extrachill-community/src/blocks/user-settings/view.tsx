import { useState, useEffect, useCallback } from '@wordpress/element';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
import { BlockShell, BlockShellInner, ResponsiveTabs, Panel, PanelHeader, ActionRow, FieldGroup, InlineStatus, BlockShellHeader } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';
import { cssVar, spacing, colors, fontSize } from '@extrachill/tokens';
import type {
	UserSettings,
	ChangeEmailResponse,
	ChangePasswordResponse,
	UserSubscriptions,
	FollowedArtist,
	RequestArtistAccessResponse,
} from '@extrachill/api-client';

const client = new ExtraChillClient( new WpApiFetchTransport( apiFetch ) );

const styles = {
	container: { width: '100%' },
	input: {
		width: '100%',
		maxWidth: '400px',
		padding: '8px',
		border: `1px solid ${ cssVar( colors.borderColor ) }`,
		borderRadius: '3px',
		backgroundColor: cssVar( colors.backgroundColor ),
		color: cssVar( colors.textColor ),
		fontSize: cssVar( fontSize.fontSizeBase ),
		boxSizing: 'border-box' as const,
	},
	disabledInput: { cursor: 'not-allowed', opacity: 0.6 },
	select: {
		width: '100%',
		maxWidth: '400px',
		padding: '8px',
		border: `1px solid ${ cssVar( colors.borderColor ) }`,
		borderRadius: '3px',
		backgroundColor: cssVar( colors.backgroundColor ),
		color: cssVar( colors.textColor ),
		fontSize: cssVar( fontSize.fontSizeBase ),
	},
	button: {
		padding: '10px 20px',
		border: 'none',
		borderRadius: '3px',
		cursor: 'pointer',
		fontWeight: 600,
		fontSize: cssVar( fontSize.fontSizeBase ),
		backgroundColor: cssVar( colors.linkColor ),
		color: '#fff',
	},
	pendingBadge: {
		display: 'inline-block',
		marginTop: '8px',
		padding: '6px 10px',
		borderRadius: '3px',
		backgroundColor: '#fff3cd',
		border: '1px solid #ffeaa7',
		color: '#856404',
		fontSize: cssVar( fontSize.fontSizeSm ),
	},
	checkboxList: { listStyle: 'none', padding: 0, margin: 0 },
	checkboxItem: {
		padding: `${ cssVar( spacing.spacingSm ) } 0`,
		borderBottom: `1px solid ${ cssVar( colors.borderColor ) }`,
		display: 'flex',
		alignItems: 'center',
		gap: cssVar( spacing.spacingSm ),
	},
	artistAccessGranted: {
		padding: cssVar( spacing.spacingMd ),
		borderRadius: '3px',
		backgroundColor: 'rgba(46, 125, 50, 0.05)',
		border: '1px solid rgba(46, 125, 50, 0.2)',
	},
	radioGroup: {
		display: 'flex',
		flexDirection: 'column' as const,
		gap: cssVar( spacing.spacingSm ),
		marginBottom: cssVar( spacing.spacingMd ),
	},
	radioLabel: {
		display: 'flex',
		alignItems: 'center',
		gap: cssVar( spacing.spacingSm ),
		cursor: 'pointer',
	},
	mutedText: { color: cssVar( colors.mutedText ) },
} as const;

function Notice( { type, message }: { type: 'success' | 'error'; message: string } ) {
	return <InlineStatus tone={ type }>{ message }</InlineStatus>;
}

function AccountTab( { settings, onUpdate }: { settings: UserSettings; onUpdate: ( updated: UserSettings ) => void } ) {
	const [ firstName, setFirstName ] = useState( settings.first_name );
	const [ lastName, setLastName ] = useState( settings.last_name );
	const [ displayName, setDisplayName ] = useState( settings.display_name );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState< { type: 'success' | 'error'; message: string } | null >( null );

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			const result = await client.users.updateSettings( { first_name: firstName, last_name: lastName, display_name: displayName } );
			onUpdate( result );
			setNotice( { type: 'success', message: result.message || 'Account details updated.' } );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Update failed.' } );
		}
		setSaving( false );
	}, [ firstName, lastName, displayName, onUpdate ] );

	return (
		<Panel depth={ 1 } className="ec-edge-surface">
			{ notice && <Notice type={ notice.type } message={ notice.message } /> }
			<FieldGroup label="First Name" htmlFor="ec-first-name">
				<input id="ec-first-name" type="text" style={ styles.input } value={ firstName } onChange={ ( e ) => setFirstName( e.target.value ) } />
			</FieldGroup>
			<FieldGroup label="Last Name" htmlFor="ec-last-name">
				<input id="ec-last-name" type="text" style={ styles.input } value={ lastName } onChange={ ( e ) => setLastName( e.target.value ) } />
			</FieldGroup>
			<FieldGroup label="Display Name" htmlFor="ec-display-name">
				<select id="ec-display-name" style={ styles.select } value={ displayName } onChange={ ( e ) => setDisplayName( e.target.value ) }>
					{ settings.display_name_options.map( ( option ) => <option key={ option } value={ option }>{ option }</option> ) }
				</select>
			</FieldGroup>
			<ActionRow>
				<button style={ { ...styles.button, opacity: saving ? 0.7 : 1 } } onClick={ handleSave } disabled={ saving }>{ saving ? 'Saving...' : 'Save Account Details' }</button>
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
			const result: ChangeEmailResponse = await client.users.changeEmail( { new_email: newEmail } );
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
			const result: ChangePasswordResponse = await client.users.changePassword( { current_password: currentPassword, new_password: newPassword, confirm_password: confirmPassword } );
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
		<Panel depth={ 1 } className="ec-edge-surface">
			{ notice && <Notice type={ notice.type } message={ notice.message } /> }
			<FieldGroup label="Current Email Address">
				<input type="email" style={ { ...styles.input, ...styles.disabledInput } } value={ settings.email } disabled />
				{ settings.pending_email && <div style={ styles.pendingBadge }><div>Email change pending - verification sent to <strong>{ settings.pending_email }</strong></div><small>Check your inbox and click the verification link.</small></div> }
			</FieldGroup>
			<FieldGroup label="New Email Address" htmlFor="ec-new-email" help="A verification email will be sent to your new address. Your current email will remain active until verification is complete.">
				<input id="ec-new-email" type="email" style={ styles.input } value={ newEmail } onChange={ ( e ) => setNewEmail( e.target.value ) } placeholder="Enter new email address" />
			</FieldGroup>
			<ActionRow><button style={ { ...styles.button, opacity: emailSaving || ! newEmail ? 0.7 : 1 } } onClick={ handleEmailChange } disabled={ emailSaving || ! newEmail }>{ emailSaving ? 'Sending...' : 'Change Email' }</button></ActionRow>
			<hr style={ { border: 'none', borderTop: `1px solid ${ cssVar( colors.borderColor ) }`, margin: `${ cssVar( spacing.spacingLg ) } 0` } } />
			<FieldGroup label="Current Password" htmlFor="ec-current-pass" required>
				<input id="ec-current-pass" type="password" style={ styles.input } value={ currentPassword } onChange={ ( e ) => setCurrentPassword( e.target.value ) } autoComplete="current-password" />
			</FieldGroup>
			<FieldGroup label="New Password" htmlFor="ec-new-pass">
				<input id="ec-new-pass" type="password" style={ styles.input } value={ newPassword } onChange={ ( e ) => setNewPassword( e.target.value ) } autoComplete="new-password" />
			</FieldGroup>
			<FieldGroup label="Confirm New Password" htmlFor="ec-confirm-pass">
				<input id="ec-confirm-pass" type="password" style={ styles.input } value={ confirmPassword } onChange={ ( e ) => setConfirmPassword( e.target.value ) } autoComplete="new-password" />
			</FieldGroup>
			<ActionRow><button style={ { ...styles.button, opacity: passwordSaving || ! currentPassword || ! newPassword ? 0.7 : 1 } } onClick={ handlePasswordChange } disabled={ passwordSaving || ! currentPassword || ! newPassword }>{ passwordSaving ? 'Changing...' : 'Change Password' }</button></ActionRow>
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
		client.users.getSubscriptions().then( ( result ) => {
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
			await client.users.updateSubscriptions( { consented_artists: Array.from( consented ) } );
			setNotice( { type: 'success', message: 'Subscription preferences updated.' } );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Update failed.' } );
		}
		setSaving( false );
	}, [ consented ] );

	if ( loading ) return <div style={ styles.mutedText }>Loading subscriptions...</div>;
	const artists = data?.followed_artists || [];

	return (
		<Panel depth={ 1 } className="ec-edge-surface">
			<PanelHeader description="Manage email consent for bands you follow. Unchecking will prevent a band from seeing your email or including it in their exports." />
			{ notice && <Notice type={ notice.type } message={ notice.message } /> }
			{ artists.length === 0 ? <p style={ styles.mutedText }>You are not currently following any bands.</p> : <>
				<ul style={ styles.checkboxList }>
					{ artists.map( ( artist: FollowedArtist ) => <li key={ artist.artist_id } style={ styles.checkboxItem }><input type="checkbox" id={ `ec-consent-${ artist.artist_id }` } checked={ consented.has( artist.artist_id ) } onChange={ () => toggleConsent( artist.artist_id ) } /><label htmlFor={ `ec-consent-${ artist.artist_id }` } style={ { fontWeight: 'normal', cursor: 'pointer' } }>Share my email with <a href={ artist.url } target="_blank" rel="noopener noreferrer" style={ { color: cssVar( colors.linkColor ) } }>{ artist.name }</a></label></li> ) }
				</ul>
				<ActionRow><button style={ { ...styles.button, opacity: saving ? 0.7 : 1 } } onClick={ handleSave } disabled={ saving }>{ saving ? 'Saving...' : 'Save Preferences' }</button></ActionRow>
			</>}
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
			const result: RequestArtistAccessResponse = await client.users.requestArtistAccess( { type: accessType } );
			setNotice( { type: 'success', message: result.message } );
			setCurrentStatus( 'pending' );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Request failed.' } );
		}
		setSubmitting( false );
	}, [ accessType ] );

	return (
		<Panel depth={ 1 } className="ec-edge-surface">
			{ notice && <Notice type={ notice.type } message={ notice.message } /> }
			{ currentStatus === 'approved' && <div style={ styles.artistAccessGranted }><p><strong>You have artist platform access!</strong></p><p>You can create artist profiles and link pages on extrachill.link.</p>{ hasArtists ? <ActionRow><a href={ `${ artistSiteUrl }/manage-artist/` } style={ { ...styles.button, display: 'inline-block', textDecoration: 'none' } }>Manage Artist</a></ActionRow> : canCreateArtists ? <ActionRow><a href={ `${ artistSiteUrl }/create-artist/` } style={ { ...styles.button, display: 'inline-block', textDecoration: 'none' } }>Create Artist Profile</a></ActionRow> : null }</div> }
			{ currentStatus === 'pending' && <div style={ styles.pendingBadge }><p><strong>Your request is pending admin review.</strong></p>{ artistAccess.request_type && <p>You requested access as "{ artistAccess.request_type === 'artist' ? 'I am a musician' : 'I work in the music industry' }"{ artistAccess.requested_at ? ` on ${ new Date( artistAccess.requested_at * 1000 ).toLocaleDateString() }` : '' }.</p> }<p>An administrator will review your request shortly.</p></div> }
			{ currentStatus === 'none' && <><p style={ { ...styles.mutedText, marginBottom: cssVar( spacing.spacingMd ) } }>Get access to create artist profiles and link pages on extrachill.link.</p><div style={ styles.radioGroup }><p style={ { fontWeight: 600, marginBottom: '4px' } }>Select which best describes you:</p><label style={ styles.radioLabel }><input type="radio" name="ec-artist-access-type" value="artist" checked={ accessType === 'artist' } onChange={ () => setAccessType( 'artist' ) } />I am a musician</label><label style={ styles.radioLabel }><input type="radio" name="ec-artist-access-type" value="professional" checked={ accessType === 'professional' } onChange={ () => setAccessType( 'professional' ) } />I work in the music industry</label></div><ActionRow><button style={ { ...styles.button, opacity: submitting ? 0.7 : 1 } } onClick={ handleRequest } disabled={ submitting }>{ submitting ? 'Submitting...' : 'Request Access' }</button></ActionRow></> }
		</Panel>
	);
}

type TabId = 'account-details' | 'security' | 'subscriptions' | 'artist-platform';

function UserSettingsApp( { artistSiteUrl, hasArtists, canCreateArtists }: { artistSiteUrl: string; hasArtists: boolean; canCreateArtists: boolean } ) {
	const [ activeTab, setActiveTab ] = useState<TabId>( 'account-details' );
	const [ settings, setSettings ] = useState<UserSettings | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ artistAccess, setArtistAccess ] = useState<{ status: string; type: string; request_type?: string; requested_at?: number }>( { status: 'none', type: '' } );

	useEffect( () => {
		Promise.all( [ client.users.getSettings(), client.users.getProfile() ] ).then( ( [ settingsData, profileData ] ) => {
			setSettings( settingsData );
			setArtistAccess( profileData.artist_access );
			setLoading( false );
		} ).catch( ( err ) => {
			setError( err instanceof Error ? err.message : 'Failed to load settings.' );
			setLoading( false );
		} );
	}, [] );

	const switchTab = useCallback( ( tab: TabId ) => { setActiveTab( tab ); }, [] );
	if ( loading ) return <InlineStatus tone="info">Loading settings...</InlineStatus>;
	if ( error || ! settings ) return <Notice type="error" message={ error || 'Failed to load settings.' } />;

	const tabs: Array<{ id: TabId; label: string }> = [
		{ id: 'account-details', label: 'Account Details' },
		{ id: 'security', label: 'Security' },
		{ id: 'subscriptions', label: 'Subscriptions' },
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
			case 'artist-platform':
				return <ArtistPlatformTab artistAccess={ artistAccess } artistSiteUrl={ artistSiteUrl } hasArtists={ hasArtists } canCreateArtists={ canCreateArtists } />;
			default:
				return null;
		}
	};

	return (
		<BlockShell className="ec-community-settings-shell">
			<BlockShellInner className="ec-block-shell-inner--centered" maxWidth="narrow">
				<BlockShellHeader title="Settings" description="Manage your account, security, subscriptions, and artist platform access." />
				<ResponsiveTabs
					tabs={ tabs }
					active={ activeTab }
					onChange={ ( id ) => switchTab( id as TabId ) }
					renderPanel={ renderTabPanel }
					syncWithHash={ true }
					className="ec-community-settings-tabs"
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
		const root = createRoot( container );
		root.render( <UserSettingsApp artistSiteUrl={ artistSiteUrl } hasArtists={ hasArtists } canCreateArtists={ canCreateArtists } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
