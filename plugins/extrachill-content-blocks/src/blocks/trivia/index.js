import './style.scss';
import './editor.scss';

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, TextareaControl, Button } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

registerBlockType(
	'extrachill/trivia',
	{
		edit: ({ attributes, setAttributes, clientId }) => {
			const { question, options, correctAnswer, answerJustification, blockId, resultMessages, scoreRanges } = attributes;
			const blockProps = useBlockProps();

			// Derive blockId from the block's clientId, which Gutenberg
			// guarantees is unique per instance and — critically — is
			// regenerated when a block is duplicated. The previous Date.now()
			// approach collided on copy/paste (the duplicate inherited the
			// saved blockId) and on same-millisecond inserts, which broke
			// the shared-score bookkeeping for multi-question quizzes. Keeping
			// blockId synced to clientId self-heals duplicates and existing
			// saved blocks on first edit. Run as an effect (not during render)
			// to avoid setState-in-render.
			useEffect( () => {
				if ( blockId !== clientId ) {
					setAttributes( { blockId: clientId } );
				}
			}, [ blockId, clientId, setAttributes ] );

			const addOption = () => {
				setAttributes( { options: [...options, ''] } );
			};

			const updateOption    = (index, value) => {
				const newOptions  = [...options];
				newOptions[index] = value;
				setAttributes( { options: newOptions } );
			};

			const removeOption   = (index) => {
				const newOptions = options.filter( (_, i) => i !== index );
				setAttributes( { options: newOptions } );
				if (correctAnswer === index) {
					setAttributes( { correctAnswer: 0 } );
				}
			};

			return (
			< >
				< InspectorControls >
					< PanelBody title                                  = {__( 'Trivia Settings', 'extrachill-content-blocks' )} >
						< TextControl
							label                                      = {__( 'Correct Answer Index (0-based)', 'extrachill-content-blocks' )}
							type                                       = "number"
							value                                      = {correctAnswer}
							onChange                                   = {(value) => setAttributes( { correctAnswer: parseInt( value ) } )}
						/ >
					< / PanelBody >
				< / InspectorControls >
				< div {...blockProps} >
					< div className                                    = "extrachill-blocks-trivia-editor" >
						< TextControl
							label                                      = {__( 'Question', 'extrachill-content-blocks' )}
							value                                      = {question}
							onChange                                   = {(value) => setAttributes( { question: value } )}
							placeholder                                = {__( 'Enter your trivia question...', 'extrachill-content-blocks' )}
						/ >
						< div className                                = "trivia-options" >
							< label > {__( 'Answer Options', 'extrachill-content-blocks' )} < / label >
							{options.map(
								(option, index) => (
								< div key                              = {index} className = "trivia-option-row" >
									< TextControl
										value                          = {option}
										onChange                       = {(value) => updateOption( index, value )}
										placeholder                    = {__( `Option ${index + 1}`, 'extrachill-content-blocks' )}
									/ >
									{options.length > 2 && (
										< Button isDestructive onClick = {() => removeOption( index )} >
											{__( 'Remove', 'extrachill-content-blocks' )}
										< / Button >
									)}
								< / div >
								)
							)}
							< Button isPrimary onClick = {addOption} >
								{__( 'Add Option', 'extrachill-content-blocks' )}
							< / Button >
						< / div >
						< TextareaControl
							label                      = {__( 'Answer Justification', 'extrachill-content-blocks' )}
							value                      = {answerJustification}
							onChange                   = {(value) => setAttributes( { answerJustification: value } )}
							placeholder                = {__( 'Explain why this is the correct answer...', 'extrachill-content-blocks' )}
						/ >
						< p className                  = "trivia-preview-note" >
							< em > {__( 'Preview: Frontend trivia interaction will appear here', 'extrachill-content-blocks' )} < / em >
						< / p >
					< / div >
				< / div >
			< / >
		);
		},
		save: () => null // Dynamic block rendered via render.php
	}
);
