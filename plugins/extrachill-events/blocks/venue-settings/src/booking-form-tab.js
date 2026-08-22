/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';

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
import { bookingEmbedSnippet, bookingOriginFromWebsite } from './booking-embed';
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
	onInitializeConfig,
	onSave,
	saving,
	status,
	bookingUrl,
	venueName,
	profile,
	idPrefix = '',
} ) {
	const [ copied, setCopied ] = useState( false );
	const [ previewOpen, setPreviewOpen ] = useState( true );
	const initializedWebsite = useRef( false );
	const errors = validateConfig( config );
	const dirty = ! sameDocument( config, baseline );
	const websites = config.embed.allowed_parent_origins;
	const embedSnippet = websites.length
		? bookingEmbedSnippet( bookingUrl, venueName, websites[ 0 ] )
		: '';
	useEffect( () => {
		if ( initializedWebsite.current || websites.length ) {
			initializedWebsite.current = true;
			return;
		}
		const origin = bookingOriginFromWebsite( profile?.website || '' );
		if ( ! origin ) {
			return;
		}
		initializedWebsite.current = true;
		onInitializeConfig( {
			...config,
			embed: { allowed_parent_origins: [ origin ] },
		} );
	}, [ config, onInitializeConfig, profile?.website, websites.length ] );
	useEffect( () => {
		const mobilePreview = window.matchMedia( '(max-width: 1200px)' );
		const syncPreview = () => setPreviewOpen( ! mobilePreview.matches );
		syncPreview();
		mobilePreview.addEventListener( 'change', syncPreview );
		return () => mobilePreview.removeEventListener( 'change', syncPreview );
	}, [] );
	const copyEmbed = async () => {
		await navigator.clipboard.writeText( embedSnippet );
		setCopied( true );
	};

	return (
		<div className="ec-booking-form-editor">
			<div className="ec-booking-form-editor__controls">
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
				<Panel>
					<PanelHeader
						title="Put this booking form on your website"
						description="Add your venue website address so the form will only work there. Entering the address does not publish the form."
					/>
					<FieldGroup
						label="Your venue website address"
						htmlFor={ `${ idPrefix }venue-booking-websites` }
						help="Enter the main HTTPS address, such as https://venue.example. This tells Extra Chill which website may display your form. Add another website on a new line if needed."
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
							<p>
								Copy this code, then paste it into the page
								where you want the form or send it to the person
								who manages your website.
							</p>
							<ActionRow>
								<button
									type="button"
									className="button-1 button-medium"
									onClick={ copyEmbed }
								>
									Copy website code
								</button>
								{ copied && (
									<span role="status">
										Code copied. It still needs to be added
										to your website.
									</span>
								) }
							</ActionRow>
							<details className="ec-booking-embed-advanced">
								<summary>View advanced website code</summary>
								<FieldGroup
									label="Website code"
									htmlFor={ `${ idPrefix }venue-booking-embed-code` }
								>
									<textarea
										id={ `${ idPrefix }venue-booking-embed-code` }
										rows="8"
										readOnly
										value={ embedSnippet }
									/>
								</FieldGroup>
							</details>
						</>
					) : (
						<p className="ec-venue-settings__save-note">
							Add your website address to prepare the code you
							will need.
						</p>
					) }
				</Panel>
			</div>
			<aside
				className="ec-booking-form-editor__preview"
				aria-label="Booking form preview"
			>
				<button
					type="button"
					className="ec-booking-form-editor__preview-toggle button-2 button-medium button-block"
					aria-expanded={ previewOpen }
					aria-controls={ `${ idPrefix }booking-form-preview` }
					onClick={ () => setPreviewOpen( ( open ) => ! open ) }
				>
					{ previewOpen
						? 'Hide booking form preview'
						: 'Preview booking form' }
				</button>
				<div
					id={ `${ idPrefix }booking-form-preview` }
					className="ec-booking-form-editor__preview-content"
					hidden={ ! previewOpen }
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
				</div>
			</aside>
		</div>
	);
}
