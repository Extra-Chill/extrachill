import './style.scss';

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

registerBlockType(
	'extrachill/band-name-generator',
	{
		edit: ({ attributes, setAttributes }) => {
			const { title, buttonText } = attributes;
			const blockProps            = useBlockProps();

			return (
			< >
				< InspectorControls >
					< PanelBody title      = {__( 'Generator Settings', 'extrachill-content-blocks' )} >
						< TextControl
							label          = {__( 'Title', 'extrachill-content-blocks' )}
							value          = {title}
							onChange       = {(value) => setAttributes( { title: value } )}
						/ >
						< TextControl
							label          = {__( 'Button Text', 'extrachill-content-blocks' )}
							value          = {buttonText}
							onChange       = {(value) => setAttributes( { buttonText: value } )}
						/ >
					< / PanelBody >
				< / InspectorControls >
				< div {...blockProps} >
					< div className        = "extrachill-blocks-generator-preview" >
						< RichText
							tagName        = "h3"
							value          = {title}
							onChange       = {(value) => setAttributes( { title: value } )}
							placeholder    = {__( 'Enter title...', 'extrachill-content-blocks' )}
						/ >
						< button className = "extrachill-blocks-generator-button" disabled >
							{buttonText}
						< / button >
						< div className    = "extrachill-blocks-generator-result" >
							< em > {__( 'Generated name will appear here', 'extrachill-content-blocks' )} < / em >
						< / div >
					< / div >
				< / div >
			< / >
		);
		},
		save: () => null // Dynamic block rendered via render.php
	}
);