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
	BOOKING_ATTACHMENT_PURPOSES,
	HOLD_TTL_MAX_MINUTES,
	normalizeKey,
	sameDocument,
	validateConfig,
} from './state';

const HOLD_DURATIONS = [
	[ 60, '1 hour' ],
	[ 240, '4 hours' ],
	[ 720, '12 hours' ],
	[ 1440, '1 day' ],
	[ 4320, '3 days' ],
	[ 10080, '7 days' ],
	[ 20160, '14 days' ],
];
const DEAL_TYPES = [
	[ 'custom', 'Custom terms' ],
	[ 'guarantee', 'Guarantee' ],
	[ 'door_split', 'Door split' ],
];
const CURRENCIES = [ 'USD', 'CAD', 'EUR', 'GBP' ];
const MARKETING_CHANNEL_LABELS = {
	social: 'Social media',
	newsletter: 'Newsletter',
};

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
					<details className="ec-venue-settings__advanced">
						<summary>Advanced space identifier</summary>
						<FieldGroup
							label="Space identifier"
							htmlFor={ `${ idPrefix }space-key-${ index }` }
							help="Keep this stable after bookings use the space."
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
					</details>
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
						className="button-link-delete button-small"
						onClick={ () =>
							// eslint-disable-next-line no-alert -- Removing a routable booking space requires confirmation.
							window.confirm(
								`Remove ${
									space.name ||
									space.key ||
									'this booking space'
								}? Existing bookings will keep their saved space identifier.`
							) &&
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

function AttachmentPolicyEditor( { policy, onChange, idPrefix } ) {
	const selected = new Map(
		policy.purposes.map( ( purpose ) => [ purpose.key, purpose ] )
	);
	const updatePurpose = ( key, patch ) => {
		const current = selected.get( key );
		onChange( {
			...policy,
			purposes: current
				? policy.purposes.map( ( purpose ) =>
						purpose.key === key ? { ...purpose, ...patch } : purpose
				  )
				: [
						...policy.purposes,
						{ key, requirement: 'invited', ...patch },
				  ],
		} );
	};

	return (
		<Panel>
			<PanelHeader
				title="Private booking files"
				description="Choose exactly which private documents artists may send. Operational readiness is approved separately."
			/>
			<label
				className="ec-venue-settings__toggle"
				htmlFor={ `${ idPrefix }booking-attachments-enabled` }
			>
				<input
					id={ `${ idPrefix }booking-attachments-enabled` }
					type="checkbox"
					checked={ policy.enabled }
					onChange={ ( event ) =>
						onChange( {
							...policy,
							enabled: event.target.checked,
							purposes: event.target.checked
								? policy.purposes
								: [],
						} )
					}
				/>{ ' ' }
				Allow private booking files when operations are ready
			</label>
			<p className="ec-venue-settings__help">
				Saving this policy does not enable uploads until private storage
				and governance checks also pass.
			</p>
			{ policy.enabled && (
				<fieldset className="ec-venue-settings__choices">
					<legend>Allowed purposes</legend>
					{ BOOKING_ATTACHMENT_PURPOSES.map( ( [ key, label ] ) => {
						const purpose = selected.get( key );
						return (
							<div
								className="ec-venue-settings__attachment-purpose"
								key={ key }
							>
								<label
									htmlFor={ `${ idPrefix }attachment-${ key }` }
								>
									<input
										id={ `${ idPrefix }attachment-${ key }` }
										type="checkbox"
										checked={ Boolean( purpose ) }
										onChange={ ( event ) =>
											event.target.checked
												? updatePurpose( key, {} )
												: onChange( {
														...policy,
														purposes:
															policy.purposes.filter(
																( item ) =>
																	item.key !==
																	key
															),
												  } )
										}
									/>{ ' ' }
									{ label }
								</label>
								{ purpose && (
									<label
										htmlFor={ `${ idPrefix }attachment-${ key }-requirement` }
									>
										<span className="screen-reader-text">
											{ label } requirement
										</span>
										<select
											id={ `${ idPrefix }attachment-${ key }-requirement` }
											value={ purpose.requirement }
											onChange={ ( event ) =>
												updatePurpose( key, {
													requirement:
														event.target.value,
												} )
											}
										>
											<option value="invited">
												Invited
											</option>
											<option value="required">
												Required
											</option>
										</select>
									</label>
								) }
							</div>
						);
					} ) }
				</fieldset>
			) }
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
					label="Default hold duration"
					htmlFor={ `${ idPrefix }venue-hold-ttl` }
					help="How long a temporary date hold remains active."
				>
					<select
						id={ `${ idPrefix }venue-hold-ttl` }
						value={ config.hold_ttl_minutes }
						onChange={ ( event ) =>
							setConfig( {
								...config,
								hold_ttl_minutes: Number( event.target.value ),
							} )
						}
					>
						{ ! HOLD_DURATIONS.some(
							( [ minutes ] ) =>
								minutes === config.hold_ttl_minutes
						) && (
							<option value={ config.hold_ttl_minutes }>
								{ config.hold_ttl_minutes } minutes (current)
							</option>
						) }
						{ HOLD_DURATIONS.filter(
							( [ minutes ] ) => minutes <= HOLD_TTL_MAX_MINUTES
						).map( ( [ minutes, label ] ) => (
							<option key={ minutes } value={ minutes }>
								{ label }
							</option>
						) ) }
					</select>
				</FieldGroup>
				<details className="ec-venue-settings__advanced">
					<summary>Advanced integrations</summary>
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
					<div className="ec-venue-settings__choices">
						<strong>Marketing automation</strong>
						{ config.marketing_channels.length ? (
							<ul>
								{ config.marketing_channels.map( ( key ) => (
									<li key={ key }>
										{ MARKETING_CHANNEL_LABELS[ key ] ||
											key.replaceAll( '_', ' ' ) }
									</li>
								) ) }
							</ul>
						) : (
							<p>No marketing automation is configured.</p>
						) }
						<small>
							Marketing automation is managed with its delivery
							policies.
						</small>
					</div>
				</details>
			</Panel>
			<SpacesEditor
				spaces={ config.spaces }
				onChange={ ( spaces ) => setConfig( { ...config, spaces } ) }
				idPrefix={ idPrefix }
			/>
			<AttachmentPolicyEditor
				policy={ config.attachment_policy }
				onChange={ ( attachmentPolicy ) =>
					setConfig( {
						...config,
						attachment_policy: attachmentPolicy,
					} )
				}
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
						<select
							id={ `${ idPrefix }venue-deal-type` }
							value={ deal.type }
							onChange={ ( event ) =>
								setDeal( {
									type: normalizeKey( event.target.value ),
								} )
							}
						>
							{ ! DEAL_TYPES.some(
								( [ value ] ) => value === deal.type
							) && (
								<option value={ deal.type }>
									{ deal.type }
								</option>
							) }
							{ DEAL_TYPES.map( ( [ value, label ] ) => (
								<option key={ value } value={ value }>
									{ label }
								</option>
							) ) }
						</select>
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
						<select
							id={ `${ idPrefix }venue-currency` }
							value={ deal.currency }
							onChange={ ( event ) =>
								setDeal( {
									currency: event.target.value.toUpperCase(),
								} )
							}
						>
							{ ! CURRENCIES.includes( deal.currency ) && (
								<option value={ deal.currency }>
									{ deal.currency }
								</option>
							) }
							{ CURRENCIES.map( ( currency ) => (
								<option key={ currency } value={ currency }>
									{ currency }
								</option>
							) ) }
						</select>
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
