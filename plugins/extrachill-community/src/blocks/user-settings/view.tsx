/**
 * WordPress dependencies
 */
import {
	useState,
	useEffect,
	useCallback,
	useRef,
	createRoot,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ComboboxControl } from '@wordpress/components';

/**
 * External dependencies
 */
import { WPNativeClient } from 'wp-native-client';
import { WpApiFetchTransport } from 'wp-native-client/wordpress';
import {
	BlockShell,
	BlockShellInner,
	ResponsiveTabs,
	Panel,
	PanelHeader,
	ActionRow,
	FieldGroup,
	BlockShellHeader,
} from '@extrachill/components';
import '@extrachill/components/styles/components.scss';
import { cssVar, spacing, colors } from '@extrachill/tokens';

/**
 * Internal dependencies
 */
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

const client = new WPNativeClient( new WpApiFetchTransport( apiFetch ), {
	validateAbilityNames: false,
} );
const LOCATION_SEARCH_DEBOUNCE_MS = 250;

interface LocalScene {
	term_id: number;
	name: string;
	slug: string;
	url: string;
	coordinates: { lat: number; lon: number } | null;
	hierarchy: {
		region: string;
		state: string;
		label: string;
	};
}

interface EventLocationsResponse {
	locations: LocalScene[];
	location: LocalScene | null;
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

function Notice( {
	type,
	message,
}: {
	type: 'success' | 'error';
	message: string;
} ) {
	return (
		<div
			className={ `notice notice-${ type }` }
			role={ type === 'error' ? 'alert' : 'status' }
			aria-live={ type === 'error' ? 'assertive' : 'polite' }
			aria-atomic="true"
		>
			<p>{ message }</p>
		</div>
	);
}

export function AccountTab( {
	settings,
	onUpdate,
}: {
	settings: UserSettings;
	onUpdate: ( updated: UserSettings ) => void;
} ) {
	const [ firstName, setFirstName ] = useState( settings.first_name );
	const [ lastName, setLastName ] = useState( settings.last_name );
	const [ displayName, setDisplayName ] = useState( settings.display_name );
	const [ localSceneSlug, setLocalSceneSlug ] = useState< string | null >(
		settings.local_scene?.slug ?? null
	);
	const [ localSceneVisibility, setLocalSceneVisibility ] = useState(
		settings.local_scene_visibility
	);
	const [ concertHistoryVisibility, setConcertHistoryVisibility ] = useState(
		settings.concert_history_visibility
	);
	const [ eventAttendanceVisibility, setEventAttendanceVisibility ] =
		useState( settings.event_attendance_visibility );
	const [ localSceneChanged, setLocalSceneChanged ] = useState( false );
	const [ locationOptions, setLocationOptions ] = useState<
		Array< { label: string; value: string } >
	>(
		settings.local_scene
			? [
					{
						label:
							settings.local_scene.hierarchy?.label ??
							settings.local_scene.name,
						value: settings.local_scene.slug,
					},
			  ]
			: []
	);
	const [ locationSearchError, setLocationSearchError ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState< {
		type: 'success' | 'error';
		message: string;
	} | null >( null );
	const locationSearchTimeout = useRef< number | null >( null );
	const locationSearchRequest = useRef( 0 );

	const searchLocations = useCallback(
		( search: string ) => {
			const request = ++locationSearchRequest.current;
			if ( locationSearchTimeout.current !== null ) {
				window.clearTimeout( locationSearchTimeout.current );
			}
			const trimmed = search.trim();
			if ( ! trimmed ) {
				setLocationSearchError( false );
				setLocationOptions(
					settings.local_scene
						? [
								{
									label:
										settings.local_scene.hierarchy?.label ??
										settings.local_scene.name,
									value: settings.local_scene.slug,
								},
						  ]
						: []
				);
				return;
			}

			locationSearchTimeout.current = window.setTimeout( () => {
				client
					.execute< EventLocationsResponse >(
						'extrachill/user-event-locations',
						{ mode: 'search', search: trimmed, limit: 10 }
					)
					.then( ( result ) => {
						if ( request === locationSearchRequest.current ) {
							setLocationSearchError( false );
							setLocationOptions(
								result.locations.map( ( location ) => ( {
									label: location.hierarchy.label,
									value: location.slug,
								} ) )
							);
						}
					} )
					.catch( () => {
						if ( request === locationSearchRequest.current ) {
							setLocationSearchError( true );
							setLocationOptions( [] );
						}
					} );
			}, LOCATION_SEARCH_DEBOUNCE_MS );
		},
		[ settings.local_scene ]
	);

	useEffect(
		() => () => {
			if ( locationSearchTimeout.current !== null ) {
				window.clearTimeout( locationSearchTimeout.current );
			}
		},
		[]
	);

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			const input: Record< string, string > = {
				first_name: firstName,
				last_name: lastName,
				display_name: displayName,
				local_scene_visibility: localSceneVisibility,
				concert_history_visibility: concertHistoryVisibility,
				event_attendance_visibility: eventAttendanceVisibility,
			};
			if ( localSceneChanged ) {
				input.local_scene = localSceneSlug ?? '';
			}
			const result = await client.execute<
				UserSettings & { message?: string }
			>( 'extrachill/update-user-settings', input );
			onUpdate( result );
			setLocalSceneSlug( result.local_scene?.slug ?? null );
			setLocalSceneVisibility( result.local_scene_visibility );
			setConcertHistoryVisibility( result.concert_history_visibility );
			setEventAttendanceVisibility( result.event_attendance_visibility );
			setLocalSceneChanged( false );
			setNotice( {
				type: 'success',
				message: result.message || 'Account details updated.',
			} );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message: err instanceof Error ? err.message : 'Update failed.',
			} );
		}
		setSaving( false );
	}, [
		firstName,
		lastName,
		displayName,
		localSceneSlug,
		localSceneVisibility,
		concertHistoryVisibility,
		eventAttendanceVisibility,
		localSceneChanged,
		onUpdate,
	] );

	return (
		<Panel>
			{ notice && (
				<Notice type={ notice.type } message={ notice.message } />
			) }
			<FieldGroup label="First Name" htmlFor="ec-first-name">
				<input
					id="ec-first-name"
					type="text"
					value={ firstName }
					onChange={ ( e ) => setFirstName( e.target.value ) }
				/>
			</FieldGroup>
			<FieldGroup label="Last Name" htmlFor="ec-last-name">
				<input
					id="ec-last-name"
					type="text"
					value={ lastName }
					onChange={ ( e ) => setLastName( e.target.value ) }
				/>
			</FieldGroup>
			<FieldGroup label="Display Name" htmlFor="ec-display-name">
				<select
					id="ec-display-name"
					value={ displayName }
					onChange={ ( e ) => setDisplayName( e.target.value ) }
				>
					{ settings.display_name_options.map( ( option ) => (
						<option key={ option } value={ option }>
							{ option }
						</option>
					) ) }
				</select>
			</FieldGroup>
			<FieldGroup help="Choose the city or region that anchors your local music scene.">
				{ locationSearchError && (
					<Notice
						type="error"
						message="Local Scene search is temporarily unavailable."
					/>
				) }
				<ComboboxControl
					label="Local Scene"
					value={ localSceneSlug }
					options={ locationOptions }
					onFilterValueChange={ searchLocations }
					onChange={ ( slug ) => {
						setLocalSceneSlug( slug ?? null );
						setLocalSceneChanged( true );
					} }
					allowReset={ true }
					placeholder="Search cities and regions"
				/>
			</FieldGroup>
			<FieldGroup help="Private scenes still personalize your event experience but do not appear on your public profile or forum posts.">
				<label htmlFor="ec-local-scene-visibility">
					<input
						id="ec-local-scene-visibility"
						type="checkbox"
						checked={ localSceneVisibility === 'public' }
						onChange={ ( event ) =>
							setLocalSceneVisibility(
								event.target.checked ? 'public' : 'private'
							)
						}
					/>
					Show my Local Scene publicly
				</label>
			</FieldGroup>
			<FieldGroup>
				<label htmlFor="ec-concert-history-visibility">
					<input
						id="ec-concert-history-visibility"
						type="checkbox"
						aria-describedby="ec-concert-history-visibility-help"
						checked={ concertHistoryVisibility === 'public' }
						onChange={ ( event ) =>
							setConcertHistoryVisibility(
								event.target.checked ? 'public' : 'private'
							)
						}
					/>
					Show my concert history publicly
				</label>
				<p
					id="ec-concert-history-visibility-help"
					style={ styles.mutedText }
				>
					When private, only you and network administrators can view
					your tracked shows and concert stats.
				</p>
			</FieldGroup>
			<FieldGroup>
				<label htmlFor="ec-event-attendance-visibility">
					<input
						id="ec-event-attendance-visibility"
						type="checkbox"
						aria-describedby="ec-event-attendance-visibility-help"
						checked={ eventAttendanceVisibility === 'public' }
						onChange={ ( event ) =>
							setEventAttendanceVisibility(
								event.target.checked ? 'public' : 'private'
							)
						}
					/>
					Show me in event attendee lists
				</label>
				<p
					id="ec-event-attendance-visibility-help"
					style={ styles.mutedText }
				>
					Your attendance still counts toward event totals when this
					is private, but your name and avatar stay hidden from
					attendee lists.
				</p>
			</FieldGroup>
			<ActionRow>
				<button
					className="button-1 button-small"
					style={ saving ? styles.button : undefined }
					onClick={ handleSave }
					disabled={ saving }
				>
					{ saving ? 'Saving...' : 'Save Account Details' }
				</button>
			</ActionRow>
		</Panel>
	);
}

function SecurityTab( {
	settings,
	onSettingsChange,
}: {
	settings: UserSettings;
	onSettingsChange: ( updated: UserSettings ) => void;
} ) {
	const [ newEmail, setNewEmail ] = useState( '' );
	const [ currentPassword, setCurrentPassword ] = useState( '' );
	const [ newPassword, setNewPassword ] = useState( '' );
	const [ confirmPassword, setConfirmPassword ] = useState( '' );
	const [ emailSaving, setEmailSaving ] = useState( false );
	const [ passwordSaving, setPasswordSaving ] = useState( false );
	const [ notice, setNotice ] = useState< {
		type: 'success' | 'error';
		message: string;
	} | null >( null );

	const handleEmailChange = useCallback( async () => {
		setEmailSaving( true );
		setNotice( null );
		try {
			const result = await client.execute< ChangeEmailResponse >(
				'extrachill/change-user-email',
				{ new_email: newEmail }
			);
			setNotice( { type: 'success', message: result.message } );
			setNewEmail( '' );
			onSettingsChange( {
				...settings,
				pending_email: result.pending_email,
			} );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message:
					err instanceof Error ? err.message : 'Email change failed.',
			} );
		}
		setEmailSaving( false );
	}, [ newEmail, settings, onSettingsChange ] );

	const handlePasswordChange = useCallback( async () => {
		setPasswordSaving( true );
		setNotice( null );
		try {
			const result = await client.execute< ChangePasswordResponse >(
				'extrachill/change-user-password',
				{
					current_password: currentPassword,
					new_password: newPassword,
					confirm_password: confirmPassword,
				}
			);
			setNotice( { type: 'success', message: result.message } );
			setCurrentPassword( '' );
			setNewPassword( '' );
			setConfirmPassword( '' );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message:
					err instanceof Error
						? err.message
						: 'Password change failed.',
			} );
		}
		setPasswordSaving( false );
	}, [ currentPassword, newPassword, confirmPassword ] );

	return (
		<Panel>
			{ notice && (
				<Notice type={ notice.type } message={ notice.message } />
			) }
			<FieldGroup label="Current Email Address">
				<input type="email" value={ settings.email } disabled />
				{ settings.pending_email && (
					<div className="notice notice-info">
						<p>
							Email change pending - verification sent to{ ' ' }
							<strong>{ settings.pending_email }</strong>
						</p>
						<p>
							<small>
								Check your inbox and click the verification
								link.
							</small>
						</p>
					</div>
				) }
			</FieldGroup>
			<FieldGroup
				label="New Email Address"
				htmlFor="ec-new-email"
				help="A verification email will be sent to your new address. Your current email will remain active until verification is complete."
			>
				<input
					id="ec-new-email"
					type="email"
					value={ newEmail }
					onChange={ ( e ) => setNewEmail( e.target.value ) }
					placeholder="Enter new email address"
				/>
			</FieldGroup>
			<ActionRow>
				<button
					className="button-1 button-small"
					style={
						emailSaving || ! newEmail ? styles.button : undefined
					}
					onClick={ handleEmailChange }
					disabled={ emailSaving || ! newEmail }
				>
					{ emailSaving ? 'Sending...' : 'Change Email' }
				</button>
			</ActionRow>
			<hr
				style={ {
					border: 'none',
					borderTop: `1px solid ${ cssVar( colors.borderColor ) }`,
					margin: `${ cssVar( spacing.spacingLg ) } 0`,
				} }
			/>
			<FieldGroup
				label="Current Password"
				htmlFor="ec-current-pass"
				required
			>
				<input
					id="ec-current-pass"
					type="password"
					value={ currentPassword }
					onChange={ ( e ) => setCurrentPassword( e.target.value ) }
					autoComplete="current-password"
				/>
			</FieldGroup>
			<FieldGroup label="New Password" htmlFor="ec-new-pass">
				<input
					id="ec-new-pass"
					type="password"
					value={ newPassword }
					onChange={ ( e ) => setNewPassword( e.target.value ) }
					autoComplete="new-password"
				/>
			</FieldGroup>
			<FieldGroup label="Confirm New Password" htmlFor="ec-confirm-pass">
				<input
					id="ec-confirm-pass"
					type="password"
					value={ confirmPassword }
					onChange={ ( e ) => setConfirmPassword( e.target.value ) }
					autoComplete="new-password"
				/>
			</FieldGroup>
			<ActionRow>
				<button
					className="button-1 button-small"
					style={
						passwordSaving || ! currentPassword || ! newPassword
							? styles.button
							: undefined
					}
					onClick={ handlePasswordChange }
					disabled={
						passwordSaving || ! currentPassword || ! newPassword
					}
				>
					{ passwordSaving ? 'Changing...' : 'Change Password' }
				</button>
			</ActionRow>
		</Panel>
	);
}

function SubscriptionsTab() {
	const [ data, setData ] = useState< UserSubscriptions | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState< {
		type: 'success' | 'error';
		message: string;
	} | null >( null );
	const [ consented, setConsented ] = useState< Set< number > >( new Set() );

	useEffect( () => {
		client
			.execute< UserSubscriptions >( 'extrachill/get-subscriptions' )
			.then( ( result ) => {
				setData( result );
				const ids = new Set< number >();
				result.followed_artists.forEach( ( a: FollowedArtist ) => {
					if ( a.email_consent ) {
						ids.add( a.artist_id );
					}
				} );
				setConsented( ids );
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [] );

	const toggleConsent = useCallback( ( artistId: number ) => {
		setConsented( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( artistId ) ) {
				next.delete( artistId );
			} else {
				next.add( artistId );
			}
			return next;
		} );
	}, [] );

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			await client.execute( 'extrachill/update-subscriptions', {
				consented_artists: Array.from( consented ),
			} );
			setNotice( {
				type: 'success',
				message: 'Subscription preferences updated.',
			} );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message: err instanceof Error ? err.message : 'Update failed.',
			} );
		}
		setSaving( false );
	}, [ consented ] );

	if ( loading ) {
		return (
			<div className="notice notice-info">
				<p>Loading subscriptions...</p>
			</div>
		);
	}
	const artists = data?.followed_artists || [];

	return (
		<Panel>
			<PanelHeader description="Manage email consent for bands you follow. Unchecking will prevent a band from seeing your email or including it in their exports." />
			{ notice && (
				<Notice type={ notice.type } message={ notice.message } />
			) }
			{ artists.length === 0 ? (
				<p style={ styles.mutedText }>
					You are not currently following any bands.
				</p>
			) : (
				<>
					<ul style={ styles.checkboxList }>
						{ artists.map( ( artist: FollowedArtist ) => (
							<li
								key={ artist.artist_id }
								style={ styles.checkboxItem }
							>
								<input
									type="checkbox"
									id={ `ec-consent-${ artist.artist_id }` }
									checked={ consented.has(
										artist.artist_id
									) }
									onChange={ () =>
										toggleConsent( artist.artist_id )
									}
								/>
								<label
									htmlFor={ `ec-consent-${ artist.artist_id }` }
									style={ {
										fontWeight: 'normal',
										cursor: 'pointer',
									} }
								>
									Share my email with{ ' ' }
									<a
										href={ artist.url }
										target="_blank"
										rel="noopener noreferrer"
										style={ {
											color: cssVar( colors.linkColor ),
										} }
									>
										{ artist.name }
									</a>
								</label>
							</li>
						) ) }
					</ul>
					<ActionRow>
						<button
							className="button-1 button-small"
							style={ saving ? styles.button : undefined }
							onClick={ handleSave }
							disabled={ saving }
						>
							{ saving ? 'Saving...' : 'Save Preferences' }
						</button>
					</ActionRow>
				</>
			) }
		</Panel>
	);
}

function NotificationsTab() {
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ emailsEnabled, setEmailsEnabled ] = useState( true );
	const [ autoSubscribe, setAutoSubscribe ] = useState( true );
	const [ notice, setNotice ] = useState< {
		type: 'success' | 'error';
		message: string;
	} | null >( null );

	useEffect( () => {
		client
			.execute< NotificationPreferences >(
				'extrachill/get-notification-preferences'
			)
			.then( ( result ) => {
				setEmailsEnabled( result.emails_enabled );
				setAutoSubscribe( result.auto_subscribe_replies );
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [] );

	const handleSave = useCallback( async () => {
		setSaving( true );
		setNotice( null );
		try {
			await client.execute(
				'extrachill/update-notification-preferences',
				{
					emails_enabled: emailsEnabled,
					auto_subscribe_replies: autoSubscribe,
				}
			);
			setNotice( {
				type: 'success',
				message: 'Notification preferences updated.',
			} );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message: err instanceof Error ? err.message : 'Update failed.',
			} );
		}
		setSaving( false );
	}, [ emailsEnabled, autoSubscribe ] );

	if ( loading ) {
		return (
			<div className="notice notice-info">
				<p>Loading notification preferences...</p>
			</div>
		);
	}

	return (
		<Panel>
			<PanelHeader description="Control how Extra Chill keeps you in the loop." />
			{ notice && (
				<Notice type={ notice.type } message={ notice.message } />
			) }
			<ul style={ styles.checkboxList }>
				<li style={ styles.checkboxItem }>
					<input
						type="checkbox"
						id="ec-notif-emails"
						checked={ emailsEnabled }
						onChange={ ( e ) =>
							setEmailsEnabled( e.target.checked )
						}
					/>
					<label
						htmlFor="ec-notif-emails"
						style={ { fontWeight: 'normal', cursor: 'pointer' } }
					>
						<strong>Email notifications</strong>
						<div style={ styles.mutedText }>
							Receive email digests for your notifications.
						</div>
					</label>
				</li>
				<li style={ styles.checkboxItem }>
					<input
						type="checkbox"
						id="ec-notif-auto-subscribe"
						checked={ autoSubscribe }
						onChange={ ( e ) =>
							setAutoSubscribe( e.target.checked )
						}
					/>
					<label
						htmlFor="ec-notif-auto-subscribe"
						style={ { fontWeight: 'normal', cursor: 'pointer' } }
					>
						<strong>Auto-subscribe to replies</strong>
						<div style={ styles.mutedText }>
							Automatically follow topics you reply to.
						</div>
					</label>
				</li>
			</ul>
			<ActionRow>
				<button
					className="button-1 button-small"
					style={ saving ? styles.button : undefined }
					onClick={ handleSave }
					disabled={ saving }
				>
					{ saving ? 'Saving...' : 'Save Preferences' }
				</button>
			</ActionRow>
		</Panel>
	);
}

function ArtistPlatformTab( {
	artistAccess,
	artistSiteUrl,
	hasArtists,
	canCreateArtists,
}: {
	artistAccess: {
		status: string;
		type: string;
		request_type?: string;
		requested_at?: number;
	};
	artistSiteUrl: string;
	hasArtists: boolean;
	canCreateArtists: boolean;
} ) {
	const [ accessType, setAccessType ] = useState< 'artist' | 'professional' >(
		'artist'
	);
	const [ submitting, setSubmitting ] = useState( false );
	const [ notice, setNotice ] = useState< {
		type: 'success' | 'error';
		message: string;
	} | null >( null );
	const [ currentStatus, setCurrentStatus ] = useState( artistAccess.status );

	const handleRequest = useCallback( async () => {
		setSubmitting( true );
		setNotice( null );
		try {
			const result = await client.execute< RequestArtistAccessResponse >(
				'extrachill/request-artist-access',
				{ type: accessType }
			);
			setNotice( { type: 'success', message: result.message } );
			setCurrentStatus( 'pending' );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message: err instanceof Error ? err.message : 'Request failed.',
			} );
		}
		setSubmitting( false );
	}, [ accessType ] );

	return (
		<Panel>
			{ notice && (
				<Notice type={ notice.type } message={ notice.message } />
			) }
			{ currentStatus === 'approved' && (
				<div className="notice notice-success">
					<p>
						<strong>You have artist platform access!</strong>
					</p>
					<p>
						You can create artist profiles and link pages on
						extrachill.link.
					</p>
					{ hasArtists && (
						<ActionRow>
							<a
								href={ `${ artistSiteUrl }/manage-artist/` }
								className="button-1 button-small"
							>
								Manage Artist
							</a>
						</ActionRow>
					) }
					{ ! hasArtists && canCreateArtists && (
						<ActionRow>
							<a
								href={ `${ artistSiteUrl }/create-artist/` }
								className="button-1 button-small"
							>
								Create Artist Profile
							</a>
						</ActionRow>
					) }
				</div>
			) }
			{ currentStatus === 'pending' && (
				<div className="notice notice-info">
					<p>
						<strong>Your request is pending admin review.</strong>
					</p>
					{ artistAccess.request_type && (
						<p>
							You requested access as &quot;
							{ artistAccess.request_type === 'artist'
								? 'I am a musician'
								: 'I work in the music industry' }
							&quot;
							{ artistAccess.requested_at
								? ` on ${ new Date(
										artistAccess.requested_at * 1000
								  ).toLocaleDateString() }`
								: '' }
							.
						</p>
					) }
					<p>An administrator will review your request shortly.</p>
				</div>
			) }
			{ currentStatus === 'none' && (
				<>
					<p
						style={ {
							...styles.mutedText,
							marginBottom: cssVar( spacing.spacingMd ),
						} }
					>
						Get access to create artist profiles and link pages on
						extrachill.link.
					</p>
					<fieldset>
						<legend>Select which best describes you:</legend>
						<p>
							<label htmlFor="ec-artist-access-type-artist">
								<input
									id="ec-artist-access-type-artist"
									type="radio"
									name="ec-artist-access-type"
									value="artist"
									checked={ accessType === 'artist' }
									onChange={ () => setAccessType( 'artist' ) }
								/>
								I am a musician
							</label>
						</p>
						<p>
							<label htmlFor="ec-artist-access-type-professional">
								<input
									id="ec-artist-access-type-professional"
									type="radio"
									name="ec-artist-access-type"
									value="professional"
									checked={ accessType === 'professional' }
									onChange={ () =>
										setAccessType( 'professional' )
									}
								/>
								I work in the music industry
							</label>
						</p>
					</fieldset>
					<ActionRow>
						<button
							className="button-1 button-small"
							style={ submitting ? styles.button : undefined }
							onClick={ handleRequest }
							disabled={ submitting }
						>
							{ submitting ? 'Submitting...' : 'Request Access' }
						</button>
					</ActionRow>
				</>
			) }
		</Panel>
	);
}

type TabId =
	| 'account-details'
	| 'security'
	| 'subscriptions'
	| 'notifications'
	| 'artist-platform';

export function UserSettingsApp( {
	artistSiteUrl,
	hasArtists,
	canCreateArtists,
	userId,
}: {
	artistSiteUrl: string;
	hasArtists: boolean;
	canCreateArtists: boolean;
	userId: number;
} ) {
	const [ activeTab, setActiveTab ] = useState< TabId >( 'account-details' );
	const [ settings, setSettings ] = useState< UserSettings | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ artistAccess, setArtistAccess ] = useState< {
		status: string;
		type: string;
		request_type?: string;
		requested_at?: number;
	} >( { status: 'none', type: '' } );

	useEffect( () => {
		client
			.execute< UserProfile >( 'extrachill/get-user-profile', {
				user_id: userId,
			} )
			.then( ( profileData ) => {
				setArtistAccess( profileData.artist_access );
			} )
			.catch( () => undefined );

		client
			.execute< UserSettings >( 'extrachill/get-user-settings' )
			.then( ( settingsData ) => {
				setSettings( settingsData );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setError(
					err instanceof Error
						? err.message
						: 'Failed to load settings.'
				);
				setLoading( false );
			} );
	}, [ userId ] );

	const switchTab = useCallback( ( tab: TabId ) => {
		setActiveTab( tab );
	}, [] );
	if ( loading ) {
		return (
			<div className="notice notice-info">
				<p>Loading settings...</p>
			</div>
		);
	}
	if ( error || ! settings ) {
		return (
			<Notice
				type="error"
				message={ error || 'Failed to load settings.' }
			/>
		);
	}

	const tabs: Array< { id: TabId; label: string } > = [
		{ id: 'account-details', label: 'Account Details' },
		{ id: 'security', label: 'Security' },
		{ id: 'subscriptions', label: 'Subscriptions' },
		{ id: 'notifications', label: 'Notifications' },
		{ id: 'artist-platform', label: 'Artist Platform' },
	];

	const renderTabPanel = ( id: string ) => {
		switch ( id as TabId ) {
			case 'account-details':
				return (
					<AccountTab
						settings={ settings }
						onUpdate={ setSettings }
					/>
				);
			case 'security':
				return (
					<SecurityTab
						settings={ settings }
						onSettingsChange={ setSettings }
					/>
				);
			case 'subscriptions':
				return <SubscriptionsTab />;
			case 'notifications':
				return <NotificationsTab />;
			case 'artist-platform':
				return (
					<ArtistPlatformTab
						artistAccess={ artistAccess }
						artistSiteUrl={ artistSiteUrl }
						hasArtists={ hasArtists }
						canCreateArtists={ canCreateArtists }
					/>
				);
			default:
				return null;
		}
	};

	return (
		<BlockShell>
			<BlockShellInner maxWidth="narrow">
				<BlockShellHeader
					title="Settings"
					description="Manage your account, security, subscriptions, notifications, and artist platform access."
				/>
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
	document
		.querySelectorAll< HTMLElement >( '.wp-block-extrachill-user-settings' )
		.forEach( ( container ) => {
			if ( container.dataset.initialized === '1' ) {
				return;
			}
			container.dataset.initialized = '1';
			const artistSiteUrl =
				container.dataset.artistSiteUrl ||
				'https://artist.extrachill.com';
			const hasArtists = container.dataset.hasArtists === '1';
			const canCreateArtists = container.dataset.canCreateArtists === '1';
			const userId = Number( container.dataset.userId || '0' );
			const root = createRoot( container );
			root.render(
				<UserSettingsApp
					artistSiteUrl={ artistSiteUrl }
					hasArtists={ hasArtists }
					canCreateArtists={ canCreateArtists }
					userId={ userId }
				/>
			);
		} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
