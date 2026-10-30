/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import { PanelBody, TextControl, TextareaControl } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
	const { headline, description, successMessage, buttonLabel, systemPrompt } =
		attributes;
	const blockProps = useBlockProps( {
		className: 'ec-event-submission-editor',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Submission Settings', 'extrachill-events' ) }
					initialOpen
				>
					<TextareaControl
						label={ __( 'Success Message', 'extrachill-events' ) }
						value={ successMessage }
						onChange={ ( value ) =>
							setAttributes( { successMessage: value } )
						}
						help={ __(
							'Shown after the REST submission succeeds.',
							'extrachill-events'
						) }
					/>
					<TextControl
						label={ __( 'Button Label', 'extrachill-events' ) }
						value={ buttonLabel }
						onChange={ ( value ) =>
							setAttributes( { buttonLabel: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'AI Processing', 'extrachill-events' ) }
					initialOpen={ false }
				>
					<TextareaControl
						label={ __( 'System Prompt', 'extrachill-events' ) }
						value={ systemPrompt }
						onChange={ ( value ) =>
							setAttributes( { systemPrompt: value } )
						}
						help={ __(
							'Instructions for the AI when processing event submissions and flyer images.',
							'extrachill-events'
						) }
						rows={ 6 }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<RichText
					tagName="h3"
					className="ec-event-submission-editor__headline"
					placeholder={ __( 'Add a headline…', 'extrachill-events' ) }
					value={ headline }
					onChange={ ( value ) =>
						setAttributes( { headline: value } )
					}
					allowedFormats={ [] }
				/>

				<RichText
					tagName="p"
					className="ec-event-submission-editor__description"
					placeholder={ __(
						'Add supporting copy…',
						'extrachill-events'
					) }
					value={ description }
					onChange={ ( value ) =>
						setAttributes( { description: value } )
					}
					allowedFormats={ [
						'core/bold',
						'core/italic',
						'core/link',
					] }
				/>

				<div className="ec-event-submission-editor__preview">
					<div className="ec-event-submission-editor__field" />
					<div className="ec-event-submission-editor__field" />
					<div className="ec-event-submission-editor__field ec-event-submission-editor__field--half" />
					<div className="ec-event-submission-editor__field ec-event-submission-editor__field--half" />
					<div className="ec-event-submission-editor__field" />
					<div className="ec-event-submission-editor__field ec-event-submission-editor__field--textarea" />
					<button className="button-1 button-large" type="button">
						{ buttonLabel ||
							__( 'Send Submission', 'extrachill-events' ) }
					</button>
				</div>
			</div>
		</>
	);
}
