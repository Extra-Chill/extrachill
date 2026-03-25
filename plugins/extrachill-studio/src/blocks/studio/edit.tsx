import { __ } from '@wordpress/i18n';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextareaControl } from '@wordpress/components';

import { getStudioTabs } from './app/tabs';

interface StudioAttributes {
	headline: string;
	description: string;
	deniedMessage: string;
}

interface EditProps {
	attributes: StudioAttributes;
	setAttributes: ( attrs: Partial< StudioAttributes > ) => void;
}

export default function Edit( { attributes, setAttributes }: EditProps ) {
	const { headline, description, deniedMessage } = attributes;
	const blockProps = useBlockProps( { className: 'ec-studio-editor' } );
	const tabs = getStudioTabs();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Studio Settings', 'extrachill-studio' ) } initialOpen>
					<TextareaControl
						label={ __( 'Denied Message', 'extrachill-studio' ) }
						value={ deniedMessage }
						onChange={ ( value: string ) => setAttributes( { deniedMessage: value } ) }
						help={ __( 'Displayed when a logged-in user does not have team member access.', 'extrachill-studio' ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<RichText
					tagName="h3"
					className="ec-studio-editor__headline"
					placeholder={ __( 'Add a Studio headline…', 'extrachill-studio' ) }
					value={ headline }
					onChange={ ( value: string ) => setAttributes( { headline: value } ) }
					allowedFormats={ [] }
				/>

				<RichText
					tagName="p"
					className="ec-studio-editor__description"
					placeholder={ __( 'Describe what the Studio is for…', 'extrachill-studio' ) }
					value={ description }
					onChange={ ( value: string ) => setAttributes( { description: value } ) }
					allowedFormats={ [ 'core/bold', 'core/italic', 'core/link' ] }
				/>

				<div className="shared-tabs-component ec-studio-editor__preview">
					<div className="shared-tabs-buttons-container">
						{ tabs.map( ( tab, index ) => (
							<div key={ tab.pane } className="shared-tab-item">
								<button type="button" className={ `shared-tab-button${ index === 0 ? ' active' : '' }` }>
									{ tab.label }
									<span className={ `shared-tab-arrow${ index === 0 ? ' open' : '' }` } />
								</button>
								{ index === 0 ? (
									<div className="shared-tab-pane" style={ { display: 'block' } }>
										<p>{ tab.preview }</p>
									</div>
								) : null }
							</div>
						) ) }
					</div>
				</div>
			</div>
		</>
	);
}
