/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

export default function Edit() {
	return (
		<div { ...useBlockProps( { className: 'ec-shop-manager' } ) }>
			<p>
				{ __(
					'Shop Manager renders on the frontend.',
					'extrachill-shop'
				) }
			</p>
		</div>
	);
}
