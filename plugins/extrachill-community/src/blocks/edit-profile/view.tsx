import { useState, useEffect, useCallback } from '@wordpress/element';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
import {
	ActionRow,
	BlockShell,
	BlockShellInner,
	BlockShellHeader,
	FieldGroup,
	InlineStatus,
	Panel,
	PanelHeader,
	ResponsiveTabs,
} from '@extrachill/components';
import '@extrachill/components/styles/components.scss';
import { cssVar, spacing, colors, fontSize } from '@extrachill/tokens';
import type { UserProfile, UserLink } from '@extrachill/api-client';

const client = new ExtraChillClient( new WpApiFetchTransport( apiFetch ) );

const styles = {
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
	mutedText: { color: cssVar( colors.mutedText ) },
	headerRegion: { display: 'grid' },
	disabledButton: {
		opacity: 0.7,
		pointerEvents: 'none' as const,
	},
	inlineButtonLink: { textDecoration: 'none' },
	visuallyHiddenInput: {
		display: 'none',
	},
} as const;

function Notice( { type, message }: { type: 'success' | 'error'; message: string } ) {
	return <InlineStatus tone={ type }>{ message }</InlineStatus>;
}

function AvatarUpload( {
	avatarUrl,
	userId,
	onAvatarChange,
}: {
	avatarUrl: string;
	userId: number;
	onAvatarChange: ( url: string ) => void;
} ) {
	const [ uploading, setUploading ] = useState( false );

	const handleFileChange = useCallback( async ( e: React.ChangeEvent<HTMLInputElement> ) => {
		const file = e.target.files?.[ 0 ];
		if ( ! file ) return;

		setUploading( true );

		try {
			const formData = client.media.buildUploadForm( 'user_avatar', userId, file );
			const result = await client.media.upload( formData );
			if ( result.url ) {
				onAvatarChange( result.url );
			}
		} catch {}

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
				<label
					className={ `button-3 button-small${ uploading ? ' is-disabled' : '' }` }
					style={ uploading ? styles.disabledButton : undefined }
				>
					{ uploading ? 'Uploading...' : 'Upload New Avatar' }
					<input
						type="file"
						accept="image/*"
						onChange={ handleFileChange }
						disabled={ uploading }
						style={ styles.visuallyHiddenInput }
					/>
				</label>
			</div>
		</div>
	);
}

function LinksManager( {
	links,
	linkTypes,
	onChange,
}: {
	links: UserLink[];
	linkTypes: Record<string, string>;
	onChange: ( links: UserLink[] ) => void;
} ) {
	const addLink = useCallback( () => {
		onChange( [ ...links, { type_key: 'website', url: '' } ] );
	}, [ links, onChange ] );

	const removeLink = useCallback( ( index: number ) => {
		onChange( links.filter( ( _, i ) => i !== index ) );
	}, [ links, onChange ] );

	const updateLink = useCallback( ( index: number, field: keyof UserLink, value: string ) => {
		onChange( links.map( ( link, i ) => ( i !== index ? link : { ...link, [ field ]: value } ) ) );
	}, [ links, onChange ] );

	return (
		<div>
			<p style={ styles.mutedText }>Add links to your website, social media, streaming, etc.</p>
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
					<button type="button" className="button-3 button-small" onClick={ () => removeLink( index ) } title="Remove link">
						Remove
					</button>
				</div>
			) ) }
			<ActionRow>
				<button type="button" className="button-3 button-small" onClick={ addLink }>
					+ Add Link
				</button>
			</ActionRow>
		</div>
	);
}

type TabId = 'avatar-title' | 'about' | 'links' | 'artist-profiles';

function EditProfileApp( {
	artistSiteUrl,
	userId,
	profileUrl,
	hasArtists,
	canCreateArtists,
}: {
	artistSiteUrl: string;
	userId: number;
	profileUrl: string;
	hasArtists: boolean;
	canCreateArtists: boolean;
} ) {
	const [ activeTab, setActiveTab ] = useState<TabId>( 'avatar-title' );
	const [ profile, setProfile ] = useState<UserProfile | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState<{ type: 'success' | 'error'; message: string } | null>( null );
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
		if ( [ 'avatar-title', 'about', 'links', 'artist-profiles' ].includes( hash ) ) {
			setActiveTab( hash as TabId );
		}
	}, [] );

	const switchTab = useCallback( ( tab: TabId ) => {
		setActiveTab( tab );
	}, [] );

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );

		try {
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
		return <InlineStatus tone="info">Loading profile...</InlineStatus>;
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
		<BlockShell className="ec-community-edit-profile-shell">
			<BlockShellInner maxWidth="narrow">
				<BlockShellHeader
					title="Edit Profile"
					description="Update your public profile, links, and artist profile access."
					showDivider={ false }
				/>
				<div style={ styles.headerRegion }>
					{ notice && <Notice type={ notice.type } message={ notice.message } /> }
					<ResponsiveTabs
						tabs={ tabs as Array<{ id: string; label: string }> }
						active={ activeTab }
						onChange={ ( id ) => switchTab( id as TabId ) }
						syncWithHash={ true }
						renderPanel={ ( id ) => {
							switch ( id as TabId ) {
								case 'avatar-title':
									return (
										<Panel depth={ 1 }>
											<PanelHeader title="Avatar & Title" />
											<AvatarUpload avatarUrl={ avatarUrl } userId={ userId } onAvatarChange={ setAvatarUrl } />
											<FieldGroup
												label={ `Custom Title${ customTitle ? ` (Current: ${ customTitle })` : '' }` }
												htmlFor="ec-custom-title"
												help="Enter a custom title, or leave blank for default."
											>
												<input
													id="ec-custom-title"
													type="text"
													style={ styles.input }
													value={ customTitle }
													onChange={ ( e ) => setCustomTitle( e.target.value ) }
													placeholder="Extra Chillian"
												/>
											</FieldGroup>
										</Panel>
									);
								case 'about':
									return (
										<Panel depth={ 1 }>
											<PanelHeader title="About" />
											<FieldGroup label="Bio" htmlFor="ec-bio">
												<textarea
													id="ec-bio"
													style={ styles.textarea }
													value={ bio }
													onChange={ ( e ) => setBio( e.target.value ) }
												/>
											</FieldGroup>
											<FieldGroup label="Local Scene (City/Region)" htmlFor="ec-local-city">
												<input
													id="ec-local-city"
													type="text"
													style={ styles.input }
													value={ localCity }
													onChange={ ( e ) => setLocalCity( e.target.value ) }
													placeholder="Your local city/region..."
												/>
											</FieldGroup>
										</Panel>
									);
								case 'links':
									return (
										<Panel depth={ 1 }>
											<PanelHeader title="Your Links" />
											<LinksManager links={ links } linkTypes={ profile.link_types } onChange={ setLinks } />
										</Panel>
									);
								case 'artist-profiles':
									return hasArtistAccess ? (
										<Panel depth={ 1 }>
											<PanelHeader
												title="Artist Profiles"
												description="Manage your artist profiles and link pages."
											/>
											{ hasArtists ? (
												<ActionRow>
													<a
														href={ `${ artistSiteUrl }/manage-artist/` }
								className="button-2 button-small"
								style={ styles.inlineButtonLink }
													>
														Manage Artist
													</a>
												</ActionRow>
											) : canCreateArtists ? (
												<ActionRow>
													<a
														href={ `${ artistSiteUrl }/create-artist/` }
								className="button-2 button-small"
								style={ styles.inlineButtonLink }
													>
														Create Artist Profile
													</a>
												</ActionRow>
											) : (
												<p style={ styles.mutedText }>No artist profiles available yet.</p>
											) }
										</Panel>
									) : null;
								default:
									return null;
							}
						} }
						className="ec-community-settings-tabs"
						showDesktopTabs={ true }
					/>
					<ActionRow>
						<button
						type="button"
						className={ `button-2 button-small${ saving ? ' is-disabled' : '' }` }
						style={ saving ? styles.disabledButton : undefined }
						onClick={ handleSave }
						disabled={ saving }
					>
						{ saving ? 'Saving...' : 'Save Profile Changes' }
					</button>
					{ profileUrl && (
						<a
							href={ profileUrl }
							className="button-3 button-small"
							style={ styles.inlineButtonLink }
						>
							View Public Profile
						</a>
					) }
					</ActionRow>
				</div>
			</BlockShellInner>
		</BlockShell>
	);
}

function init(): void {
	document.querySelectorAll<HTMLElement>( '.wp-block-extrachill-edit-profile' ).forEach( ( container ) => {
		if ( container.dataset.initialized === '1' ) return;

		container.dataset.initialized = '1';

		const artistSiteUrl = container.dataset.artistSiteUrl || 'https://artist.extrachill.com';
		const userId = Number( container.dataset.userId || '0' );
		const profileUrl = container.dataset.profileUrl || '#';
		const hasArtists = container.dataset.hasArtists === '1';
		const canCreateArtists = container.dataset.canCreateArtists === '1';
		const root = createRoot( container );

		root.render(
			<EditProfileApp
				artistSiteUrl={ artistSiteUrl }
				userId={ userId }
				profileUrl={ profileUrl }
				hasArtists={ hasArtists }
				canCreateArtists={ canCreateArtists }
			/>
		);
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
