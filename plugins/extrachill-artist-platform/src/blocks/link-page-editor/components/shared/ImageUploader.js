/**
 * ImageUploader Component
 *
 * File upload with preview, upload button, and remove button.
 */

import { useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { MediaField } from '@extrachill/components';

export default function ImageUploader( {
	imageUrl,
	onUpload,
	onRemove,
	isUploading,
	accept = 'image/*',
	label,
	help,
} ) {
	const fileInputRef = useRef( null );

	const handleFileSelect = useCallback(
		( e ) => {
			const file = e.target.files?.[ 0 ];
			if ( file ) {
				onUpload( file );
			}
			e.target.value = '';
		},
		[ onUpload ]
	);

	const handleButtonClick = useCallback( () => {
		fileInputRef.current?.click();
	}, [] );

	return (
		<MediaField
			label={ label }
			help={ help }
			previewUrl={ imageUrl }
			previewAlt=""
			empty={ __( 'No image selected yet.', 'extrachill-artist-platform' ) }
			actions={
				<>
					<button
						type="button"
						className="button-2 button-small"
						onClick={ handleButtonClick }
						disabled={ isUploading }
					>
						{ isUploading
							? __( 'Uploading...', 'extrachill-artist-platform' )
							: imageUrl
								? __( 'Change', 'extrachill-artist-platform' )
								: __( 'Upload Image', 'extrachill-artist-platform' ) }
					</button>
					{ imageUrl ? (
						<button
							type="button"
							className="button-danger button-small"
							onClick={ onRemove }
							disabled={ isUploading }
						>
							{ __( 'Remove', 'extrachill-artist-platform' ) }
						</button>
					) : null }
				</>
			}
			className="ec-image-uploader"
		>
			<input
				ref={ fileInputRef }
				type="file"
				accept={ accept }
				onChange={ handleFileSelect }
				className="ec-image-uploader__input"
				disabled={ isUploading }
			/>
		</MediaField>
	);
}
