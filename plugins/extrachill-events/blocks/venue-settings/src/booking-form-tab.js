/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
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
import {
	DEFAULT_BOOKING_APPEARANCE,
	bookingAppearanceStyle,
	bookingQrRequest,
} from './booking-appearance';
import { bookingButtonSnippet, bookingEmbedSnippet } from './booking-embed';
import { IntakeTab } from './intake-tab';
import { Status } from './status';
import { sameDocument, validateConfig } from './state';

const COLOR_FIELDS = [
	[ 'background_color', 'Background' ],
	[ 'surface_color', 'Surface / card' ],
	[ 'text_color', 'Text' ],
	[ 'accent_color', 'Accent / button' ],
	[ 'button_text_color', 'Button text' ],
	[ 'border_color', 'Border' ],
];

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
		address: [
			profile?.address,
			profile?.city,
			profile?.state,
			profile?.zip,
		]
			.filter( Boolean )
			.join( ', ' ),
	},
	spaces: config.spaces,
	fields: config.intake.fields,
	presentation: config.intake.presentation,
	appearance: config.appearance,
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
	const [ copied, setCopied ] = useState( '' );
	const [ previewDevice, setPreviewDevice ] = useState( 'desktop' );
	const [ qrState, setQrState ] = useState( { loading: false } );
	const errors = validateConfig( config );
	const dirty = ! sameDocument( config, baseline );
	const origins = config.embed.allowed_parent_origins;
	const buttonSnippet = bookingButtonSnippet( bookingUrl, venueName );
	const embedSnippet = origins.length
		? bookingEmbedSnippet( bookingUrl, venueName, origins[ 0 ] )
		: '';
	const setAppearance = ( patch ) =>
		setConfig( {
			...config,
			appearance: { ...config.appearance, ...patch },
		} );
	const copy = async ( label, value ) => {
		await navigator.clipboard.writeText( value );
		setCopied( label );
	};
	const generateQr = async ( size = 300 ) => {
		setQrState( { loading: true } );
		try {
			const result = await apiFetch(
				bookingQrRequest( bookingUrl, size )
			);
			setQrState( { loading: false, imageUrl: result.image_url } );
			return result.image_url;
		} catch ( error ) {
			setQrState( {
				loading: false,
				error: error?.message || 'QR code generation failed.',
			} );
			return '';
		}
	};
	const downloadQr = async () => {
		const imageUrl = await generateQr( 1000 );
		if ( imageUrl ) {
			const link = document.createElement( 'a' );
			link.href = imageUrl;
			link.download = `${ venueName
				.toLowerCase()
				.replace( /[^a-z0-9]+/g, '-' ) }-booking-qr.png`;
			link.click();
		}
	};

	return (
		<Grid minColumnWidth="100%">
			<IntakeTab
				config={ config }
				setConfig={ setConfig }
				idPrefix={ idPrefix }
			/>
			<Panel>
				<PanelHeader
					title="Appearance"
					description="Use Extra Chill defaults or a bounded custom palette shared by the hosted and embedded form."
				/>
				<FieldGroup
					label="Theme"
					htmlFor={ `${ idPrefix }booking-theme` }
				>
					<select
						id={ `${ idPrefix }booking-theme` }
						value={ config.appearance.mode }
						onChange={ ( event ) =>
							setAppearance( { mode: event.target.value } )
						}
					>
						<option value="default">Extra Chill default</option>
						<option value="custom">Custom</option>
					</select>
				</FieldGroup>
				{ config.appearance.mode === 'custom' && (
					<Grid minColumnWidth="12rem" maxColumns={ 3 }>
						{ COLOR_FIELDS.map( ( [ key, label ] ) => (
							<FieldGroup
								key={ key }
								label={ label }
								htmlFor={ `${ idPrefix }booking-${ key }` }
							>
								<input
									id={ `${ idPrefix }booking-${ key }` }
									type="color"
									value={ config.appearance[ key ] }
									onChange={ ( event ) =>
										setAppearance( {
											[ key ]: event.target.value,
										} )
									}
								/>
							</FieldGroup>
						) ) }
						<FieldGroup
							label="Button radius"
							htmlFor={ `${ idPrefix }booking-button-radius` }
							help={ `${ config.appearance.button_radius }px` }
						>
							<input
								id={ `${ idPrefix }booking-button-radius` }
								type="range"
								min="0"
								max="32"
								value={ config.appearance.button_radius }
								onChange={ ( event ) =>
									setAppearance( {
										button_radius: Number(
											event.target.value
										),
									} )
								}
							/>
						</FieldGroup>
					</Grid>
				) }
				<label
					className="ec-venue-settings__toggle"
					htmlFor={ `${ idPrefix }booking-show-logo` }
				>
					<input
						type="checkbox"
						checked={ config.appearance.show_logo }
						disabled={ ! profile?.logo_url }
						id={ `${ idPrefix }booking-show-logo` }
						onChange={ ( event ) =>
							setAppearance( { show_logo: event.target.checked } )
						}
					/>{ ' ' }
					Show canonical venue logo
				</label>
				{ ! profile?.logo_url && (
					<p className="ec-venue-settings__save-note">
						Logo display will become available when the canonical
						venue profile supports media.
					</p>
				) }
				<button
					type="button"
					className="button-2"
					onClick={ () =>
						setConfig( {
							...config,
							appearance: { ...DEFAULT_BOOKING_APPEARANCE },
						} )
					}
				>
					Reset to Extra Chill defaults
				</button>
			</Panel>
			<Panel className="ec-booking-form-preview-panel">
				<PanelHeader
					title="Live form preview"
					description="This is the production booking form component with submissions disabled."
					actions={
						<ActionRow>
							{ [ 'desktop', 'mobile' ].map( ( device ) => (
								<button
									key={ device }
									type="button"
									className={
										previewDevice === device
											? 'button-1'
											: 'button-2'
									}
									onClick={ () => setPreviewDevice( device ) }
								>
									{ device[ 0 ].toUpperCase() +
										device.slice( 1 ) }
								</button>
							) ) }
						</ActionRow>
					}
				/>
				<div
					className={ `ec-booking-form-preview is-${ previewDevice }` }
				>
					<div
						className="ec-venue-booking-inquiry"
						style={ bookingAppearanceStyle( config.appearance ) }
					>
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
			</Panel>
			<Panel>
				<PanelHeader
					title="Share and embed"
					description="Share the canonical booking anchor or authorize exact HTTPS websites to frame the same hosted form."
				/>
				<FieldGroup
					label="Canonical booking link"
					htmlFor={ `${ idPrefix }venue-booking-link` }
				>
					<input
						id={ `${ idPrefix }venue-booking-link` }
						readOnly
						value={ bookingUrl }
					/>
				</FieldGroup>
				<ActionRow>
					<button
						type="button"
						className="button-2"
						onClick={ () => copy( 'link', bookingUrl ) }
					>
						Copy link
					</button>
					<button
						type="button"
						className="button-2"
						onClick={ () => copy( 'button HTML', buttonSnippet ) }
					>
						Copy button HTML
					</button>
					<button
						type="button"
						className="button-2"
						disabled={ qrState.loading }
						onClick={ () => generateQr() }
					>
						{ qrState.loading ? 'Generating QR...' : 'Generate QR' }
					</button>
					{ copied && <span role="status">Copied { copied }.</span> }
				</ActionRow>
				{ qrState.error && (
					<InlineStatus tone="error">{ qrState.error }</InlineStatus>
				) }
				{ qrState.imageUrl && (
					<div className="ec-booking-form-qr">
						<img
							src={ qrState.imageUrl }
							alt={ `QR code for ${ venueName } booking form` }
						/>
						<button
							type="button"
							className="button-2"
							onClick={ downloadQr }
						>
							Download print QR
						</button>
					</div>
				) }
				<FieldGroup
					label="Allowed parent origins"
					htmlFor={ `${ idPrefix }venue-booking-origins` }
					help="One exact HTTPS origin per line. Paths, wildcards, ports, credentials, localhost, and IP addresses are rejected. The first origin is used for the snippet."
				>
					<textarea
						id={ `${ idPrefix }venue-booking-origins` }
						rows="4"
						value={ origins.join( '\n' ) }
						onChange={ ( event ) =>
							setConfig( {
								...config,
								embed: {
									allowed_parent_origins: event.target.value
										.split( /\r?\n/ )
										.map( ( origin ) => origin.trim() )
										.filter( Boolean ),
								},
							} )
						}
					/>
				</FieldGroup>
				{ embedSnippet && (
					<>
						<FieldGroup
							label="Responsive iframe snippet"
							htmlFor={ `${ idPrefix }venue-booking-embed-code` }
						>
							<textarea
								id={ `${ idPrefix }venue-booking-embed-code` }
								rows="8"
								readOnly
								value={ embedSnippet }
							/>
						</FieldGroup>
						<button
							type="button"
							className="button-2"
							onClick={ () =>
								copy( 'iframe snippet', embedSnippet )
							}
						>
							Copy iframe snippet
						</button>
					</>
				) }
			</Panel>
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
