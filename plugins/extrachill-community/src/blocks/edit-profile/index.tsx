import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { BlockIntro } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';

function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<BlockIntro description={ __( 'Edit Profile — renders on the frontend.', 'extra-chill-community' ) } />
		</div>
	);
}

registerBlockType( 'extrachill/edit-profile', {
	edit: Edit,
	save: () => null,
} );
