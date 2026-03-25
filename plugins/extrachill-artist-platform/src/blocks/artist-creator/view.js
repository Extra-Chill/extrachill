import React, { useMemo, useState } from 'react';
import { render } from '@wordpress/element';
import { ActionRow, FieldGroup, ImagePreview, InlineStatus, MediaField, Panel, PanelHeader } from '@extrachill/components';
import { createArtist, uploadMedia } from '../shared/api/client';

const useConfig = () => {
	const config = window.ecArtistCreatorConfig || {};
	return useMemo(
		() => ( {
			restUrl: config.restUrl,
			nonce: config.nonce,
			prefill: config.prefill || {},
			manageArtistUrl: config.manageArtistUrl || '/manage-artist/',
			createLinkPageUrl: config.createLinkPageUrl || '/manage-link-page/',
			createShopUrl: config.createShopUrl || '',
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
		profileImageFile: null,
	} );

	const handleFileSelect = ( file ) => {
		if ( ! file ) {
			return;
		}

		setFormState( ( prev ) => ( {
			...prev,
			profileImage: URL.createObjectURL( file ),
			profileImageId: null,
			profileImageFile: file,
		} ) );
	};

	const handleRemoveImage = () => {
		setFormState( ( prev ) => ( {
			...prev,
			profileImage: '',
			profileImageId: null,
			profileImageFile: null,
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

		try {
			const created = await createArtist( { name: formState.name } );

			if ( created?.id && formState.profileImageFile ) {
				try {
					const uploadResponse = await uploadMedia(
						'artist_profile',
						created.id,
						formState.profileImageFile
					);

					setFormState( ( prev ) => ( {
						...prev,
						profileImage: uploadResponse?.url || prev.profileImage,
						profileImageId: uploadResponse?.attachment_id || null,
						profileImageFile: null,
					} ) );
				} catch ( err ) {
					setError( 'Artist created, but image upload failed.' );
				}
			}

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
				<Panel>
					<PanelHeader
						title={ `Your artist profile "${ createdArtist.name }" has been created!` }
						description="What would you like to do next?"
					/>
					<ActionRow className="ec-artist-creator__actions">
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
						{ config.createShopUrl && (
							<a
								href={ config.createShopUrl }
								className="button-1 button-medium"
							>
								Create Shop
							</a>
						) }
					</ActionRow>

				{ error && (
					<InlineStatus tone="error">Error: { error }</InlineStatus>
				) }
				</Panel>
		);
	}

	return (
		<Panel className="ec-artist-creator-form">
			<PanelHeader
				title="Create Artist Profile"
				description="Set up your artist profile to start building your presence on Extra Chill."
			/>

			<form onSubmit={ handleSubmit }>
				<FieldGroup label="Artist Name" htmlFor="ec-artist-name" required>
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
				</FieldGroup>

				<MediaField
					label="Profile Picture"
					htmlFor="ec-artist-profile-image"
					preview={ formState.profileImage ? <ImagePreview src={ formState.profileImage } alt="Profile preview" className="ec-artist-creator-image-preview" /> : null }
					empty="No profile image selected yet."
						actions={
							<>
								<input
									id="ec-artist-profile-image"
								type="file"
								accept="image/*"
								onChange={ ( e ) => handleFileSelect( e.target.files?.[ 0 ] ) }
							/>
							{ formState.profileImage ? (
								<button
									type="button"
									className="button-danger button-small"
									onClick={ handleRemoveImage }
								>
									Remove
								</button>
							) : null }
						</>
					}
				/>

				{ error && <InlineStatus tone="error">{ error }</InlineStatus> }

				<ActionRow className="ec-artist-creator-actions">
					<button
						type="submit"
						className="button-1 button-large"
						disabled={ saving }
					>
						{ saving ? 'Creating...' : 'Create Artist Profile' }
					</button>
				</ActionRow>
			</form>
		</Panel>
	);
};

const rootEl = document.getElementById( 'ec-artist-creator-root' );

if ( rootEl ) {
	render( <App />, rootEl );
}
