import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import type { ContestConfig, Entry } from '../types';

export function SharePanel( {
	config,
	entry,
	prompt,
	initialShowQr = false,
}: {
	entry: Entry;
	prompt: string;
	initialShowQr?: boolean;
	config: ContestConfig;
} ) {
	const [ copied, setCopied ] = useState( false );
	const [ showQr, setShowQr ] = useState( initialShowQr );
	const [ qrImage, setQrImage ] = useState( '' );
	const [ qrError, setQrError ] = useState( '' );
	const [ qrAttempt, setQrAttempt ] = useState( 0 );

	useEffect( () => {
		if ( ! showQr ) {
			return;
		}
		let cancelled = false;
		setQrImage( '' );
		setQrError( '' );
		apiFetch( {
			path: `/extrachill/v1/content-blocks/photo-contest/entry-qr?post_id=${
				config.postId
			}&contest_id=${ encodeURIComponent( config.contestId ) }&entry_id=${
				entry.id
			}`,
			method: 'GET',
		} )
			.then( ( result ) => {
				const { image, mime_type: mimeType } = result as {
					image?: string;
					mime_type?: string;
				};
				if ( ! image || ! mimeType ) {
					throw new Error( 'Missing QR image' );
				}
				if ( ! cancelled ) {
					setQrImage( `data:${ mimeType };base64,${ image }` );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setQrError(
						'Could not load QR code. Copy the link or try again.'
					);
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ showQr, config.postId, config.contestId, entry.id, qrAttempt ] );
	const shareText = `Vote for ${ entry.display_name }!`;
	const canWebShare = typeof navigator !== 'undefined' && !! navigator.share;

	const webShare = async () => {
		try {
			await navigator.share( {
				title: shareText,
				text: shareText,
				url: entry.share_url,
			} );
		} catch {
			// User cancelled; ignore.
		}
	};

	const copyLink = async () => {
		try {
			await navigator.clipboard.writeText( entry.share_url );
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} catch {
			// Clipboard unavailable; the visible link below remains selectable.
		}
	};

	const encodedUrl = encodeURIComponent( entry.share_url );
	const encodedText = encodeURIComponent( shareText );

	return (
		<div className="extrachill-blocks-photo-contest-share">
			<p className="extrachill-blocks-photo-contest-share-prompt">
				{ prompt }
			</p>
			<div className="extrachill-blocks-photo-contest-share-buttons">
				{ canWebShare && (
					<button
						className="button-1 button-medium"
						onClick={ webShare }
					>
						Share
					</button>
				) }
				<button className="button-2 button-medium" onClick={ copyLink }>
					{ copied ? 'Copied!' : 'Copy Link' }
				</button>
				<a
					className="button-3 button-medium"
					href={ `https://www.facebook.com/sharer/sharer.php?u=${ encodedUrl }` }
					target="_blank"
					rel="noopener noreferrer"
				>
					Facebook
				</a>
				<a
					className="button-3 button-medium"
					href={ `https://twitter.com/intent/tweet?url=${ encodedUrl }&text=${ encodedText }` }
					target="_blank"
					rel="noopener noreferrer"
				>
					X
				</a>
				<button
					className="button-2 button-medium"
					aria-expanded={ showQr }
					onClick={ () => setShowQr( ! showQr ) }
				>
					{ showQr ? 'Hide QR' : 'Show QR' }
				</button>
			</div>
			{ showQr && (
				<div
					className="extrachill-blocks-photo-contest-qr"
					aria-live="polite"
				>
					{ qrImage ? (
						<img
							src={ qrImage }
							alt={ `Scan to vote for ${ entry.display_name }` }
						/>
					) : qrError ? (
						<>
							<p className="notice notice-error">{ qrError }</p>
							<button
								className="button-2 button-small"
								onClick={ () => setQrAttempt( qrAttempt + 1 ) }
							>
								Try again
							</button>
						</>
					) : (
						<p>Loading QR code…</p>
					) }
				</div>
			) }
			<p className="extrachill-blocks-photo-contest-share-link">
				{ entry.share_url }
			</p>
		</div>
	);
}

