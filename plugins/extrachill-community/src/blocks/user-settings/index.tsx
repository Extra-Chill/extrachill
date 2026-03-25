import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import { cssVar, spacing, colors } from '@extrachill/tokens';

function Edit() {
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<div style={ {
				padding: cssVar( spacing.spacingMd ),
				color: cssVar( colors.mutedText ),
				borderRadius: '4px',
				border: `1px dashed ${ cssVar( colors.borderColor ) }`,
				textAlign: 'center',
			} }>
				{ __( 'User Settings — renders on the frontend.', 'extra-chill-community' ) }
			</div>
		</div>
	);
}

registerBlockType( 'extrachill/user-settings', {
	edit: Edit,
	save: () => null,
} );
