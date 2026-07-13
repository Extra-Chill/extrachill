import {
	useState,
	useEffect,
	useCallback,
	createRoot,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { WPNativeClient } from 'wp-native-client';
import { WpApiFetchTransport } from 'wp-native-client/wordpress';
import {
	ActionRow,
	BlockShell,
	BlockShellInner,
	BlockShellHeader,
	FieldGroup,
	Panel,
	PanelHeader,
	ResponsiveTabs,
} from '@extrachill/components';
import '@extrachill/components/styles/components.scss';
import { cssVar, spacing, colors } from '@extrachill/tokens';
import type { UserProfile, UserLink } from '../../types/users';

const client = new WPNativeClient( new WpApiFetchTransport( apiFetch ), {
	validateAbilityNames: false,
} );

const styles = {
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
		minWidth: '140px',
	},
	linkInput: {
		flex: 1,
		minWidth: '200px',
	},
	mutedText: { color: cssVar( colors.mutedText ) },
	disabledButton: {
		opacity: 0.7,
		pointerEvents: 'none' as const,
	},
	inlineButtonLink: { textDecoration: 'none' },
	visuallyHiddenInput: {
		display: 'none',
	},
} as const;

function Notice( {
	type,
	message,
}: {
	type: 'success' | 'error';
	message: string;
} ) {
	return (
		<div className={ `notice notice-${ type }` }>
			<p>{ message }</p>
		</div>
	);
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

	const handleFileChange = useCallback(
		async ( e: React.ChangeEvent< HTMLInputElement > ) => {
			const file = e.target.files?.[ 0 ];
			if ( ! file ) {
				return;
			}

			setUploading( true );

			try {
				// B1 exception: avatar upload is a multipart POST with no backing
				// ability (the Abilities API run endpoint is JSON-only). It stays on
				// the existing extrachill/v1/media REST route, called directly via
				// apiFetch — mirroring the former api-client media.upload() wire.
				const formData = new FormData();
				formData.append( 'context', 'user_avatar' );
				if ( userId ) {
					formData.append( 'target_id', String( userId ) );
				}
				formData.append( 'file', file );

				const result = await apiFetch< { url?: string } >( {
					path: 'extrachill/v1/media',
					method: 'POST',
					body: formData,
				} );
				if ( result.url ) {
					onAvatarChange( result.url );
				}
			} catch {}

			setUploading( false );
		},
		[ onAvatarChange, userId ]
	);

	return (
		<div style={ styles.avatarContainer }>
			<img src={ avatarUrl } alt="Avatar" style={ styles.avatar } />
			<div>
				<h4 style={ { margin: 0, marginBottom: '4px' } }>
					Current Avatar
				</h4>
				<p
					style={ {
						marginTop: 0,
						marginBottom: '8px',
						color: cssVar( colors.mutedText ),
					} }
				>
					This is the avatar you currently have set. Upload a new
					image to change it.
				</p>
				<label
					htmlFor="ec-edit-profile-avatar-upload"
					className={ `button-3 button-small${
						uploading ? ' is-disabled' : ''
					}` }
					style={ uploading ? styles.disabledButton : undefined }
				>
					{ uploading ? 'Uploading...' : 'Upload New Avatar' }
					<input
						id="ec-edit-profile-avatar-upload"
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
	linkTypes: Record< string, string >;
	onChange: ( links: UserLink[] ) => void;
} ) {
	const addLink = useCallback( () => {
		onChange( [ ...links, { type_key: 'website', url: '' } ] );
	}, [ links, onChange ] );

	const removeLink = useCallback(
		( index: number ) => {
			onChange( links.filter( ( _, i ) => i !== index ) );
		},
		[ links, onChange ]
	);

	const updateLink = useCallback(
		( index: number, field: keyof UserLink, value: string ) => {
			onChange(
				links.map( ( link, i ) =>
					i !== index ? link : { ...link, [ field ]: value }
				)
			);
		},
		[ links, onChange ]
	);

	return (
		<div>
			<p style={ styles.mutedText }>
				Add links to your website, social media, streaming, etc.
			</p>
			{ links.map( ( link, index ) => (
				<div key={ index } style={ styles.linkRow }>
					<select
						style={ styles.linkSelect }
						value={ link.type_key }
						onChange={ ( e ) =>
							updateLink( index, 'type_key', e.target.value )
						}
					>
						{ Object.entries( linkTypes ).map(
							( [ key, label ] ) => (
								<option key={ key } value={ key }>
									{ label }
								</option>
							)
						) }
					</select>
					<input
						type="url"
						style={ styles.linkInput }
						value={ link.url }
						onChange={ ( e ) =>
							updateLink( index, 'url', e.target.value )
						}
						placeholder="https://..."
					/>
					{ link.type_key === 'other' && (
						<input
							type="text"
							style={ {
								...styles.linkInput,
								minWidth: '120px',
								flex: 'none',
								width: '140px',
							} }
							value={ link.custom_label || '' }
							onChange={ ( e ) =>
								updateLink(
									index,
									'custom_label',
									e.target.value
								)
							}
							placeholder="Label"
						/>
					) }
					<button
						type="button"
						className="button-3 button-small"
						onClick={ () => removeLink( index ) }
						title="Remove link"
					>
						Remove
					</button>
				</div>
			) ) }
			<ActionRow>
				<button
					type="button"
					className="button-3 button-small"
					onClick={ addLink }
				>
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
	const [ activeTab, setActiveTab ] = useState< TabId >( 'avatar-title' );
	const [ profile, setProfile ] = useState< UserProfile | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ , setNotice ] = useState< {
		type: 'success' | 'error';
		message: string;
	} | null >( null );
	const [ customTitle, setCustomTitle ] = useState( '' );
	const [ bio, setBio ] = useState( '' );
	const [ links, setLinks ] = useState< UserLink[] >( [] );
	const [ avatarUrl, setAvatarUrl ] = useState( '' );

	useEffect( () => {
		client
			.execute< UserProfile >( 'extrachill/get-user-profile', {
				user_id: userId,
			} )
			.then( ( data ) => {
				setProfile( data );
				setCustomTitle( data.custom_title || '' );
				setBio( data.bio || '' );
				setLinks( data.links || [] );
				setAvatarUrl( data.avatar_url || '' );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err instanceof Error
						? err.message
						: 'Failed to load profile.'
				);
				setLoading( false );
			} );
	}, [ userId ] );

	useEffect( () => {
		const hash = window.location.hash.replace( '#tab-', '' );
		if (
			[ 'avatar-title', 'about', 'links', 'artist-profiles' ].includes(
				hash
			)
		) {
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
				client.execute< UserProfile >(
					'extrachill/update-user-profile',
					{
						custom_title: customTitle,
						bio,
					}
				),
				client.execute( 'extrachill/update-user-links', { links } ),
			] );

			setProfile( profileResult );
			setNotice( {
				type: 'success',
				message: 'Profile updated successfully.',
			} );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message: err instanceof Error ? err.message : 'Update failed.',
			} );
		}

		setSaving( false );
	}, [ customTitle, bio, links ] );

	if ( loading ) {
		return (
			<div className="notice notice-info">
				<p>Loading profile...</p>
			</div>
		);
	}

	if ( error || ! profile ) {
		return (
			<Notice
				type="error"
				message={ error || 'Failed to load profile.' }
			/>
		);
	}

	const hasArtistAccess = profile.artist_access.status === 'approved';
	const tabs = [
		{ id: 'avatar-title', label: 'Avatar & Title' },
		{ id: 'about', label: 'About' },
		{ id: 'links', label: 'Your Links' },
		...( hasArtistAccess
			? [ { id: 'artist-profiles', label: 'Artist Profiles' } ]
			: [] ),
	] as const;

	return (
		<BlockShell>
			<BlockShellInner maxWidth="narrow">
				<BlockShellHeader
					title="Edit Profile"
					description="Update your public profile, links, and artist profile access."
				/>
				<ResponsiveTabs
					tabs={ tabs as Array< { id: string; label: string } > }
					active={ activeTab }
					onChange={ ( id ) => switchTab( id as TabId ) }
					syncWithHash={ true }
					showDesktopTabs={ true }
					renderPanel={ ( id ) => {
						switch ( id as TabId ) {
							case 'avatar-title':
								return (
									<Panel>
										<AvatarUpload
											avatarUrl={ avatarUrl }
											userId={ userId }
											onAvatarChange={ setAvatarUrl }
										/>
										<FieldGroup
											label={ `Custom Title${
												customTitle
													? ` (Current: ${ customTitle })`
													: ''
											}` }
											htmlFor="ec-custom-title"
											help="Enter a custom title, or leave blank for default."
										>
											<input
												id="ec-custom-title"
												type="text"
												value={ customTitle }
												onChange={ ( e ) =>
													setCustomTitle(
														e.target.value
													)
												}
												placeholder="Extra Chillian"
											/>
										</FieldGroup>
									</Panel>
								);
							case 'about':
								return (
									<Panel>
										<FieldGroup
											label="Bio"
											htmlFor="ec-bio"
										>
											<textarea
												id="ec-bio"
												value={ bio }
												onChange={ ( e ) =>
													setBio( e.target.value )
												}
											/>
										</FieldGroup>
									</Panel>
								);
							case 'links':
								return (
									<Panel>
										<LinksManager
											links={ links }
											linkTypes={ profile.link_types }
											onChange={ setLinks }
										/>
									</Panel>
								);
							case 'artist-profiles':
								return hasArtistAccess ? (
									<Panel>
										<PanelHeader description="Manage your artist profiles and link pages." />
										{ hasArtists && (
											<ActionRow>
												<a
													href={ `${ artistSiteUrl }/manage-artist/` }
													className="button-2 button-small"
													style={
														styles.inlineButtonLink
													}
												>
													Manage Artist
												</a>
											</ActionRow>
										) }
										{ ! hasArtists && canCreateArtists && (
											<ActionRow>
												<a
													href={ `${ artistSiteUrl }/create-artist/` }
													className="button-2 button-small"
													style={
														styles.inlineButtonLink
													}
												>
													Create Artist Profile
												</a>
											</ActionRow>
										) }
										{ ! hasArtists &&
											! canCreateArtists && (
												<p style={ styles.mutedText }>
													No artist profiles available
													yet.
												</p>
											) }
									</Panel>
								) : null;
							default:
								return null;
						}
					} }
				/>
				<ActionRow>
					<button
						type="button"
						className="button-1 button-small"
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
			</BlockShellInner>
		</BlockShell>
	);
}

function init(): void {
	document
		.querySelectorAll< HTMLElement >( '.wp-block-extrachill-edit-profile' )
		.forEach( ( container ) => {
			if ( container.dataset.initialized === '1' ) {
				return;
			}

			container.dataset.initialized = '1';

			const artistSiteUrl =
				container.dataset.artistSiteUrl ||
				'https://artist.extrachill.com';
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
