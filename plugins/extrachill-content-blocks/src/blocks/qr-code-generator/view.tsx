import { createRoot, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

export function QRCodeGenerator() {
	const [ url, setUrl ] = useState( '' );
	const [ image, setImage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const pending = useRef( false );

	async function generate( event: React.FormEvent ) {
		event.preventDefault();
		if ( pending.current ) return;
		setError( '' );
		setImage( '' );
		try {
			const parsed = new URL( url.trim() );
			if ( ! [ 'http:', 'https:' ].includes( parsed.protocol ) ) throw new Error();
		} catch {
			setError( 'Please enter a valid HTTP or HTTPS URL.' );
			return;
		}
		pending.current = true;
		setBusy( true );
		try {
			const result = await apiFetch< { image_url: string } >( {
				path: '/extrachill/v1/tools/qr-code',
				method: 'POST',
				data: { url: url.trim() },
			} );
			if ( ! result.image_url?.startsWith( 'data:image/png;base64,' ) ) {
				throw new Error( 'QR generation returned an invalid image.' );
			}
			setImage( result.image_url );
		} catch ( failure ) {
			setError( ( failure as { message?: string } )?.message || 'Could not generate QR code. Please try again.' );
		} finally {
			pending.current = false;
			setBusy( false );
		}
	}

	return (
		<>
			<p>Free forever. Static QR codes never expire, with no signup and no redirects.</p>
			<form className="ec-form-stack" onSubmit={ generate } aria-busy={ busy }>
				<label>
					URL
					<input type="url" value={ url } maxLength={ 2048 } required
						placeholder="https://example.com" disabled={ busy }
						onChange={ ( event ) => { setUrl( event.target.value ); setImage( '' ); } } />
				</label>
				<button type="submit" className="button-1" disabled={ busy }>
					{ busy ? 'Generating…' : 'Generate' }
				</button>
			</form>
			{ error && <p className="notice notice-error" role="alert">{ error }</p> }
			<div aria-live="polite">
				{ image && <>
					<p><img src={ image } alt="Your generated static QR code" width="300" height="300" /></p>
					<a className="button-1" href={ image } download="qr-code.png">Download PNG</a>
				</> }
			</div>
		</>
	);
}

function init() {
	document.querySelectorAll< HTMLElement >( '[data-qr-code-generator]' ).forEach( ( container ) => {
		if ( container.dataset.initialized ) return;
		container.dataset.initialized = '1';
		createRoot( container ).render( <QRCodeGenerator /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
