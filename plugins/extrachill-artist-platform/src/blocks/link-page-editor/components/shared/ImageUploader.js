/**
 * ImageUploader Component
 *
 * File upload with preview, upload button, and remove button.
 */

import { useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export default function ImageUploader( {
	imageUrl,
	onUpload,
	onRemove,
	isUploading,
	accept = 'image/*',
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
		<div className="ec-image-uploader">
			<input
				ref={ fileInputRef }
				type="file"
				accept={ accept }
				onChange={ handleFileSelect }
				className="ec-image-uploader__input"
				disabled={ isUploading }
			/>

			{ imageUrl ? (
				<div className="ec-image-uploader__preview">
					<img src={ imageUrl } alt="" className="ec-image-uploader__image" />
					<div className="ec-image-uploader__actions">
						<button
							type="button"
							className="button-2"
							onClick={ handleButtonClick }
							disabled={ isUploading }
						>
							{ isUploading
								? __( 'Uploading...', 'extrachill-artist-platform' )
								: __( 'Change', 'extrachill-artist-platform' ) }
						</button>
						<button
							type="button"
							className="button-2 button-2--danger"
							onClick={ onRemove }
							disabled={ isUploading }
						>
							{ __( 'Remove', 'extrachill-artist-platform' ) }
						</button>
					</div>
				</div>
			) : (
				<div className="ec-image-uploader__empty">
					<button
						type="button"
						className="button-2"
						onClick={ handleButtonClick }
						disabled={ isUploading }
					>
						{ isUploading
							? __( 'Uploading...', 'extrachill-artist-platform' )
							: __( 'Upload Image', 'extrachill-artist-platform' ) }
					</button>
				</div>
			) }
		</div>
	);
}
