/**
 * WordPress dependencies
 */
import { createRoot, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

export interface QRGeneratorConfig {
	joinUrl?: string;
}

interface Preset {
	slug: string;
	label: string;
	placeholder: string;
}

// Presets only label the code and hint at a URL; any HTTP(S) link is allowed.
export const PRESETS: Preset[] = [
	{ slug: 'bandcamp', label: __( 'Bandcamp', 'extrachill-content-blocks' ), placeholder: 'https://yourband.bandcamp.com' },
	{ slug: 'tickets', label: __( 'Tickets', 'extrachill-content-blocks' ), placeholder: 'https://tickets.example.com/your-show' },
	{ slug: 'tip-jar', label: __( 'Tip jar', 'extrachill-content-blocks' ), placeholder: 'https://venmo.com/u/yourband' },
	{ slug: 'pre-save', label: __( 'Pre-save', 'extrachill-content-blocks' ), placeholder: 'https://presave.example.com/your-single' },
	{ slug: 'instagram', label: __( 'Instagram', 'extrachill-content-blocks' ), placeholder: 'https://instagram.com/yourband' },
];

export function QRCodeGenerator( { config = {} }: { config?: QRGeneratorConfig } ) {
	const [ url, setUrl ] = useState( '' );
	const [ preset, setPreset ] = useState< Preset | null >( null );
	const [ image, setImage ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const pending = useRef( false );

	async function generate( event: React.FormEvent ) {
		event.preventDefault();
		if ( pending.current ) {
			return;
		}
		setError( '' );
		setImage( '' );
		try {
			const parsed = new URL( url.trim() );
			if ( ! [ 'http:', 'https:' ].includes( parsed.protocol ) ) {
				throw new Error();
			}
		} catch {
			setError( __( 'Please enter a valid HTTP or HTTPS URL.', 'extrachill-content-blocks' ) );
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
				throw new Error( __( 'QR generation returned an invalid image.', 'extrachill-content-blocks' ) );
			}
			setImage( result.image_url );
		} catch ( failure ) {
			setError( ( failure as { message?: string } )?.message || __( 'Could not generate QR code. Please try again.', 'extrachill-content-blocks' ) );
		} finally {
			pending.current = false;
			setBusy( false );
		}
	}

	const filename = preset ? `${ preset.slug }-qr-code.png` : 'qr-code.png';

	return (
		<>
			<form className="ec-form-stack" onSubmit={ generate } aria-busy={ busy }>
				<label>
					{ __( 'Where should it point?', 'extrachill-content-blocks' ) }
					<input type="url" value={ url } maxLength={ 2048 } required
						placeholder={ preset ? preset.placeholder : 'https://yourband.bandcamp.com' } disabled={ busy }
						onChange={ ( event ) => {
							setUrl( event.target.value );
							setImage( '' );
						} } />
				</label>
				<button type="submit" className="button-1" disabled={ busy }>
					{ busy ? __( 'Generating…', 'extrachill-content-blocks' ) : __( 'Generate QR code', 'extrachill-content-blocks' ) }
				</button>
			</form>
			<p>
				{ __( 'Use it for:', 'extrachill-content-blocks' ) }{ ' ' }
				{ PRESETS.map( ( item ) => (
					<button key={ item.slug } type="button" data-preset={ item.slug }
						className={ preset?.slug === item.slug ? 'button-2 button-small' : 'button-3 button-small' }
						aria-pressed={ preset?.slug === item.slug }
						onClick={ () => setPreset( preset?.slug === item.slug ? null : item ) }>
						{ item.label }
					</button>
				) ) }
			</p>
			{ error && <p className="notice notice-error" role="alert">{ error }</p> }
			<div aria-live="polite">
				{ image && <>
					<p><img src={ image } alt={ preset
						/* translators: %s: QR code use, e.g. Bandcamp. */
						? sprintf( __( 'Your %s QR code', 'extrachill-content-blocks' ), preset.label )
						: __( 'Your QR code', 'extrachill-content-blocks' ) } width="300" height="300" /></p>
					<a className="button-1" href={ image } download={ filename }>{ __( 'Download PNG', 'extrachill-content-blocks' ) }</a>
					{ config.joinUrl && <p className="notice notice-info" data-qr-join>
						{ __( 'Tired of reprinting flyers every time a link changes? Put one QR on everything:', 'extrachill-content-blocks' ) }{ ' ' }
						<a href={ config.joinUrl }>{ __( 'make your free extrachill.link page', 'extrachill-content-blocks' ) }</a>
					</p> }
				</> }
			</div>
		</>
	);
}

function init() {
	document.querySelectorAll< HTMLElement >( '[data-qr-code-generator]' ).forEach( ( container ) => {
		if ( container.dataset.initialized ) {
			return;
		}
		container.dataset.initialized = '1';
		const config: QRGeneratorConfig = { joinUrl: container.dataset.joinUrl || '' };
		createRoot( container ).render( <QRCodeGenerator config={ config } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
