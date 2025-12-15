import React, { useMemo, useState } from 'react';
import { render } from '@wordpress/element';
import { createArtist, uploadMedia } from '../shared/api/client';

const useConfig = () => {
	const config = window.ecArtistCreatorConfig || {};
	return useMemo(
		() => ( {
			restUrl: config.restUrl,
			nonce: config.nonce,
			prefill: config.prefill || {},
			manageArtistUrl: config.manageArtistUrl || '/manage-artist-profiles/',
			createLinkPageUrl: config.createLinkPageUrl || '/manage-link-page/',
		} ),
		[ config ]
	);
};

const App = () => {
	const config = useConfig();
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ createdArtist, setCreatedArtist ] = useState( null );
	const [ formState, setFormState ] = useState( {
		name: config.prefill?.artist_name || '',
		profileImage: config.prefill?.avatar_thumb || '',
		profileImageId: config.prefill?.avatar_id || null,
	} );

	const handleFileUpload = async ( file ) => {
		try {
			const response = await uploadMedia( 'artist_profile', 0, file );
			setFormState( ( prev ) => ( {
				...prev,
				profileImage: response?.url || '',
				profileImageId: response?.attachment_id || null,
			} ) );
		} catch ( err ) {
			setError( 'Failed to upload image.' );
		}
	};

	const handleRemoveImage = () => {
		setFormState( ( prev ) => ( {
			...prev,
			profileImage: '',
			profileImageId: null,
		} ) );
	};

	const handleSubmit = async ( e ) => {
		e.preventDefault();

		if ( ! formState.name.trim() ) {
			setError( 'Artist name is required.' );
			return;
		}

		setSaving( true );
		setError( '' );

		const payload = {
			name: formState.name,
			profile_image_id: formState.profileImageId,
		};

		try {
			const created = await createArtist( payload );
			if ( created?.id ) {
				setCreatedArtist( created );
			}
		} catch ( err ) {
			setError( err?.message || 'Failed to create artist profile.' );
		} finally {
			setSaving( false );
		}
	};

	if ( createdArtist ) {
		return (
			<div className="notice notice-success">
				<p>
					<strong>
						Your artist profile "{ createdArtist.name }" has been created!
					</strong>
				</p>
				<p>What would you like to do next?</p>
				<div className="ec-artist-creator__actions">
					<a
						href={ config.manageArtistUrl }
						className="button-2 button-medium"
					>
						Manage Artist Profile
					</a>
					<a
						href={ config.createLinkPageUrl }
						className="button-1 button-medium"
					>
						Create Link Page
					</a>
				</div>
			</div>
		);
	}

	return (
		<div className="ec-artist-creator-form">
			<h2>Create Artist Profile</h2>
			<p className="ec-artist-creator-intro">
				Set up your artist profile to start building your presence on Extra Chill.
			</p>

			<form onSubmit={ handleSubmit }>
				<div className="ec-artist-creator-field">
					<label htmlFor="ec-artist-name">
						Artist Name <span className="required">*</span>
					</label>
					<input
						type="text"
						id="ec-artist-name"
						value={ formState.name }
						onChange={ ( e ) =>
							setFormState( ( prev ) => ( {
								...prev,
								name: e.target.value,
							} ) )
						}
						placeholder="Enter your artist or band name"
						required
					/>
				</div>

				<div className="ec-artist-creator-field">
					<label htmlFor="ec-artist-profile-image">Profile Picture</label>
					{ formState.profileImage && (
						<div className="ec-artist-creator-image-preview">
							<img
								src={ formState.profileImage }
								alt="Profile preview"
							/>
							<button
								type="button"
								className="button-danger button-small"
								onClick={ handleRemoveImage }
							>
								Remove
							</button>
						</div>
					) }
					<input
						id="ec-artist-profile-image"
						type="file"
						accept="image/*"
						onChange={ ( e ) => {
							const file = e.target.files?.[ 0 ];
							if ( file ) {
								handleFileUpload( file );
							}
						} }
					/>
				</div>

				{ error && <p className="ec-artist-creator-error">{ error }</p> }

				<div className="ec-artist-creator-actions">
					<button
						type="submit"
						className="button-1 button-large"
						disabled={ saving }
					>
						{ saving ? 'Creating...' : 'Create Artist Profile' }
					</button>
				</div>
			</form>
		</div>
	);
};

const rootEl = document.getElementById( 'ec-artist-creator-root' );

if ( rootEl ) {
	render( <App />, rootEl );
}
