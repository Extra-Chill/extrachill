import './style.scss';
import './editor.scss';

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

function Edit( { attributes, setAttributes } ) {
	const { perPage } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Leaderboard', 'extra-chill-community' ) }
				>
					<RangeControl
						label={ __(
							'Users per page',
							'extra-chill-community'
						) }
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
				<p>
					{ __(
						'Leaderboard will render on the frontend.',
						'extra-chill-community'
					) }
				</p>
			</div>
		</>
	);
}

registerBlockType( 'extrachill/leaderboard', {
	edit: Edit,
	save: () => null,
} );
