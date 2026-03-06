import { __ } from '@wordpress/i18n';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextareaControl } from '@wordpress/components';

export default function Edit( { attributes, setAttributes } ) {
	const { headline, description, deniedMessage } = attributes;
	const blockProps = useBlockProps( { className: 'ec-studio-editor' } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Studio Settings', 'extrachill-studio' ) } initialOpen>
					<TextareaControl
						label={ __( 'Denied Message', 'extrachill-studio' ) }
						value={ deniedMessage }
						onChange={ ( value ) => setAttributes( { deniedMessage: value } ) }
						help={ __( 'Shown to logged-in users who are not team members.', 'extrachill-studio' ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<RichText
					tagName="h3"
					className="ec-studio-editor__headline"
					placeholder={ __( 'Add a Studio headline…', 'extrachill-studio' ) }
					value={ headline }
					onChange={ ( value ) => setAttributes( { headline: value } ) }
					allowedFormats={ [] }
				/>

				<RichText
					tagName="p"
					className="ec-studio-editor__description"
					placeholder={ __( 'Describe what the Studio is for…', 'extrachill-studio' ) }
					value={ description }
					onChange={ ( value ) => setAttributes( { description: value } ) }
					allowedFormats={ [ 'core/bold', 'core/italic', 'core/link' ] }
				/>

				<div className="shared-tabs-component ec-studio-editor__preview">
					<div className="shared-tabs-buttons-container">
						<div className="shared-tab-item">
							<button type="button" className="shared-tab-button active">
								{ __( 'Overview', 'extrachill-studio' ) }
								<span className="shared-tab-arrow open" />
							</button>
							<div className="shared-tab-pane" style={ { display: 'block' } }>
								<p>{ __( 'Team-gated Studio shell with tabbed workspace.', 'extrachill-studio' ) }</p>
							</div>
						</div>

						<div className="shared-tab-item">
							<button type="button" className="shared-tab-button">
								{ __( 'QR Codes', 'extrachill-studio' ) }
								<span className="shared-tab-arrow" />
							</button>
						</div>

						<div className="shared-tab-item">
							<button type="button" className="shared-tab-button">
								{ __( 'Publishing', 'extrachill-studio' ) }
								<span className="shared-tab-arrow" />
							</button>
						</div>
					</div>
				</div>
			</div>
		</>
	);
}
