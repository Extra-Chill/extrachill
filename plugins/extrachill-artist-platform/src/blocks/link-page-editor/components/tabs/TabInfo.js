/**
 * TabInfo Component
 *
 * Artist profile info: name, bio, profile image.
 */

import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { FieldGroup } from '@extrachill/components';
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
			if ( result?.attachment_id && result?.url ) {
				setProfileImage( result.attachment_id, result.url );
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
			<FieldGroup label={ __( 'Display Name', 'extrachill-artist-platform' ) } htmlFor="ec-artist-name">
				<input
					id="ec-artist-name"
					type="text"
					className="ec-field__input"
					value={ artist?.name || '' }
					onChange={ handleNameChange }
					placeholder={ __( 'Your name or artist name', 'extrachill-artist-platform' ) }
				/>
			</FieldGroup>

			<FieldGroup label={ __( 'Bio', 'extrachill-artist-platform' ) } htmlFor="ec-artist-bio">
				<textarea
					id="ec-artist-bio"
					className="ec-field__textarea"
					value={ artist?.bio || '' }
					onChange={ handleBioChange }
					rows={ 4 }
					placeholder={ __( 'A short bio about you...', 'extrachill-artist-platform' ) }
				/>
			</FieldGroup>

			<FieldGroup label={ __( 'Profile Image', 'extrachill-artist-platform' ) }>
				<ImageUploader
					imageUrl={ artist?.profile_image_url }
					onUpload={ handleImageUpload }
					onRemove={ handleImageRemove }
					isUploading={ isUploading }
					accept="image/*"
				/>
			</FieldGroup>
		</div>
	);
}
