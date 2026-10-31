/**
 * Artist Creator - Edit
 * Placeholder view in the block editor. Actual UI renders on the frontend
 * for users with artist creation permissions.
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder, Notice } from '@wordpress/components';

export default function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<Placeholder
				icon="plus-alt"
				label={ __( 'Artist Creator', 'extrachill-artist-platform' ) }
				instructions={ __(
					'This block renders the artist creation form on the frontend for authorized users.',
					'extrachill-artist-platform'
				) }
			>
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Add this block to the Create Artist page. The form appears on the frontend.',
						'extrachill-artist-platform'
					) }
				</Notice>
			</Placeholder>
		</div>
	);
}
