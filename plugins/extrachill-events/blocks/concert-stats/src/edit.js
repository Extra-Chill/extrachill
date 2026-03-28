/**
 * Concert Stats Block — Editor Component
 *
 * Shows a preview of the concert stats block in the Gutenberg editor.
 *
 * @package ExtraChillEvents
 */

import { useBlockProps } from '@wordpress/block-editor';
import { Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<Placeholder
				icon="tickets-alt"
				label={ __( 'Concert Stats', 'extrachill-events' ) }
				instructions={ __(
					'Displays the logged-in user\'s concert history, stats, and leaderboards. This block renders dynamically on the frontend.',
					'extrachill-events'
				) }
			/>
		</div>
	);
}
