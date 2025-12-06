/**
 * useMediaUpload Hook
 *
 * Handles media file uploads via REST API.
 */

import { useState, useCallback } from '@wordpress/element';
import { uploadMedia, deleteMedia } from '../api/client';

export default function useMediaUpload() {
	const [ isUploading, setIsUploading ] = useState( false );
	const [ error, setError ] = useState( null );

	const upload = useCallback( async ( context, targetId, file ) => {
		setIsUploading( true );
		setError( null );

		try {
			const result = await uploadMedia( context, targetId, file );
			return result;
		} catch ( err ) {
			setError( err.message || 'Upload failed' );
			throw err;
		} finally {
			setIsUploading( false );
		}
	}, [] );

	const remove = useCallback( async ( context, targetId ) => {
		setIsUploading( true );
		setError( null );

		try {
			const result = await deleteMedia( context, targetId );
			return result;
		} catch ( err ) {
			setError( err.message || 'Removal failed' );
			throw err;
		} finally {
			setIsUploading( false );
		}
	}, [] );

	return {
		upload,
		remove,
		isUploading,
		error,
	};
}
