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
	Grid,
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
		<Grid minColumnWidth="100%">
			<Panel>
				<PanelHeader
					title="Embed your booking form"
					description="Add the websites where this form may appear, then copy the secure embed code into your website."
				/>
				<FieldGroup
					label="Allowed websites"
					htmlFor={ `${ idPrefix }venue-booking-websites` }
					help="Enter one exact HTTPS website address per line, such as https://venue.example. The first website is used in the code below."
				>
					<textarea
						id={ `${ idPrefix }venue-booking-websites` }
						rows="4"
						value={ websites.join( '\n' ) }
						onChange={ ( event ) =>
							setConfig( {
								...config,
								embed: {
									allowed_parent_origins: event.target.value
										.split( /\r?\n/ )
										.map( ( website ) => website.trim() )
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
								className="button-1"
								onClick={ copyEmbed }
							>
								Copy embed code
							</button>
							{ copied && (
								<span role="status">Embed code copied.</span>
							) }
						</ActionRow>
					</>
				) : (
					<p className="ec-venue-settings__save-note">
						Add an allowed website to generate its embed code.
					</p>
				) }
			</Panel>
			<IntakeTab
				config={ config }
				setConfig={ setConfig }
				idPrefix={ idPrefix }
			/>
			<details>
				<summary>
					<strong>Preview booking form</strong>
				</summary>
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
			</details>
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
					className="button-1"
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
		</Grid>
	);
}
