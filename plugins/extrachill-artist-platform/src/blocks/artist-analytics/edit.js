/**
 * Artist Analytics Block - Editor Component
 *
 * Simple placeholder for the Gutenberg editor.
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

export default function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<div className="ec-aa-editor-placeholder">
				<span className="dashicons dashicons-chart-line"></span>
				<p>{ __( 'Artist Analytics', 'extrachill-artist-platform' ) }</p>
				<small>{ __( 'Analytics dashboard will display on the frontend.', 'extrachill-artist-platform' ) }</small>
			</div>
		</div>
	);
}
