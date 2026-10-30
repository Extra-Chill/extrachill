/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

interface Attributes {
	userId: number;
}

function Edit( {
	attributes,
	setAttributes,
}: {
	attributes: Attributes;
	setAttributes: ( attrs: Partial< Attributes > ) => void;
} ) {
	const { userId } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __(
						'Contribution Heatmap',
						'extra-chill-community'
					) }
				>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'User ID', 'extra-chill-community' ) }
						help={ __(
							'0 resolves the displayed bbPress profile user.',
							'extra-chill-community'
						) }
						type="number"
						value={ String( userId ) }
						onChange={ ( value ) =>
							setAttributes( {
								userId: parseInt( value, 10 ) || 0,
							} )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<p>
					{ __(
						'Contribution Heatmap — renders on the frontend.',
						'extra-chill-community'
					) }
				</p>
			</div>
		</>
	);
}

registerBlockType( 'extrachill/contribution-heatmap', {
	edit: Edit,
	save: () => null,
} );
