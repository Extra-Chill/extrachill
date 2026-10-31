/**
 * Link Page Editor - Edit Component
 *
 * Main editor component that orchestrates the link page management interface.
 */

import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder, Spinner } from '@wordpress/components';

export default function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<Placeholder
				icon="admin-links"
				label={ __( 'Link Page Editor', 'extrachill-artist-platform' ) }
				instructions={ __(
					'This block displays the link page editor on the frontend.',
					'extrachill-artist-platform'
				) }
			>
				<p>
					{ __(
						'The editor interface will render for logged-in artists on the frontend.',
						'extrachill-artist-platform'
					) }
				</p>
			</Placeholder>
		</div>
	);
}
