/**
 * TabInfo Component
 *
 * Artist profile info: name, bio, profile image.
 */

import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useEditor } from '../../context/EditorContext';
import ImageUploader from '../shared/ImageUploader';

export default function TabInfo() {
	const {
		artistId,
		artist,
		setName,
		setBio,
		setProfileImage,
		uploadMedia,
		removeMedia,
		isUploading,
	} = useEditor();

	const handleNameChange = useCallback(
		( e ) => {
			setName( e.target.value );
		},
		[ setName ]
	);

	const handleBioChange = useCallback(
		( e ) => {
			setBio( e.target.value );
		},
		[ setBio ]
	);

	const handleImageUpload = useCallback(
		async ( file ) => {
			const result = await uploadMedia( 'artist_profile', artistId, file );
			if ( result?.id && result?.url ) {
				setProfileImage( result.id, result.url );
			}
		},
		[ artistId, uploadMedia, setProfileImage ]
	);

	const handleImageRemove = useCallback( async () => {
		await removeMedia( 'artist_profile', artistId );
		setProfileImage( null, null );
	}, [ artistId, removeMedia, setProfileImage ] );

	return (
		<div className="ec-tab ec-tab--info">
			<div className="ec-field">
				<label htmlFor="ec-artist-name" className="ec-field__label">
					{ __( 'Display Name', 'extrachill-artist-platform' ) }
				</label>
				<input
					id="ec-artist-name"
					type="text"
					className="ec-field__input"
					value={ artist?.name || '' }
					onChange={ handleNameChange }
					placeholder={ __( 'Your name or artist name', 'extrachill-artist-platform' ) }
				/>
			</div>

			<div className="ec-field">
				<label htmlFor="ec-artist-bio" className="ec-field__label">
					{ __( 'Bio', 'extrachill-artist-platform' ) }
				</label>
				<textarea
					id="ec-artist-bio"
					className="ec-field__textarea"
					value={ artist?.bio || '' }
					onChange={ handleBioChange }
					rows={ 4 }
					placeholder={ __( 'A short bio about you...', 'extrachill-artist-platform' ) }
				/>
			</div>

			<div className="ec-field">
				<label className="ec-field__label">
					{ __( 'Profile Image', 'extrachill-artist-platform' ) }
				</label>
				<ImageUploader
					imageUrl={ artist?.profile_image_url }
					onUpload={ handleImageUpload }
					onRemove={ handleImageRemove }
					isUploading={ isUploading }
					accept="image/*"
				/>
			</div>
		</div>
	);
}
