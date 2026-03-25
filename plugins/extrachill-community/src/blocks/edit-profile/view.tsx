import { useState, useEffect, useCallback } from '@wordpress/element';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
import { Tabs } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';
import { cssVar, spacing, colors, fontSize } from '@extrachill/tokens';
import type { UserProfile, UserLink } from '@extrachill/api-client';

const client = new ExtraChillClient( new WpApiFetchTransport( apiFetch ) );

// ─── Styles ──────────────────────────────────────────────────────────────────

const styles = {
	container: {
		maxWidth: '700px',
	},
	tabsWrapper: {
		marginBottom: cssVar( spacing.spacingLg ),
	},
	card: {
		border: `1px solid ${ cssVar( colors.borderColor ) }`,
		borderRadius: '4px',
		padding: cssVar( spacing.spacingMd ),
		marginBottom: cssVar( spacing.spacingMd ),
		backgroundColor: cssVar( colors.cardBackground ),
	},
	cardTitle: {
		fontSize: '1.1em',
		fontWeight: 600,
		marginTop: 0,
		marginBottom: cssVar( spacing.spacingMd ),
		color: cssVar( colors.textColor ),
	},
	fieldGroup: {
		marginBottom: cssVar( spacing.spacingMd ),
	},
	label: {
		display: 'block',
		fontWeight: 600,
		marginBottom: '4px',
		fontSize: cssVar( fontSize.fontSizeSm ),
	},
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
	textarea: {
		width: '100%',
		padding: '8px',
		border: `1px solid ${ cssVar( colors.borderColor ) }`,
		borderRadius: '3px',
		backgroundColor: cssVar( colors.backgroundColor ),
		color: cssVar( colors.textColor ),
		fontSize: cssVar( fontSize.fontSizeBase ),
		boxSizing: 'border-box' as const,
		minHeight: '120px',
		resize: 'vertical' as const,
		fontFamily: 'inherit',
	},
	description: {
		marginTop: '4px',
		fontSize: cssVar( fontSize.fontSizeSm ),
		color: cssVar( colors.mutedText ),
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
	secondaryButton: {
		padding: '8px 16px',
		border: `1px solid ${ cssVar( colors.borderColor ) }`,
		borderRadius: '3px',
		cursor: 'pointer',
		fontWeight: 500,
		fontSize: cssVar( fontSize.fontSizeSm ),
		backgroundColor: 'transparent',
		color: cssVar( colors.textColor ),
	},
	dangerButton: {
		padding: '6px 12px',
		border: 'none',
		borderRadius: '3px',
		cursor: 'pointer',
		fontWeight: 500,
		fontSize: cssVar( fontSize.fontSizeSm ),
		backgroundColor: 'rgba(211, 47, 47, 0.1)',
		color: '#d32f2f',
	},
	notice: {
		padding: `${ cssVar( spacing.spacingSm ) } ${ cssVar( spacing.spacingMd ) }`,
		borderRadius: '3px',
		marginBottom: cssVar( spacing.spacingMd ),
	},
	successNotice: {
		backgroundColor: 'rgba(46, 125, 50, 0.1)',
		border: '1px solid rgba(46, 125, 50, 0.3)',
		color: '#2e7d32',
	},
	errorNotice: {
		backgroundColor: 'rgba(211, 47, 47, 0.1)',
		border: '1px solid rgba(211, 47, 47, 0.3)',
		color: '#d32f2f',
	},
	avatarContainer: {
		display: 'flex',
		alignItems: 'center',
		gap: cssVar( spacing.spacingMd ),
		marginBottom: cssVar( spacing.spacingMd ),
	},
	avatar: {
		width: '80px',
		height: '80px',
		borderRadius: '50%',
		objectFit: 'cover' as const,
		border: `2px solid ${ cssVar( colors.borderColor ) }`,
	},
	linkRow: {
		display: 'flex',
		gap: cssVar( spacing.spacingSm ),
		alignItems: 'flex-start',
		marginBottom: cssVar( spacing.spacingSm ),
		flexWrap: 'wrap' as const,
	},
	linkSelect: {
		padding: '8px',
		border: `1px solid ${ cssVar( colors.borderColor ) }`,
		borderRadius: '3px',
		backgroundColor: cssVar( colors.backgroundColor ),
		color: cssVar( colors.textColor ),
		fontSize: cssVar( fontSize.fontSizeBase ),
		minWidth: '140px',
	},
	linkInput: {
		flex: 1,
		minWidth: '200px',
		padding: '8px',
		border: `1px solid ${ cssVar( colors.borderColor ) }`,
		borderRadius: '3px',
		backgroundColor: cssVar( colors.backgroundColor ),
		color: cssVar( colors.textColor ),
		fontSize: cssVar( fontSize.fontSizeBase ),
		boxSizing: 'border-box' as const,
	},
} as const;

// ─── Notice Component ────────────────────────────────────────────────────────

function Notice( { type, message }: { type: 'success' | 'error'; message: string } ) {
	return (
		<div style={ { ...styles.notice, ...( type === 'success' ? styles.successNotice : styles.errorNotice ) } }>
			{ message }
		</div>
	);
}

// ─── Avatar Upload ───────────────────────────────────────────────────────────

function AvatarUpload( { avatarUrl, userId, onAvatarChange }: {
	avatarUrl: string;
	userId: number;
	onAvatarChange: ( url: string ) => void;
} ) {
	const [ uploading, setUploading ] = useState( false );

	const handleFileChange = useCallback( async ( e: React.ChangeEvent< HTMLInputElement > ) => {
		const file = e.target.files?.[ 0 ];
		if ( ! file ) return;

		setUploading( true );
		try {
			const formData = client.media.buildUploadForm( 'user_avatar', userId, file );
			const result = await client.media.upload( formData );
			if ( result.url ) {
				onAvatarChange( result.url );
			}
		} catch {
			// Silently fail — avatar upload is non-critical.
		}
		setUploading( false );
	}, [ onAvatarChange, userId ] );

	return (
		<div style={ styles.avatarContainer }>
			<img src={ avatarUrl } alt="Avatar" style={ styles.avatar } />
			<div>
				<h4 style={ { margin: 0, marginBottom: '4px' } }>Current Avatar</h4>
				<p style={ { marginTop: 0, marginBottom: '8px', color: cssVar( colors.mutedText ) } }>
					This is the avatar you currently have set. Upload a new image to change it.
				</p>
				<label style={ { ...styles.secondaryButton, display: 'inline-block', opacity: uploading ? 0.7 : 1 } }>
					{ uploading ? 'Uploading...' : 'Upload New Avatar' }
					<input
						type="file"
						accept="image/*"
						onChange={ handleFileChange }
						disabled={ uploading }
						style={ { display: 'none' } }
					/>
				</label>
			</div>
		</div>
	);
}

// ─── Links Manager ───────────────────────────────────────────────────────────

function LinksManager( { links, linkTypes, onChange }: {
	links: UserLink[];
	linkTypes: Record< string, string >;
	onChange: ( links: UserLink[] ) => void;
} ) {
	const addLink = useCallback( () => {
		onChange( [ ...links, { type_key: 'website', url: '' } ] );
	}, [ links, onChange ] );

	const removeLink = useCallback( ( index: number ) => {
		const updated = links.filter( ( _, i ) => i !== index );
		onChange( updated );
	}, [ links, onChange ] );

	const updateLink = useCallback( ( index: number, field: keyof UserLink, value: string ) => {
		const updated = links.map( ( link, i ) => {
			if ( i !== index ) return link;
			return { ...link, [ field ]: value };
		} );
		onChange( updated );
	}, [ links, onChange ] );

	return (
		<div>
			<p style={ styles.description }>Add links to your website, social media, streaming, etc.</p>

			{ links.map( ( link, index ) => (
				<div key={ index } style={ styles.linkRow }>
					<select
						style={ styles.linkSelect }
						value={ link.type_key }
						onChange={ ( e ) => updateLink( index, 'type_key', e.target.value ) }
					>
						{ Object.entries( linkTypes ).map( ( [ key, label ] ) => (
							<option key={ key } value={ key }>{ label }</option>
						) ) }
					</select>
					<input
						type="url"
						style={ styles.linkInput }
						value={ link.url }
						onChange={ ( e ) => updateLink( index, 'url', e.target.value ) }
						placeholder="https://..."
					/>
					{ link.type_key === 'other' && (
						<input
							type="text"
							style={ { ...styles.linkInput, minWidth: '120px', flex: 'none', width: '140px' } }
							value={ link.custom_label || '' }
							onChange={ ( e ) => updateLink( index, 'custom_label', e.target.value ) }
							placeholder="Label"
						/>
					) }
					<button
						type="button"
						style={ styles.dangerButton }
						onClick={ () => removeLink( index ) }
						title="Remove link"
					>
						Remove
					</button>
				</div>
			) ) }

			<button
				type="button"
				style={ styles.secondaryButton }
				onClick={ addLink }
			>
				+ Add Link
			</button>
		</div>
	);
}

// ─── Main Component ──────────────────────────────────────────────────────────

function EditProfileApp( { spriteUrl, artistSiteUrl, userId, profileUrl, hasArtists, canCreateArtists }: { spriteUrl: string; artistSiteUrl: string; userId: number; profileUrl: string; hasArtists: boolean; canCreateArtists: boolean } ) {
	const [ activeTab, setActiveTab ] = useState< 'avatar-title' | 'about' | 'links' | 'artist-profiles' >( 'avatar-title' );
	const [ profile, setProfile ] = useState< UserProfile | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState< { type: 'success' | 'error'; message: string } | null >( null );

	// Editable fields.
	const [ customTitle, setCustomTitle ] = useState( '' );
	const [ bio, setBio ] = useState( '' );
	const [ localCity, setLocalCity ] = useState( '' );
	const [ links, setLinks ] = useState< UserLink[] >( [] );
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
		if ( [ 'avatar-title', 'about', 'links', 'artist-profiles' ].includes( hash ) ) {
			setActiveTab( hash as 'avatar-title' | 'about' | 'links' | 'artist-profiles' );
		}
	}, [] );

	const switchTab = useCallback( ( tab: 'avatar-title' | 'about' | 'links' | 'artist-profiles' ) => {
		setActiveTab( tab );
		window.location.hash = `tab-${ tab }`;
	}, [] );

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );

		try {
			// Save profile fields and links in parallel.
			const [ profileResult ] = await Promise.all( [
				client.users.updateProfile( {
					custom_title: customTitle,
					bio,
					local_city: localCity,
				} ),
				client.users.updateLinks( { links } ),
			] );

			setProfile( profileResult );
			setNotice( { type: 'success', message: 'Profile updated successfully.' } );
		} catch ( err ) {
			setNotice( { type: 'error', message: err instanceof Error ? err.message : 'Update failed.' } );
		}

		setSaving( false );
	}, [ customTitle, bio, localCity, links ] );

	if ( loading ) {
		return <div style={ { padding: cssVar( spacing.spacingMd ), color: cssVar( colors.mutedText ) } }>Loading profile...</div>;
	}

	if ( error || ! profile ) {
		return <Notice type="error" message={ error || 'Failed to load profile.' } />;
	}

	const hasArtistAccess = profile.artist_access.status === 'approved';
	const tabs = [
		{ id: 'avatar-title', label: 'Avatar & Title' },
		{ id: 'about', label: 'About' },
		{ id: 'links', label: 'Your Links' },
		...( hasArtistAccess ? [ { id: 'artist-profiles', label: 'Artist Profiles' } ] : [] ),
	] as const;

	return (
		<div style={ styles.container }>
			{ notice && <Notice type={ notice.type } message={ notice.message } /> }

			<div style={ styles.tabsWrapper }>
				<Tabs tabs={ tabs as Array<{ id: string; label: string }> } active={ activeTab } onChange={ ( id ) => switchTab( id as 'avatar-title' | 'about' | 'links' | 'artist-profiles' ) } />
			</div>

			{ /* Avatar & Title Card */ }
			{ activeTab === 'avatar-title' && <div style={ styles.card }>
				<h3 style={ styles.cardTitle }>Avatar & Title</h3>

				<AvatarUpload avatarUrl={ avatarUrl } userId={ userId } onAvatarChange={ setAvatarUrl } />

				<div style={ styles.fieldGroup }>
					<label style={ styles.label } htmlFor="ec-custom-title">
						Custom Title{ customTitle ? ` (Current: ${ customTitle })` : '' }
					</label>
					<input
						id="ec-custom-title"
						type="text"
						style={ styles.input }
						value={ customTitle }
						onChange={ ( e ) => setCustomTitle( e.target.value ) }
						placeholder="Extra Chillian"
					/>
					<div style={ styles.description }>Enter a custom title, or leave blank for default.</div>
				</div>
			</div> }

			{ /* About Card */ }
			{ activeTab === 'about' && <div style={ styles.card }>
				<h3 style={ styles.cardTitle }>About</h3>

				<div style={ styles.fieldGroup }>
					<label style={ styles.label } htmlFor="ec-bio">Bio</label>
					<textarea
						id="ec-bio"
						style={ styles.textarea }
						value={ bio }
						onChange={ ( e ) => setBio( e.target.value ) }
					/>
				</div>

				<div style={ styles.fieldGroup }>
					<label style={ styles.label } htmlFor="ec-local-city">Local Scene (City/Region)</label>
					<input
						id="ec-local-city"
						type="text"
						style={ styles.input }
						value={ localCity }
						onChange={ ( e ) => setLocalCity( e.target.value ) }
						placeholder="Your local city/region..."
					/>
				</div>
			</div> }

			{ /* Links Card */ }
			{ activeTab === 'links' && <div style={ styles.card }>
				<h3 style={ styles.cardTitle }>Your Links</h3>
				<LinksManager
					links={ links }
					linkTypes={ profile.link_types }
					onChange={ setLinks }
				/>
			</div> }

			{ /* Artist Profiles Card (conditional) */ }
			{ hasArtistAccess && activeTab === 'artist-profiles' && (
				<div style={ styles.card }>
					<h3 style={ styles.cardTitle }>Artist Profiles</h3>
					<p>Manage your artist profiles and link pages.</p>
					{ hasArtists ? (
						<a
							href={ `${ artistSiteUrl }/manage-artist/` }
							style={ { ...styles.button, display: 'inline-block', textDecoration: 'none' } }
						>
							Manage Artist
						</a>
					) : canCreateArtists ? (
						<a
							href={ `${ artistSiteUrl }/create-artist/` }
							style={ { ...styles.button, display: 'inline-block', textDecoration: 'none' } }
						>
							Create Artist Profile
						</a>
					) : null }
				</div>
			) }

			{ /* Save Button */ }
			<div style={ { marginTop: cssVar( spacing.spacingMd ), display: 'flex', gap: cssVar( spacing.spacingSm ), flexWrap: 'wrap' } }>
				<a
					href={ profileUrl }
					style={ { ...styles.secondaryButton, display: 'inline-block', textDecoration: 'none' } }
				>
					View Profile
				</a>
				<button
					style={ { ...styles.button, opacity: saving ? 0.7 : 1 } }
					onClick={ handleSave }
					disabled={ saving }
				>
					{ saving ? 'Saving...' : 'Update Profile' }
				</button>
			</div>
		</div>
	);
}

// ─── Hydration ───────────────────────────────────────────────────────────────

function init(): void {
	document
		.querySelectorAll< HTMLElement >( '.wp-block-extrachill-edit-profile' )
		.forEach( ( container ) => {
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
