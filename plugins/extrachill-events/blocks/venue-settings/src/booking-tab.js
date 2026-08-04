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
import { Status } from './status';
import { bookingButtonSnippet, bookingEmbedSnippet } from './booking-embed';
import {
	HOLD_TTL_MAX_MINUTES,
	normalizeKey,
	sameDocument,
	validateConfig,
} from './state';

function SpacesEditor( { spaces, onChange } ) {
	const update = ( index, patch ) =>
		onChange(
			spaces.map( ( space, current ) => {
				if ( current !== index ) {
					return patch.is_default
						? { ...space, is_default: false }
						: space;
				}
				return { ...space, ...patch };
			} )
		);
	return (
		<Panel>
			<PanelHeader
				title="Spaces"
				description="Rooms, stages, or other independently held booking spaces."
			/>
			{ spaces.length === 0 && (
				<p>No spaces configured. Add one to start routing holds.</p>
			) }
			{ spaces.map( ( space, index ) => (
				<fieldset
					className="ec-venue-settings__repeater"
					key={ `${ space.key }-${ index }` }
				>
					<legend>Space { index + 1 }</legend>
					<FieldGroup
						label="Name"
						htmlFor={ `space-name-${ index }` }
						required
					>
						<input
							id={ `space-name-${ index }` }
							value={ space.name }
							onChange={ ( event ) =>
								update( index, {
									name: event.target.value,
									key:
										space.key ||
										normalizeKey( event.target.value ),
								} )
							}
						/>
					</FieldGroup>
					<FieldGroup
						label="Key"
						htmlFor={ `space-key-${ index }` }
						help="Stable machine-readable key used by booking records."
						required
					>
						<input
							id={ `space-key-${ index }` }
							value={ space.key }
							onChange={ ( event ) =>
								update( index, {
									key: normalizeKey( event.target.value ),
								} )
							}
						/>
					</FieldGroup>
					<label htmlFor={ `space-default-${ index }` }>
						<input
							id={ `space-default-${ index }` }
							type="radio"
							name="default-space"
							checked={ space.is_default }
							onChange={ () =>
								update( index, { is_default: true } )
							}
						/>{ ' ' }
						Default space
					</label>
					<button
						type="button"
						className="button-link-delete"
						onClick={ () =>
							onChange(
								spaces.filter(
									( _, current ) => current !== index
								)
							)
						}
					>
						Remove space
					</button>
				</fieldset>
			) ) }
			<button
				type="button"
				className="button-2"
				onClick={ () =>
					onChange( [
						...spaces,
						{ key: '', name: '', is_default: spaces.length === 0 },
					] )
				}
			>
				Add space
			</button>
		</Panel>
	);
}

export function BookingTab( {
	config,
	baseline,
	setConfig,
	onSave,
	saving,
	status,
	bookingUrl,
	venueName,
	children,
} ) {
	const [ copied, setCopied ] = useState( '' );
	const errors = validateConfig( config );
	const dirty = ! sameDocument( config, baseline );
	const deal = config.default_deal;
	const setDeal = ( patch ) =>
		setConfig( { ...config, default_deal: { ...deal, ...patch } } );
	const origins = config.embed.allowed_parent_origins;
	const buttonSnippet = bookingButtonSnippet( bookingUrl, venueName );
	const embedSnippet = origins.length
		? bookingEmbedSnippet( bookingUrl, venueName, origins[ 0 ] )
		: '';
	const copy = async ( label, value ) => {
		await navigator.clipboard.writeText( value );
		setCopied( label );
	};
	return (
		<Grid minColumnWidth="100%">
			<Panel>
				<PanelHeader
					title="Booking operation"
					description="Venue-wide defaults used by inquiry, holds, tickets, and marketing workflows."
				/>
				<label
					className="ec-venue-settings__toggle"
					htmlFor="venue-booking-enabled"
				>
					<input
						id="venue-booking-enabled"
						type="checkbox"
						checked={ config.enabled }
						onChange={ ( event ) =>
							setConfig( {
								...config,
								enabled: event.target.checked,
							} )
						}
					/>{ ' ' }
					Accept booking inquiries
				</label>
				<FieldGroup
					label="Default hold duration (minutes)"
					htmlFor="venue-hold-ttl"
					help="Between 5 minutes and 14 days."
				>
					<input
						id="venue-hold-ttl"
						type="number"
						min="5"
						max={ HOLD_TTL_MAX_MINUTES }
						value={ config.hold_ttl_minutes }
						onChange={ ( event ) =>
							setConfig( {
								...config,
								hold_ttl_minutes: Number( event.target.value ),
							} )
						}
					/>
				</FieldGroup>
				<FieldGroup
					label="Ticket provider reference"
					htmlFor="venue-ticket-provider"
					help="Account, venue, or provider reference used when ticket records are connected."
				>
					<input
						id="venue-ticket-provider"
						value={ config.ticket_provider_reference || '' }
						onChange={ ( event ) =>
							setConfig( {
								...config,
								ticket_provider_reference:
									event.target.value || null,
							} )
						}
					/>
				</FieldGroup>
				<FieldGroup
					label="Default marketing channels"
					htmlFor="venue-marketing-channels"
					help="Comma-separated canonical channel keys, for example instagram, newsletter."
				>
					<input
						id="venue-marketing-channels"
						value={ config.marketing_channels.join( ', ' ) }
						onChange={ ( event ) =>
							setConfig( {
								...config,
								marketing_channels: [
									...new Set(
										event.target.value
											.split( ',' )
											.map( normalizeKey )
											.filter( Boolean )
									),
								],
							} )
						}
					/>
				</FieldGroup>
			</Panel>
			<Panel>
				<PanelHeader
					title="Booking link and embed"
					description="Share the canonical booking page or authorize one exact HTTPS website to frame the hosted form."
				/>
				<FieldGroup
					label="Canonical booking link"
					htmlFor="venue-booking-link"
				>
					<input
						id="venue-booking-link"
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
						onClick={ () => copy( 'button', buttonSnippet ) }
					>
						Copy button HTML
					</button>
					{ copied && <span role="status">Copied { copied }.</span> }
				</ActionRow>
				<FieldGroup
					label="Allowed parent origins"
					htmlFor="venue-booking-origins"
					help="One exact HTTPS origin per line, such as https://venue.example. Paths, wildcards, ports, credentials, localhost, and IP addresses are rejected. The first origin is used for the generated snippet."
				>
					<textarea
						id="venue-booking-origins"
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
							htmlFor="venue-booking-embed-code"
						>
							<textarea
								id="venue-booking-embed-code"
								rows="8"
								readOnly
								value={ embedSnippet }
							/>
						</FieldGroup>
						<button
							type="button"
							className="button-2"
							onClick={ () =>
								copy( 'embed snippet', embedSnippet )
							}
						>
							Copy iframe snippet
						</button>
					</>
				) }
			</Panel>
			<SpacesEditor
				spaces={ config.spaces }
				onChange={ ( spaces ) => setConfig( { ...config, spaces } ) }
			/>
			<Panel>
				<PanelHeader
					title="Default deal"
					description="Starting terms only; each booking keeps its own negotiated deal."
				/>
				<Grid minColumnWidth="16rem" maxColumns={ 2 }>
					<FieldGroup label="Deal type" htmlFor="venue-deal-type">
						<input
							id="venue-deal-type"
							value={ deal.type }
							onChange={ ( event ) =>
								setDeal( {
									type: normalizeKey( event.target.value ),
								} )
							}
						/>
					</FieldGroup>
					<FieldGroup
						label="Guarantee"
						htmlFor="venue-guarantee"
						help="Amount in major currency units."
					>
						<input
							id="venue-guarantee"
							type="number"
							min="0"
							step="0.01"
							value={ deal.guarantee_cents / 100 }
							onChange={ ( event ) =>
								setDeal( {
									guarantee_cents: Math.round(
										Number( event.target.value ) * 100
									),
								} )
							}
						/>
					</FieldGroup>
					<FieldGroup label="Revenue share (%)" htmlFor="venue-share">
						<input
							id="venue-share"
							type="number"
							min="0"
							max="100"
							step="0.01"
							value={ deal.revenue_share_basis_points / 100 }
							onChange={ ( event ) =>
								setDeal( {
									revenue_share_basis_points: Math.round(
										Number( event.target.value ) * 100
									),
								} )
							}
						/>
					</FieldGroup>
					<FieldGroup
						label="Revenue basis"
						htmlFor="venue-share-basis"
					>
						<select
							id="venue-share-basis"
							value={ deal.revenue_share_basis }
							onChange={ ( event ) =>
								setDeal( {
									revenue_share_basis: event.target.value,
								} )
							}
						>
							<option value="gross_ticket_sales">
								Gross ticket sales
							</option>
							<option value="net_ticket_sales">
								Net ticket sales
							</option>
							<option value="door_receipts">Door receipts</option>
						</select>
					</FieldGroup>
					<FieldGroup label="Currency" htmlFor="venue-currency">
						<input
							id="venue-currency"
							maxLength="3"
							value={ deal.currency }
							onChange={ ( event ) =>
								setDeal( {
									currency: event.target.value.toUpperCase(),
								} )
							}
						/>
					</FieldGroup>
				</Grid>
			</Panel>
			{ children }
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
					{ saving ? 'Saving...' : 'Save settings' }
				</button>
				{ dirty && (
					<span className="ec-venue-settings__dirty">
						Unsaved settings changes
					</span>
				) }
			</ActionRow>
		</Grid>
	);
}
