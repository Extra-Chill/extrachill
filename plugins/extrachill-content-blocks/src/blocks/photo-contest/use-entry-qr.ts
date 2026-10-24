import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import type { ContestConfig } from './types';

/**
 * Fetch an entry's vote QR (data URL) from the public entry-qr route.
 * @param config  Contest config.
 * @param entryId Entry ID.
 * @param enabled Fetch only when true.
 * @param attempt Bump to retry.
 */
export function useEntryQr(
	config: ContestConfig,
	entryId: number,
	enabled: boolean,
	attempt = 0
): { image: string; error: boolean } {
	const [ image, setImage ] = useState( '' );
	const [ error, setError ] = useState( false );
	useEffect( () => {
		if ( ! enabled ) {
			return;
		}
		let cancelled = false;
		setImage( '' );
		setError( false );
		apiFetch( {
			path: `/extrachill/v1/content-blocks/photo-contest/entry-qr?post_id=${
				config.postId
			}&contest_id=${ encodeURIComponent(
				config.contestId
			) }&entry_id=${ entryId }`,
			method: 'GET',
		} )
			.then( ( result ) => {
				const { image: data, mime_type: mimeType } = result as {
					image?: string;
					mime_type?: string;
				};
				if ( ! data || ! mimeType ) {
					throw new Error( 'Missing QR image' );
				}
				if ( ! cancelled ) {
					setImage( `data:${ mimeType };base64,${ data }` );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError( true );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ enabled, config.postId, config.contestId, entryId, attempt ] );
	return { image, error };
}
