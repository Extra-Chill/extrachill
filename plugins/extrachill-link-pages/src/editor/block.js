import { useBlockProps } from '@wordpress/block-editor';
import { registerBlockType } from '@wordpress/blocks';
import { Placeholder } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './editor.scss';

function Edit() {
	return (
		<div { ...useBlockProps() }>
			<Placeholder
				icon="admin-links"
				label={ __( 'Link Page Editor', 'extrachill-link-pages' ) }
				instructions={ __(
					'This block displays the Link Page editor on the frontend for an authorized identity.',
					'extrachill-link-pages'
				) }
			/>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
