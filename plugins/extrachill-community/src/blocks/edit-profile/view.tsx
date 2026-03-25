import { useState, useEffect, useCallback } from '@wordpress/element';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
import { Tabs, Panel, PanelHeader, ActionRow, FieldGroup, InlineStatus } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';
import { cssVar, spacing, colors, fontSize } from '@extrachill/tokens';
import type { UserProfile, UserLink } from '@extrachill/api-client';

const client = new ExtraChillClient( new WpApiFetchTransport( apiFetch ) );

const styles = {
	container: { maxWidth: '700px' },
	tabsWrapper: { marginBottom: cssVar( spacing.spacingLg ) },
	input: {
		width: '100%', maxWidth: '400px', padding: '8px', border: `1px solid ${ cssVar( colors.borderColor ) }`, borderRadius: '3px', backgroundColor: cssVar( colors.backgroundColor ), color: cssVar( colors.textColor ), fontSize: cssVar( fontSize.fontSizeBase ), boxSizing: 'border-box' as const,
	},
	textarea: {
		width: '100%', padding: '8px', border: `1px solid ${ cssVar( colors.borderColor ) }`, borderRadius: '3px', backgroundColor: cssVar( colors.backgroundColor ), color: cssVar( colors.textColor ), fontSize: cssVar( fontSize.fontSizeBase ), boxSizing: 'border-box' as const, minHeight: '120px', resize: 'vertical' as const, fontFamily: 'inherit',
	},
	button: { padding: '10px 20px', border: 'none', borderRadius: '3px', cursor: 'pointer', fontWeight: 600, fontSize: cssVar( fontSize.fontSizeBase ), backgroundColor: cssVar( colors.linkColor ), color: '#fff' },
	secondaryButton: { padding: '8px 16px', border: `1px solid ${ cssVar( colors.borderColor ) }`, borderRadius: '3px', cursor: 'pointer', fontWeight: 500, fontSize: cssVar( fontSize.fontSizeSm ), backgroundColor: 'transparent', color: cssVar( colors.textColor ) },
	dangerButton: { padding: '6px 12px', border: 'none', borderRadius: '3px', cursor: 'pointer', fontWeight: 500, fontSize: cssVar( fontSize.fontSizeSm ), backgroundColor: 'rgba(211, 47, 47, 0.1)', color: '#d32f2f' },
	avatarContainer: { display: 'flex', alignItems: 'center', gap: cssVar( spacing.spacingMd ), marginBottom: cssVar( spacing.spacingMd ) },
	avatar: { width: '80px', height: '80px', borderRadius: '50%', objectFit: 'cover' as const, border: `2px solid ${ cssVar( colors.borderColor ) }` },
	linkRow: { display: 'flex', gap: cssVar( spacing.spacingSm ), alignItems: 'flex-start', marginBottom: cssVar( spacing.spacingSm ), flexWrap: 'wrap' as const },
	linkSelect: { padding: '8px', border: `1px solid ${ cssVar( colors.borderColor ) }`, borderRadius: '3px', backgroundColor: cssVar( colors.backgroundColor ), color: cssVar( colors.textColor ), fontSize: cssVar( fontSize.fontSizeBase ), minWidth: '140px' },
	linkInput: { flex: 1, minWidth: '200px', padding: '8px', border: `1px solid ${ cssVar( colors.borderColor ) }`, borderRadius: '3px', backgroundColor: cssVar( colors.backgroundColor ), color: cssVar( colors.textColor ), fontSize: cssVar( fontSize.fontSizeBase ), boxSizing: 'border-box' as const },
	mutedText: { color: cssVar( colors.mutedText ) },
} as const;

function Notice( { type, message }: { type: 'success' | 'error'; message: string } ) {
	return <InlineStatus tone={ type }>{ message }</InlineStatus>;
}

function AvatarUpload( { avatarUrl, userId, onAvatarChange }: { avatarUrl: string; userId: number; onAvatarChange: ( url: string ) => void } ) {
	const [ uploading, setUploading ] = useState( false );
	const handleFileChange = useCallback( async ( e: React.ChangeEvent<HTMLInputElement> ) => {
		const file = e.target.files?.[ 0 ];
		if ( ! file ) return;
		setUploading( true );
		try {
			const formData = client.media.buildUploadForm( 'user_avatar', userId, file );
			const result = await client.media.upload( formData );
			if ( result.url ) onAvatarChange( result.url );
		} catch {}
		setUploading( false );
	}, [ onAvatarChange, userId ] );

	return <div style={ styles.avatarContainer }><img src={ avatarUrl } alt="Avatar" style={ styles.avatar } /><div><h4 style={ { margin: 0, marginBottom: '4px' } }>Current Avatar</h4><p style={ { marginTop: 0, marginBottom: '8px', color: cssVar( colors.mutedText ) } }>This is the avatar you currently have set. Upload a new image to change it.</p><label style={ { ...styles.secondaryButton, display: 'inline-block', opacity: uploading ? 0.7 : 1 } }>{ uploading ? 'Uploading...' : 'Upload New Avatar' }<input type="file" accept="image/*" onChange={ handleFileChange } disabled={ uploading } style={ { display: 'none' } } /></label></div></div>;
}

function LinksManager( { links, linkTypes, onChange }: { links: UserLink[]; linkTypes: Record<string, string>; onChange: ( links: UserLink[] ) => void } ) {
	const addLink = useCallback( () => onChange( [ ...links, { type_key: 'website', url: '' } ] ), [ links, onChange ] );
	const removeLink = useCallback( ( index: number ) => onChange( links.filter( ( _, i ) => i !== index ) ), [ links, onChange ] );
	const updateLink = useCallback( ( index: number, field: keyof UserLink, value: string ) => onChange( links.map( ( link, i ) => i !== index ? link : { ...link, [ field ]: value } ) ), [ links, onChange ] );
	return <div><p style={ styles.mutedText }>Add links to your website, social media, streaming, etc.</p>{ links.map( ( link, index ) => <div key={ index } style={ styles.linkRow }><select style={ styles.linkSelect } value={ link.type_key } onChange={ ( e ) => updateLink( index, 'type_key', e.target.value ) }>{ Object.entries( linkTypes ).map( ( [ key, label ] ) => <option key={ key } value={ key }>{ label }</option> ) }</select><input type="url" style={ styles.linkInput } value={ link.url } onChange={ ( e ) => updateLink( index, 'url', e.target.value ) } placeholder="https://..." />{ link.type_key === 'other' && <input type="text" style={ { ...styles.linkInput, minWidth: '120px', flex: 'none', width: '140px' } } value={ link.custom_label || '' } onChange={ ( e ) => updateLink( index, 'custom_label', e.target.value ) } placeholder="Label" /> }<button type="button" style={ styles.dangerButton } onClick={ () => removeLink( index ) } title="Remove link">Remove</button></div> ) }<ActionRow><button type="button" style={ styles.secondaryButton } onClick={ addLink }>+ Add Link</button></ActionRow></div>;
}

function EditProfileApp( { spriteUrl, artistSiteUrl, userId, profileUrl, hasArtists, canCreateArtists }: { spriteUrl: string; artistSiteUrl: string; userId: number; profileUrl: string; hasArtists: boolean; canCreateArtists: boolean } ) {
	const [ activeTab, setActiveTab ] = useState<'avatar-title' | 'about' | 'links' | 'artist-profiles'>( 'avatar-title' );
	const [ profile, setProfile ] = useState<UserProfile | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState< { type: 'success' | 'error'; message: string } | null >( null );
	const [ customTitle, setCustomTitle ] = useState( '' );
	const [ bio, setBio ] = useState( '' );
	const [ localCity, setLocalCity ] = useState( '' );
	const [ links, setLinks ] = useState<UserLink[]>( [] );
	const [ avatarUrl, setAvatarUrl ] = useState( '' );

	useEffect( () => {
		client.users.getProfile().then( ( data ) => {
			setProfile( data );
			setCustomTitle( data.custom_title || '' );
			setBio( data.bio || '' );
			setLocalCity( data.local_city || '' );
			setLinks( data.links || [] );
			setAvatarUrl( data.avatar_url || '' );
			setLoading( false );
		} ).catch( ( err ) => {
			setError( err instanceof Error ? err.message : 'Failed to load profile.' );
			setLoading( false );
		} );
	}, [] );

	useEffect( () => {
		const hash = window.location.hash.replace( '#tab-', '' );
		if ( [ 'avatar-title', 'about', 'links', 'artist-profiles' ].includes( hash ) ) setActiveTab( hash as 'avatar-title' | 'about' | 'links' | 'artist-profiles' );
	}, [] );

	const switchTab = useCallback( ( tab: 'avatar-title' | 'about' | 'links' | 'artist-profiles' ) => { setActiveTab( tab ); window.location.hash = `tab-${ tab }`; }, [] );
	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			const [ profileResult ] = await Promise.all( [ client.users.updateProfile( { custom_title: customTitle, bio, local_city: localCity } ), client.users.updateLinks( { links } ) ] );
			setProfile( profileResult );
			setNotice( { type: 'success', message: 'Profile updated successfully.' } );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Update failed.' } );
		}
		setSaving( false );
	}, [ customTitle, bio, localCity, links ] );

	if ( loading ) return <div style={ { padding: cssVar( spacing.spacingMd ), color: cssVar( colors.mutedText ) } }>Loading profile...</div>;
	if ( error || ! profile ) return <Notice type="error" message={ error || 'Failed to load profile.' } />;
	const hasArtistAccess = profile.artist_access.status === 'approved';
	const tabs = [ { id: 'avatar-title', label: 'Avatar & Title' }, { id: 'about', label: 'About' }, { id: 'links', label: 'Your Links' }, ...( hasArtistAccess ? [ { id: 'artist-profiles', label: 'Artist Profiles' } ] : [] ) ] as const;

	return <div style={ styles.container }>{ notice && <Notice type={ notice.type } message={ notice.message } /> }<div style={ styles.tabsWrapper }><Tabs tabs={ tabs as Array<{ id: string; label: string }> } active={ activeTab } onChange={ ( id ) => switchTab( id as 'avatar-title' | 'about' | 'links' | 'artist-profiles' ) } /></div>{ activeTab === 'avatar-title' && <Panel><PanelHeader title="Avatar & Title" /><AvatarUpload avatarUrl={ avatarUrl } userId={ userId } onAvatarChange={ setAvatarUrl } /><FieldGroup label={ `Custom Title${ customTitle ? ` (Current: ${ customTitle })` : '' }` } htmlFor="ec-custom-title" help="Enter a custom title, or leave blank for default."><input id="ec-custom-title" type="text" style={ styles.input } value={ customTitle } onChange={ ( e ) => setCustomTitle( e.target.value ) } placeholder="Extra Chillian" /></FieldGroup></Panel>}{ activeTab === 'about' && <Panel><PanelHeader title="About" /><FieldGroup label="Bio" htmlFor="ec-bio"><textarea id="ec-bio" style={ styles.textarea } value={ bio } onChange={ ( e ) => setBio( e.target.value ) } /></FieldGroup><FieldGroup label="Local Scene (City/Region)" htmlFor="ec-local-city"><input id="ec-local-city" type="text" style={ styles.input } value={ localCity } onChange={ ( e ) => setLocalCity( e.target.value ) } placeholder="Your local city/region..." /></FieldGroup></Panel>}{ activeTab === 'links' && <Panel><PanelHeader title="Your Links" /><LinksManager links={ links } linkTypes={ profile.link_types } onChange={ setLinks } /></Panel>}{ hasArtistAccess && activeTab === 'artist-profiles' && <Panel><PanelHeader title="Artist Profiles" description="Manage your artist profiles and link pages." />{ hasArtists ? <ActionRow><a href={ `${ artistSiteUrl }/manage-artist/` } style={ { ...styles.button, display: 'inline-block', textDecoration: 'none' } }>Manage Artist</a></ActionRow> : canCreateArtists ? <ActionRow><a href={ `${ artistSiteUrl }/create-artist/` } style={ { ...styles.button, display: 'inline-block', textDecoration: 'none' } }>Create Artist Profile</a></ActionRow> : null }</Panel>}<ActionRow align="between"><a href={ profileUrl } style={ { ...styles.secondaryButton, display: 'inline-block', textDecoration: 'none' } }>View Profile</a><button style={ { ...styles.button, opacity: saving ? 0.7 : 1 } } onClick={ handleSave } disabled={ saving }>{ saving ? 'Saving...' : 'Update Profile' }</button></ActionRow></div>;
}

function init(): void {
	document.querySelectorAll<HTMLElement>( '.wp-block-extrachill-edit-profile' ).forEach( ( container ) => {
		if ( container.dataset.initialized === '1' ) return;
		container.dataset.initialized = '1';
		const spriteUrl = container.dataset.spriteUrl || '';
		const artistSiteUrl = container.dataset.artistSiteUrl || 'https://artist.extrachill.com';
		const userId = Number( container.dataset.userId || '0' );
		const profileUrl = container.dataset.profileUrl || '#';
		const hasArtists = container.dataset.hasArtists === '1';
		const canCreateArtists = container.dataset.canCreateArtists === '1';
		const root = createRoot( container );
		root.render( <EditProfileApp spriteUrl={ spriteUrl } artistSiteUrl={ artistSiteUrl } userId={ userId } profileUrl={ profileUrl } hasArtists={ hasArtists } canCreateArtists={ canCreateArtists } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
