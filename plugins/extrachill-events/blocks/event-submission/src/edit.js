import { __ } from '@wordpress/i18n';
import {
	InspectorControls,
	RichText,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	TextareaControl,
} from '@wordpress/components';

const sanitizeFlowId = (value = '') => value.replace(/[^0-9]/g, '');

export default function Edit({ attributes, setAttributes }) {
	const { headline, description, flowId, successMessage, buttonLabel, systemPrompt } = attributes;
	const blockProps = useBlockProps({ className: 'ec-event-submission-editor' });

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __('Submission Settings', 'datamachine-events') } initialOpen>
					<TextareaControl
						label={ __('Success Message', 'datamachine-events') }
						value={ successMessage }
						onChange={(value) => setAttributes({ successMessage: value })}
						help={ __('Shown after the REST submission succeeds.', 'datamachine-events') }
					/>
					<TextControl
						label={ __('Button Label', 'datamachine-events') }
						value={ buttonLabel }
						onChange={(value) => setAttributes({ buttonLabel: value })}
					/>
				</PanelBody>
				<PanelBody title={ __('AI Processing', 'datamachine-events') } initialOpen={ false }>
					<TextareaControl
						label={ __('System Prompt', 'datamachine-events') }
						value={ systemPrompt }
						onChange={(value) => setAttributes({ systemPrompt: value })}
						help={ __('Instructions for the AI when processing event submissions and flyer images.', 'datamachine-events') }
						rows={ 6 }
					/>
				</PanelBody>
				<PanelBody title={ __('Advanced', 'datamachine-events') } initialOpen={ false }>
					<TextControl
						label={ __('Flow ID (Optional)', 'datamachine-events') }
						value={ flowId }
						onChange={(value) => setAttributes({ flowId: sanitizeFlowId(value) })}
						help={ __('Override default processing with a specific Data Machine flow. Leave empty to use built-in workflow.', 'datamachine-events') }
					/>
				</PanelBody>
			</InspectorControls>

			<div {...blockProps}>

				<RichText
					tagName="h3"
					className="ec-event-submission-editor__headline"
					placeholder={ __('Add a headline…', 'datamachine-events') }
					value={ headline }
					onChange={(value) => setAttributes({ headline: value })}
					allowedFormats={[]}
				/>

				<RichText
					tagName="p"
					className="ec-event-submission-editor__description"
					placeholder={ __('Add supporting copy…', 'datamachine-events') }
					value={ description }
					onChange={(value) => setAttributes({ description: value })}
					allowedFormats={['core/bold', 'core/italic', 'core/link']}
				/>

				<div className="ec-event-submission-editor__preview">
					<div className="ec-event-submission-editor__field" />
					<div className="ec-event-submission-editor__field" />
					<div className="ec-event-submission-editor__field ec-event-submission-editor__field--half" />
					<div className="ec-event-submission-editor__field ec-event-submission-editor__field--half" />
					<div className="ec-event-submission-editor__field" />
					<div className="ec-event-submission-editor__field ec-event-submission-editor__field--textarea" />
					<button className="button-1 button-large" type="button">
						{buttonLabel || __('Send Submission', 'datamachine-events')}
					</button>
				</div>
			</div>
		</>
	);
}
