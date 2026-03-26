import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIntro } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';


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
				<BlockIntro description={ __( 'Leaderboard — renders on the frontend.', 'extra-chill-community' ) } />
			</div>
		</>
	);
}

registerBlockType( 'extrachill/leaderboard', {
	edit: Edit,
	save: () => null,
} );
