import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { cssVar, spacing, colors } from '@extrachill/tokens';


interface Attributes {
	perPage: number;
}

function Edit( { attributes, setAttributes }: {
	attributes: Attributes;
	setAttributes: ( attrs: Partial< Attributes > ) => void;
} ) {
	const { perPage } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Leaderboard', 'extra-chill-community' ) }
				>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Users per page', 'extra-chill-community' ) }
						value={ perPage }
						onChange={ ( value ) =>
							setAttributes( { perPage: value } )
						}
						min={ 5 }
						max={ 100 }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div style={ {
					padding: cssVar( spacing.spacingMd ),
					color: cssVar( colors.mutedText ),
					borderRadius: '4px',
					border: `1px dashed ${ cssVar( colors.borderColor ) }`,
					textAlign: 'center',
				} }>
					{ __( 'Leaderboard — renders on the frontend.', 'extra-chill-community' ) }
				</div>
			</div>
		</>
	);
}

registerBlockType( 'extrachill/leaderboard', {
	edit: Edit,
	save: () => null,
} );
