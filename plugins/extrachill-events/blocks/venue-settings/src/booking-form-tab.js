/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	ActionRow,
	FieldGroup,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { BookingInquiry } from '../../venue-booking-inquiry/src/view';
import { bookingEmbedSnippet } from './booking-embed';
import { IntakeTab } from './intake-tab';
import { Status } from './status';
import { sameDocument, validateConfig } from './state';

const previewConfig = ( config, venueName, profile, idPrefix ) => ( {
	instanceId: `${ idPrefix }ec-booking-preview`,
	endpoint: '',
	availabilityEndpoint: '',
	restNonce: '',
	buttonLabel: 'Send booking inquiry',
	revision: 0,
	venue: {
		id: profile?.term_id || 0,
		name: venueName,
		logoUrl: profile?.logo_url || '',
	},
	spaces: config.spaces,
	fields: config.intake.fields,
	presentation: config.intake.presentation,
	consent: config.consent,
} );

export function BookingFormTab( {
	config,
	baseline,
	setConfig,
	onSave,
	saving,
	status,
	bookingUrl,
	venueName,
	profile,
	idPrefix = '',
} ) {
	const [ copied, setCopied ] = useState( false );
	const errors = validateConfig( config );
	const dirty = ! sameDocument( config, baseline );
	const websites = config.embed.allowed_parent_origins;
	const embedSnippet = websites.length
		? bookingEmbedSnippet( bookingUrl, venueName, websites[ 0 ] )
		: '';
	const copyEmbed = async () => {
		await navigator.clipboard.writeText( embedSnippet );
		setCopied( true );
	};

	return (
		<div className="ec-booking-form-editor">
			<div className="ec-booking-form-editor__controls">
				<Panel>
					<PanelHeader
						title="Embed your booking form"
						description="Authorize your venue website, then copy the secure embed code into the page where you want the form to appear."
					/>
					<FieldGroup
						label="Website where you'll embed this form"
						htmlFor={ `${ idPrefix }venue-booking-websites` }
						help="Enter the HTTPS address of your website, such as https://venue.example. You can place the form on any page of that website. Add another website on a new line if needed."
					>
						<textarea
							id={ `${ idPrefix }venue-booking-websites` }
							rows="4"
							value={ websites.join( '\n' ) }
							onChange={ ( event ) =>
								setConfig( {
									...config,
									embed: {
										allowed_parent_origins:
											event.target.value
												.split( /\r?\n/ )
												.map( ( website ) =>
													website.trim()
												)
												.filter( Boolean ),
									},
								} )
							}
						/>
					</FieldGroup>
					{ embedSnippet ? (
						<>
							<FieldGroup
								label="Embed code"
								htmlFor={ `${ idPrefix }venue-booking-embed-code` }
							>
								<textarea
									id={ `${ idPrefix }venue-booking-embed-code` }
									rows="8"
									readOnly
									value={ embedSnippet }
								/>
							</FieldGroup>
							<ActionRow>
								<button
									type="button"
									className="button-1 button-medium"
									onClick={ copyEmbed }
								>
									Copy embed code
								</button>
								{ copied && (
									<span role="status">
										Embed code copied.
									</span>
								) }
							</ActionRow>
						</>
					) : (
						<p className="ec-venue-settings__save-note">
							Add your website to generate its embed code.
						</p>
					) }
				</Panel>
				<IntakeTab
					config={ config }
					setConfig={ setConfig }
					idPrefix={ idPrefix }
				/>
				{ errors.length > 0 && (
					<InlineStatus tone="error">
						<strong>Resolve before saving:</strong>
						<ul>
							{ errors.map( ( error ) => (
								<li key={ error }>{ error }</li>
							) ) }
						</ul>
					</InlineStatus>
				) }
				<Status state={ status } />
				<ActionRow>
					<button
						type="button"
						className="button-1 button-medium"
						disabled={ ! dirty || saving || errors.length > 0 }
						onClick={ onSave }
					>
						{ saving ? 'Saving...' : 'Save booking form' }
					</button>
					{ dirty && (
						<span className="ec-venue-settings__dirty">
							Unsaved booking form changes
						</span>
					) }
				</ActionRow>
			</div>
			<aside
				className="ec-booking-form-editor__preview"
				aria-label="Booking form preview"
			>
				<PanelHeader
					title="Preview"
					description="Live preview of your public booking form."
				/>
				<div className="ec-venue-booking-inquiry">
					<BookingInquiry
						config={ previewConfig(
							config,
							venueName,
							profile,
							idPrefix
						) }
						wrapper={ null }
						preview
					/>
				</div>
			</aside>
		</div>
	);
}
