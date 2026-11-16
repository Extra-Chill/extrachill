import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

registerBlockType( 'extrachill/qr-code-generator', {
	edit: () => (
		<div { ...useBlockProps() }>
			<h3>{ __( 'QR Code Generator', 'extrachill-content-blocks' ) }</h3>
			<p>{ __( 'Free QR codes for your merch table, gig flyers, the tip jar and the screen between sets.', 'extrachill-content-blocks' ) }</p>
			<button className="button-1" disabled>{ __( 'Generate', 'extrachill-content-blocks' ) }</button>
		</div>
	),
	save: () => null,
} );
