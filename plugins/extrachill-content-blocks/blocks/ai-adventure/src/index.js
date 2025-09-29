import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText, InnerBlocks, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import metadata from '../block.json';

/**
 * Recursively sanitizes block data to be JSON-safe.
 * Removes circular references and unnecessary properties.
 *
 * @param {Array} blocks The array of block objects to clean.
 * @return {Array} A new array of sanitized block objects.
 */
const getCleanedInnerBlocks = ( blocks ) => {
	if ( ! blocks || ! Array.isArray( blocks ) ) {
		return [];
	}

	return blocks.map( ( block ) => {
		// Only keep the properties we need.
		const cleanedBlock = {
			name: block.name,
			attributes: block.attributes,
			innerBlocks: getCleanedInnerBlocks( block.innerBlocks ), // Recurse
		};
		return cleanedBlock;
	} );
};

registerBlockType( metadata.name, {
	edit: ( { attributes, setAttributes, clientId } ) => {
		const blockProps = useBlockProps({ className: 'extrachill-block-window' });
		const { title, adventurePrompt, gameMasterPersona } = attributes;

		// Watch for changes in inner blocks and save them to an attribute.
		const innerBlocks = useSelect(
			( select ) => select( 'core/block-editor' ).getBlocksByClientId( clientId )[0]?.innerBlocks,
			[ clientId ]
		);

		useEffect( () => {
			const newInnerBlocksJSON = JSON.stringify( getCleanedInnerBlocks( innerBlocks ) );

			if ( newInnerBlocksJSON !== attributes.innerBlocksJSON ) {
				setAttributes( { innerBlocksJSON: newInnerBlocksJSON } );
			}
		}, [ innerBlocks, attributes.innerBlocksJSON, setAttributes ] );

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'AI Settings', 'extrachill-blocks' ) }>
						<TextareaControl
							label={ __( 'Game Master Persona', 'extrachill-blocks' ) }
							value={ gameMasterPersona }
							onChange={ ( value ) => setAttributes( { gameMasterPersona: value } ) }
							help={ __( 'Define the personality and role of the AI storyteller.', 'extrachill-blocks' ) }
						/>
					</PanelBody>
				</InspectorControls>
				<div { ...blockProps }>
					<RichText
						tagName="h2"
						className="adventure-title"
						onChange={ ( value ) => setAttributes( { title: value } ) }
						value={ title }
						placeholder={ __( 'Enter Adventure Title', 'extrachill-blocks' ) }
					/>
					<RichText
						tagName="p"
						className="adventure-prompt"
						onChange={ ( value ) => setAttributes( { adventurePrompt: value } ) }
						value={ adventurePrompt }
						placeholder={ __( 'Adventure Description: Describe the overall plot in 2-3 sentences. This will be shown to the player before they start.', 'extrachill-blocks' ) }
					/>
					<InnerBlocks
						allowedBlocks={ [ 'extrachill-blocks/ai-adventure-path' ] }
					/>
				</div>
			</>
		);
	},
	save: ( { attributes } ) => {
		// We must save the InnerBlocks content for the render_callback to have access to it.
		return <InnerBlocks.Content />;
	},
} );
