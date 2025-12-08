/**
 * Artist Profile Manager - Edit
 * Placeholder view in the block editor. Actual UI renders on the frontend
 * for logged-in users with artist permissions.
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps, Notice } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';
import metadata from './block.json';

export default function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<Placeholder
				icon="id-alt"
				label={ __( 'Artist Profile Manager', 'extrachill-artist-platform' ) }
				instructions={ __(
					'This block renders the artist profile manager on the frontend for authorized users.',
					'extrachill-artist-platform'
				) }
			>
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Add this block to the Manage Artist page. The full interface appears on the frontend.',
						'extrachill-artist-platform'
					) }
				</Notice>
			</Placeholder>
		</div>
	);
}
