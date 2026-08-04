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
import {
	HOLD_TTL_MAX_MINUTES,
	normalizeKey,
	sameDocument,
	validateConfig,
} from './state';

function SpacesEditor( { spaces, onChange, idPrefix } ) {
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
						htmlFor={ `${ idPrefix }space-name-${ index }` }
						required
					>
						<input
							id={ `${ idPrefix }space-name-${ index }` }
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
						htmlFor={ `${ idPrefix }space-key-${ index }` }
						help="Stable machine-readable key used by booking records."
						required
					>
						<input
							id={ `${ idPrefix }space-key-${ index }` }
							value={ space.key }
							onChange={ ( event ) =>
								update( index, {
									key: normalizeKey( event.target.value ),
								} )
							}
						/>
					</FieldGroup>
					<label htmlFor={ `${ idPrefix }space-default-${ index }` }>
						<input
							id={ `${ idPrefix }space-default-${ index }` }
							type="radio"
							name={ `${ idPrefix }default-space` }
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
	idPrefix = '',
} ) {
	const errors = validateConfig( config );
	const dirty = ! sameDocument( config, baseline );
	const deal = config.default_deal;
	const setDeal = ( patch ) =>
		setConfig( { ...config, default_deal: { ...deal, ...patch } } );
	return (
		<Grid minColumnWidth="100%">
			<Panel>
				<PanelHeader
					title="Booking operation"
					description="Venue-wide defaults used by inquiry, holds, tickets, and marketing workflows."
				/>
				<label
					className="ec-venue-settings__toggle"
					htmlFor={ `${ idPrefix }venue-booking-enabled` }
				>
					<input
						id={ `${ idPrefix }venue-booking-enabled` }
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
					htmlFor={ `${ idPrefix }venue-hold-ttl` }
					help="Between 5 minutes and 14 days."
				>
					<input
						id={ `${ idPrefix }venue-hold-ttl` }
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
					htmlFor={ `${ idPrefix }venue-ticket-provider` }
					help="Account, venue, or provider reference used when ticket records are connected."
				>
					<input
						id={ `${ idPrefix }venue-ticket-provider` }
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
					htmlFor={ `${ idPrefix }venue-marketing-channels` }
					help="Comma-separated canonical channel keys, for example instagram, newsletter."
				>
					<input
						id={ `${ idPrefix }venue-marketing-channels` }
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
			<SpacesEditor
				spaces={ config.spaces }
				onChange={ ( spaces ) => setConfig( { ...config, spaces } ) }
				idPrefix={ idPrefix }
			/>
			<Panel>
				<PanelHeader
					title="Default deal"
					description="Starting terms only; each booking keeps its own negotiated deal."
				/>
				<Grid minColumnWidth="16rem" maxColumns={ 2 }>
					<FieldGroup
						label="Deal type"
						htmlFor={ `${ idPrefix }venue-deal-type` }
					>
						<input
							id={ `${ idPrefix }venue-deal-type` }
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
						htmlFor={ `${ idPrefix }venue-guarantee` }
						help="Amount in major currency units."
					>
						<input
							id={ `${ idPrefix }venue-guarantee` }
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
					<FieldGroup
						label="Revenue share (%)"
						htmlFor={ `${ idPrefix }venue-share` }
					>
						<input
							id={ `${ idPrefix }venue-share` }
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
						htmlFor={ `${ idPrefix }venue-share-basis` }
					>
						<select
							id={ `${ idPrefix }venue-share-basis` }
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
					<FieldGroup
						label="Currency"
						htmlFor={ `${ idPrefix }venue-currency` }
					>
						<input
							id={ `${ idPrefix }venue-currency` }
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
