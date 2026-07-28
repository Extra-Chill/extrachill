/**
 * WordPress dependencies
 */
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	ActionRow,
	Badge,
	BlockShell,
	BlockShellHeader,
	BlockShellInner,
	FieldGroup,
	InlineStatus,
	Panel,
	PanelHeader,
	ResponsiveTabs,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { errorDetails, runAbility } from './api';
import PublicBookingDetails from './intake-public';
import { BookingConsole } from './booking-console';
import {
	editableConfig,
	normalizeKey,
	profileChanges,
	sameDocument,
	validateConfig,
} from './state';

const PROFILE_FIELDS = [
	[ 'name', 'Venue name', true ],
	[ 'description', 'Description' ],
	[ 'address', 'Street address' ],
	[ 'city', 'City' ],
	[ 'state', 'State / region' ],
	[ 'zip', 'Postal code' ],
	[ 'country', 'Country' ],
	[ 'phone', 'Phone' ],
	[ 'website', 'Website' ],
	[ 'capacity', 'Capacity' ],
];

const INTAKE_TYPES = [
	'text',
	'textarea',
	'email',
	'phone',
	'number',
	'select',
	'checkbox',
	'url',
];

export const venueSubscriberCsv = ( subscribers ) =>
	[
		[ 'user_id', 'email' ],
		...subscribers.map( ( subscriber ) => [
			subscriber.user_id,
			subscriber.email,
		] ),
	]
		.map( ( row ) =>
			row
				.map(
					( value ) =>
						`"${ String( value ).replaceAll( '"', '""' ) }"`
				)
				.join( ',' )
		)
		.join( '\r\n' );

const profileInputType = ( key ) => {
	if ( key === 'website' ) {
		return 'url';
	}
	if ( key === 'phone' ) {
		return 'tel';
	}
	return 'text';
};

const Status = ( { state, onRetry } ) => {
	if ( ! state ) {
		return null;
	}
	return (
		<div
			role={ state.tone === 'error' ? 'alert' : 'status' }
			aria-live="polite"
		>
			<InlineStatus tone={ state.tone }>
				{ state.message }
				{ onRetry && (
					<button
						type="button"
						className="button-link"
						onClick={ onRetry }
					>
						Retry
					</button>
				) }
			</InlineStatus>
		</div>
	);
};

const LoadingPanel = ( { label = 'Loading venue data...' } ) => (
	<Panel>
		<p aria-live="polite">{ label }</p>
	</Panel>
);

function ProfileTab( {
	profile,
	baseline,
	setProfile,
	onSave,
	saving,
	status,
} ) {
	const dirty = profile && baseline && ! sameDocument( profile, baseline );
	return (
		<Panel>
			<PanelHeader
				title="Public venue profile"
				description="These fields update the canonical Events venue profile."
			/>
			{ PROFILE_FIELDS.map( ( [ key, label, required ] ) => (
				<FieldGroup
					key={ key }
					label={ label }
					htmlFor={ `venue-profile-${ key }` }
					required={ required }
				>
					{ key === 'description' ? (
						<textarea
							id={ `venue-profile-${ key }` }
							rows="5"
							value={ profile[ key ] }
							onChange={ ( event ) =>
								setProfile( {
									...profile,
									[ key ]: event.target.value,
								} )
							}
						/>
					) : (
						<input
							id={ `venue-profile-${ key }` }
							type={ profileInputType( key ) }
							value={ profile[ key ] }
							required={ required }
							onChange={ ( event ) =>
								setProfile( {
									...profile,
									[ key ]: event.target.value,
								} )
							}
						/>
					) }
				</FieldGroup>
			) ) }
			<Status state={ status } />
			<ActionRow>
				<button
					type="button"
					className="button-1"
					disabled={ ! dirty || saving || ! profile.name.trim() }
					onClick={ onSave }
				>
					{ saving ? 'Saving...' : 'Save profile' }
				</button>
				{ dirty && (
					<span className="ec-venue-settings__dirty">
						Unsaved profile changes
					</span>
				) }
			</ActionRow>
		</Panel>
	);
}

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

function BookingTab( { config, baseline, setConfig, onSave, saving, status } ) {
	const errors = validateConfig( config );
	const dirty = ! sameDocument( config, baseline );
	const deal = config.default_deal;
	const setDeal = ( patch ) =>
		setConfig( { ...config, default_deal: { ...deal, ...patch } } );
	return (
		<div className="ec-venue-settings__stack">
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
					help="Between 5 minutes and 7 days."
				>
					<input
						id="venue-hold-ttl"
						type="number"
						min="5"
						max="10080"
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
			<SpacesEditor
				spaces={ config.spaces }
				onChange={ ( spaces ) => setConfig( { ...config, spaces } ) }
			/>
			<Panel>
				<PanelHeader
					title="Default deal"
					description="Starting terms only; each booking keeps its own negotiated deal."
				/>
				<div className="ec-venue-settings__grid">
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
				</div>
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
					{ saving ? 'Saving...' : 'Save booking defaults' }
				</button>
				{ dirty && (
					<span className="ec-venue-settings__dirty">
						Unsaved booking changes
					</span>
				) }
			</ActionRow>
		</div>
	);
}

function IntakeTab( { config, setConfig } ) {
	const fields = config.intake.fields;
	const setFields = ( next ) =>
		setConfig( { ...config, intake: { ...config.intake, fields: next } } );
	const update = ( index, patch ) =>
		setFields(
			fields.map( ( field, current ) =>
				current === index ? { ...field, ...patch } : field
			)
		);
	return (
		<div className="ec-venue-settings__stack">
			<PublicBookingDetails config={ config } setConfig={ setConfig } />
			<Panel>
				<PanelHeader
					title="Inquiry fields"
					description="Define the canonical information requested before a booking enters the pipeline."
				/>
				{ fields.length === 0 && (
					<p>No custom intake fields configured.</p>
				) }
				{ fields.map( ( field, index ) => (
					<fieldset
						className="ec-venue-settings__repeater"
						key={ `${ field.key }-${ index }` }
					>
						<legend>Field { index + 1 }</legend>
						<div className="ec-venue-settings__grid">
							<FieldGroup
								label="Label"
								htmlFor={ `intake-label-${ index }` }
								required
							>
								<input
									id={ `intake-label-${ index }` }
									value={ field.label }
									onChange={ ( event ) =>
										update( index, {
											label: event.target.value,
											key:
												field.key ||
												normalizeKey(
													event.target.value
												),
										} )
									}
								/>
							</FieldGroup>
							<FieldGroup
								label="Key"
								htmlFor={ `intake-key-${ index }` }
								required
							>
								<input
									id={ `intake-key-${ index }` }
									value={ field.key }
									onChange={ ( event ) =>
										update( index, {
											key: normalizeKey(
												event.target.value
											),
										} )
									}
								/>
							</FieldGroup>
							<FieldGroup
								label="Type"
								htmlFor={ `intake-type-${ index }` }
							>
								<select
									id={ `intake-type-${ index }` }
									value={ field.type }
									onChange={ ( event ) =>
										update( index, {
											type: event.target.value,
										} )
									}
								>
									{ INTAKE_TYPES.map( ( type ) => (
										<option value={ type } key={ type }>
											{ type }
										</option>
									) ) }
								</select>
							</FieldGroup>
						</div>
						{ field.type === 'select' && (
							<FieldGroup
								label="Options"
								htmlFor={ `intake-options-${ index }` }
								help="One option per line."
							>
								<textarea
									id={ `intake-options-${ index }` }
									value={ field.options.join( '\n' ) }
									onChange={ ( event ) =>
										update( index, {
											options: event.target.value
												.split( '\n' )
												.map( ( option ) =>
													option.trim()
												)
												.filter( Boolean ),
										} )
									}
								/>
							</FieldGroup>
						) }
						<label htmlFor={ `intake-required-${ index }` }>
							<input
								id={ `intake-required-${ index }` }
								type="checkbox"
								checked={ field.required }
								onChange={ ( event ) =>
									update( index, {
										required: event.target.checked,
									} )
								}
							/>{ ' ' }
							Required
						</label>
						<button
							type="button"
							className="button-link-delete"
							onClick={ () =>
								setFields(
									fields.filter(
										( _, current ) => current !== index
									)
								)
							}
						>
							Remove field
						</button>
					</fieldset>
				) ) }
				<button
					type="button"
					className="button-2"
					onClick={ () =>
						setFields( [
							...fields,
							{
								key: '',
								label: '',
								type: 'text',
								required: false,
								options: [],
							},
						] )
					}
				>
					Add intake field
				</button>
				<p className="ec-venue-settings__save-note">
					Intake changes are saved with the Booking tab’s single
					revisioned document.
				</p>
			</Panel>
		</div>
	);
}

function TeamTab( { venueId, members, invitations, subscribers, onRefresh } ) {
	const [ email, setEmail ] = useState( '' );
	const [ owner, setOwner ] = useState( false );
	const [ action, setAction ] = useState( null );
	const mutate = async ( name, input, message ) => {
		setAction( { tone: 'info', message: 'Working...' } );
		try {
			await runAbility( name, input );
			setAction( { tone: 'success', message } );
			await onRefresh();
			return true;
		} catch ( error ) {
			const details = errorDetails( error );
			setAction( {
				tone: details.status === 409 ? 'warning' : 'error',
				message:
					details.status === 409
						? `${ details.message } Refreshing current team state.`
						: details.message,
			} );
			if ( details.status === 409 ) {
				await onRefresh();
			}
			return false;
		}
	};
	const invite = async ( event ) => {
		event.preventDefault();
		const sent = await mutate(
			'extrachill/create-venue-invitation',
			{ venue_term_id: venueId, email, is_owner: owner },
			'Invitation queued.'
		);
		if ( sent ) {
			setEmail( '' );
			setOwner( false );
		}
	};
	const exportSubscribers = () => {
		const url = window.URL.createObjectURL(
			new window.Blob( [ venueSubscriberCsv( subscribers ) ], {
				type: 'text/csv;charset=utf-8',
			} )
		);
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = `venue-${ venueId }-email-subscribers.csv`;
		link.click();
		window.URL.revokeObjectURL( url );
	};
	return (
		<div className="ec-venue-settings__stack">
			<Panel>
				<PanelHeader
					title="Venue email list"
					description="Only accounts that explicitly share email access with this venue appear here. Addresses always reflect the current account email."
				/>
				{ subscribers.length === 0 ? (
					<p>
						No accounts currently share email access with this
						venue.
					</p>
				) : (
					<>
						<ul className="ec-venue-settings__records">
							{ subscribers.map( ( subscriber ) => (
								<li key={ subscriber.user_id }>
									<a href={ `mailto:${ subscriber.email }` }>
										{ subscriber.email }
									</a>
								</li>
							) ) }
						</ul>
						<ActionRow>
							<button
								type="button"
								className="button-2"
								onClick={ exportSubscribers }
							>
								Download CSV
							</button>
						</ActionRow>
					</>
				) }
			</Panel>
			<Panel>
				<PanelHeader
					title="Invite a teammate"
					description="Invited accounts remain inactive until the signed email invitation is accepted."
				/>
				<form onSubmit={ invite }>
					<FieldGroup
						label="Email"
						htmlFor="venue-invite-email"
						required
					>
						<input
							id="venue-invite-email"
							type="email"
							required
							value={ email }
							onChange={ ( event ) =>
								setEmail( event.target.value )
							}
						/>
					</FieldGroup>
					<label htmlFor="venue-invite-owner">
						<input
							id="venue-invite-owner"
							type="checkbox"
							checked={ owner }
							onChange={ ( event ) =>
								setOwner( event.target.checked )
							}
						/>{ ' ' }
						Venue owner (can manage team)
					</label>
					<ActionRow>
						<button className="button-1" type="submit">
							Send invitation
						</button>
					</ActionRow>
				</form>
				<Status state={ action } />
			</Panel>
			<Panel>
				<PanelHeader
					title="Memberships"
					description="Capability access is controlled by WordPress; ownership only controls team administration."
				/>
				{ members.length === 0 ? (
					<p>No membership records found.</p>
				) : (
					<ul className="ec-venue-settings__records">
						{ members.map( ( member ) => (
							<li key={ member.id }>
								<div>
									<strong>User #{ member.user_id }</strong>
									<div>
										<Badge>{ member.status }</Badge>{ ' ' }
										{ member.is_owner && (
											<Badge>owner</Badge>
										) }
									</div>
								</div>
								{ member.status === 'active' && (
									<ActionRow>
										<button
											type="button"
											className="button-2 button-small"
											onClick={ () =>
												mutate(
													'extrachill/update-venue-membership',
													{
														venue_term_id: venueId,
														user_id: member.user_id,
														is_owner:
															! member.is_owner,
														expected_version:
															member.version,
													},
													member.is_owner
														? 'Owner access removed.'
														: 'Owner access granted.'
												)
											}
										>
											{ member.is_owner
												? 'Make member'
												: 'Make owner' }
										</button>
										<button
											type="button"
											className="button-link-delete"
											onClick={ () =>
												// eslint-disable-next-line no-alert -- Destructive membership action requires explicit confirmation.
												window.confirm(
													'Revoke this venue membership?'
												) &&
												mutate(
													'extrachill/revoke-venue-membership',
													{
														venue_term_id: venueId,
														user_id: member.user_id,
														expected_version:
															member.version,
													},
													'Membership revoked.'
												)
											}
										>
											Revoke
										</button>
									</ActionRow>
								) }
							</li>
						) ) }
					</ul>
				) }
			</Panel>
			<Panel>
				<PanelHeader
					title="Invitations"
					description="Delivery status and acceptance lifecycle for this venue."
				/>
				{ invitations.length === 0 ? (
					<p>No invitations found.</p>
				) : (
					<ul className="ec-venue-settings__records">
						{ invitations.map( ( invitation ) => (
							<li key={ invitation.id }>
								<div>
									<strong>
										User #{ invitation.user_id }
									</strong>
									<div>
										<Badge>{ invitation.status }</Badge>{ ' ' }
										<Badge>
											{ invitation.delivery_status }
										</Badge>{ ' ' }
										{ invitation.is_owner && (
											<Badge>owner</Badge>
										) }
									</div>
								</div>
								{ invitation.status === 'pending' && (
									<ActionRow>
										<button
											type="button"
											className="button-2 button-small"
											onClick={ () =>
												mutate(
													'extrachill/resend-venue-invitation',
													{
														venue_term_id: venueId,
														invitation_id:
															invitation.id,
														expected_version:
															invitation.version,
													},
													'Invitation requeued.'
												)
											}
										>
											Resend
										</button>
										<button
											type="button"
											className="button-link-delete"
											onClick={ () =>
												mutate(
													'extrachill/cancel-venue-invitation',
													{
														venue_term_id: venueId,
														invitation_id:
															invitation.id,
														expected_version:
															invitation.version,
													},
													'Invitation cancelled.'
												)
											}
										>
											Cancel
										</button>
									</ActionRow>
								) }
							</li>
						) ) }
					</ul>
				) }
			</Panel>
		</div>
	);
}

function ClaimsTab( { claims, venues, onRefresh } ) {
	const [ status, setStatus ] = useState( null );
	const venueName = ( id ) =>
		venues.find( ( venue ) => venue.id === id )?.name || `Venue #${ id }`;
	const review = async ( claim, decision ) => {
		setStatus( { tone: 'info', message: 'Saving decision...' } );
		try {
			await runAbility( 'extrachill/review-venue-claim', {
				claim_id: claim.id,
				decision,
				expected_version: claim.version,
			} );
			setStatus( { tone: 'success', message: `Claim ${ decision }.` } );
			await onRefresh();
		} catch ( error ) {
			const details = errorDetails( error );
			setStatus( {
				tone: details.status === 409 ? 'warning' : 'error',
				message: details.message,
			} );
			if ( details.status === 409 ) {
				await onRefresh();
			}
		}
	};
	return (
		<Panel>
			<PanelHeader
				title="Venue claims"
				description="Administrator review creates the first owner membership atomically."
			/>
			<Status state={ status } />
			{ claims.length === 0 ? (
				<p>No venue claims found.</p>
			) : (
				<ul className="ec-venue-settings__records">
					{ claims.map( ( claim ) => (
						<li key={ claim.id }>
							<div>
								<strong>
									{ venueName( claim.venue_term_id ) }
								</strong>
								<div>
									Claimant user #{ claim.claimant_user_id }{ ' ' }
									<Badge>{ claim.status }</Badge>
								</div>
							</div>
							{ claim.status === 'pending' && (
								<ActionRow>
									<button
										type="button"
										className="button-1 button-small"
										onClick={ () =>
											review( claim, 'approved' )
										}
									>
										Approve
									</button>
									<button
										type="button"
										className="button-2 button-small"
										onClick={ () =>
											review( claim, 'rejected' )
										}
									>
										Reject
									</button>
								</ActionRow>
							) }
						</li>
					) ) }
				</ul>
			) }
		</Panel>
	);
}

function ClaimPanel( { venues, membership } ) {
	const [ venueId, setVenueId ] = useState( venues[ 0 ]?.id || 0 );
	const [ status, setStatus ] = useState( null );
	const submit = async ( event ) => {
		event.preventDefault();
		setStatus( { tone: 'info', message: 'Submitting claim...' } );
		try {
			const claim = await runAbility( 'extrachill/submit-venue-claim', {
				venue_term_id: venueId,
			} );
			setStatus( {
				tone: 'success',
				message: `Claim ${ claim.status }. An administrator will review it.`,
			} );
		} catch ( error ) {
			setStatus( {
				tone: 'error',
				message: errorDetails( error ).message,
			} );
		}
	};
	return (
		<Panel>
			<PanelHeader
				title="Request venue access"
				description="Claim an existing canonical venue profile. Approval creates the first owner membership."
			/>
			{ membership && (
				<InlineStatus tone="warning">
					Your { membership.status } membership cannot access
					active-member settings.
				</InlineStatus>
			) }
			<form onSubmit={ submit }>
				<FieldGroup label="Venue" htmlFor="venue-claim-select">
					<select
						id="venue-claim-select"
						value={ venueId }
						onChange={ ( event ) =>
							setVenueId( Number( event.target.value ) )
						}
					>
						{ venues.map( ( venue ) => (
							<option key={ venue.id } value={ venue.id }>
								{ venue.name }
							</option>
						) ) }
					</select>
				</FieldGroup>
				<ActionRow>
					<button
						type="submit"
						className="button-1"
						disabled={ ! venueId }
					>
						Submit claim
					</button>
				</ActionRow>
			</form>
			<Status state={ status } />
		</Panel>
	);
}

export function VenueSettingsApp( { context } ) {
	const selected = context.selected_venue;
	const [ activeTab, setActiveTab ] = useState( 'calendar' );
	const [ loading, setLoading ] = useState( Boolean( context.can_access ) );
	const [ loadErrors, setLoadErrors ] = useState( {} );
	const [ profile, setProfile ] = useState( null );
	const [ profileBaseline, setProfileBaseline ] = useState( null );
	const [ config, setConfig ] = useState( null );
	const [ configBaseline, setConfigBaseline ] = useState( null );
	const [ configRevision, setConfigRevision ] = useState( 0 );
	const [ members, setMembers ] = useState( [] );
	const [ invitations, setInvitations ] = useState( [] );
	const [ subscribers, setSubscribers ] = useState( [] );
	const [ claims, setClaims ] = useState( [] );
	const [ profileStatus, setProfileStatus ] = useState( null );
	const [ configStatus, setConfigStatus ] = useState( null );
	const [ savingProfile, setSavingProfile ] = useState( false );
	const [ savingConfig, setSavingConfig ] = useState( false );
	const profileRequestId = useRef( 0 );
	const configRequestId = useRef( 0 );
	const dirty =
		Boolean(
			profile &&
				profileBaseline &&
				! sameDocument( profile, profileBaseline )
		) ||
		Boolean(
			config && configBaseline && ! sameDocument( config, configBaseline )
		);

	const loadTeam = async () => {
		if ( ! selected || ! context.can_manage ) {
			return;
		}
		const [ memberResult, invitationResult, subscriberResult ] =
			await Promise.allSettled( [
				runAbility( 'extrachill/list-venue-memberships', {
					venue_term_id: selected.id,
				} ),
				runAbility( 'extrachill/list-venue-invitations', {
					venue_term_id: selected.id,
				} ),
				runAbility( 'extrachill/list-venue-email-subscribers', {
					venue_term_id: selected.id,
				} ),
			] );
		if ( memberResult.status === 'fulfilled' ) {
			setMembers( memberResult.value );
		}
		if ( invitationResult.status === 'fulfilled' ) {
			setInvitations( invitationResult.value );
		}
		if ( subscriberResult.status === 'fulfilled' ) {
			setSubscribers( subscriberResult.value.subscribers || [] );
		}
		setLoadErrors( ( current ) => ( {
			...current,
			team:
				memberResult.status === 'rejected' ||
				invitationResult.status === 'rejected' ||
				subscriberResult.status === 'rejected'
					? 'Some team records could not be loaded.'
					: null,
		} ) );
	};
	const loadClaims = async () => {
		if ( ! context.user.is_admin ) {
			return;
		}
		try {
			setClaims( await runAbility( 'extrachill/list-venue-claims' ) );
			setLoadErrors( ( current ) => ( { ...current, claims: null } ) );
		} catch ( error ) {
			setLoadErrors( ( current ) => ( {
				...current,
				claims: errorDetails( error ).message,
			} ) );
		}
	};
	const loadProfile = async () => {
		const currentRequest = ++profileRequestId.current;
		setLoadErrors( ( current ) => ( { ...current, profile: null } ) );
		try {
			const result = await runAbility( 'extrachill/get-venue-profile', {
				venue_term_id: selected.id,
			} );
			if ( currentRequest !== profileRequestId.current ) {
				return;
			}
			setProfile( result );
			setProfileBaseline( result );
		} catch ( error ) {
			if ( currentRequest !== profileRequestId.current ) {
				return;
			}
			setLoadErrors( ( current ) => ( {
				...current,
				profile: errorDetails( error ).message,
			} ) );
		}
	};
	const loadConfig = async () => {
		const currentRequest = ++configRequestId.current;
		setLoadErrors( ( current ) => ( { ...current, config: null } ) );
		try {
			const result = await runAbility(
				'extrachill/get-venue-booking-config',
				{
					venue_term_id: selected.id,
				}
			);
			if ( currentRequest !== configRequestId.current ) {
				return;
			}
			setConfigRevision( result.revision );
			const editable = editableConfig( result );
			setConfig( editable );
			setConfigBaseline( editable );
		} catch ( error ) {
			if ( currentRequest !== configRequestId.current ) {
				return;
			}
			setLoadErrors( ( current ) => ( {
				...current,
				config: errorDetails( error ).message,
			} ) );
		}
	};
	const loadVenue = async () => {
		if ( ! selected || ! context.can_access ) {
			return;
		}
		setLoading( true );
		setLoadErrors( {} );
		setProfile( null );
		setConfig( null );
		await Promise.all( [ loadProfile(), loadConfig() ] );
		setLoading( false );
		await Promise.all( [ loadTeam(), loadClaims() ] );
	};

	useEffect( () => {
		if ( context.can_access ) {
			loadVenue();
		} else {
			loadClaims();
		}
		// The selected venue is immutable for this mount; switching performs a full route navigation.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
	useEffect( () => {
		const warn = ( event ) => {
			if ( ! dirty ) {
				return;
			}
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	const switchVenue = ( event ) => {
		if (
			dirty &&
			// eslint-disable-next-line no-alert -- Native navigation guard is keyboard and screen-reader accessible.
			! window.confirm( 'Discard unsaved changes and switch venues?' )
		) {
			return;
		}
		const venueId = Number( event.target.value );
		const url = new URL( context.route_url );
		if ( venueId ) {
			url.searchParams.set( 'venue_id', venueId );
		}
		window.location.assign( url.toString() );
	};
	const saveProfile = async () => {
		setSavingProfile( true );
		setProfileStatus( null );
		try {
			const result = await runAbility(
				'extrachill/update-venue-profile',
				{
					venue_term_id: selected.id,
					expected_revision: profileBaseline.revision,
					profile: profileChanges( profile, profileBaseline ),
				}
			);
			setProfile( result.profile );
			setProfileBaseline( result.profile );
			setProfileStatus( {
				tone: 'success',
				message: 'Venue profile saved.',
			} );
		} catch ( error ) {
			const details = errorDetails( error );
			setProfileStatus( {
				tone: details.status === 409 ? 'warning' : 'error',
				message:
					details.status === 409
						? `${ details.message } Reload before saving again.`
						: details.message,
			} );
		} finally {
			setSavingProfile( false );
		}
	};
	const saveConfig = async () => {
		setSavingConfig( true );
		setConfigStatus( null );
		try {
			const result = await runAbility(
				'extrachill/update-venue-booking-config',
				{
					venue_term_id: selected.id,
					expected_revision: configRevision,
					config,
				}
			);
			const editable = editableConfig( result );
			setConfigRevision( result.revision );
			setConfig( editable );
			setConfigBaseline( editable );
			setConfigStatus( {
				tone: 'success',
				message: 'Booking defaults saved.',
			} );
		} catch ( error ) {
			const details = errorDetails( error );
			setConfigStatus( {
				tone: details.status === 409 ? 'warning' : 'error',
				message:
					details.status === 409
						? `${ details.message } Reload before saving again.`
						: details.message,
			} );
		} finally {
			setSavingConfig( false );
		}
	};

	const tabs = [
		{ id: 'calendar', label: 'Calendar' },
		{ id: 'bookings', label: 'Bookings' },
		{ id: 'profile', label: 'Profile' },
		{ id: 'booking', label: 'Booking' },
		{ id: 'intake', label: 'Intake' },
		...( context.can_manage ? [ { id: 'team', label: 'Team' } ] : [] ),
		...( context.user.is_admin
			? [ { id: 'claims', label: 'Claims' } ]
			: [] ),
	];
	const renderPanel = ( tab ) => {
		if ( tab === 'calendar' || tab === 'bookings' ) {
			return (
				<BookingConsole
					key={ `${ selected.id }-${ tab }` }
					context={ context }
					members={ members }
					defaultDeal={ config?.default_deal }
					view={ tab }
				/>
			);
		}
		if ( tab === 'profile' ) {
			if ( loadErrors.profile ) {
				return (
					<Panel>
						<InlineStatus tone="error">
							{ loadErrors.profile }
						</InlineStatus>
						<button
							type="button"
							className="button-2"
							onClick={ loadProfile }
						>
							Retry profile
						</button>
					</Panel>
				);
			}
			return profile ? (
				<ProfileTab
					profile={ profile }
					baseline={ profileBaseline }
					setProfile={ setProfile }
					onSave={ saveProfile }
					saving={ savingProfile }
					status={ profileStatus }
				/>
			) : (
				<LoadingPanel label="Loading profile..." />
			);
		}
		if ( tab === 'booking' ) {
			if ( loadErrors.config ) {
				return (
					<Panel>
						<InlineStatus tone="error">
							{ loadErrors.config }
						</InlineStatus>
						<button
							type="button"
							className="button-2"
							onClick={ loadConfig }
						>
							Retry booking settings
						</button>
					</Panel>
				);
			}
			return config ? (
				<BookingTab
					config={ config }
					baseline={ configBaseline }
					setConfig={ setConfig }
					onSave={ saveConfig }
					saving={ savingConfig }
					status={ configStatus }
				/>
			) : (
				<LoadingPanel label="Loading booking settings..." />
			);
		}
		if ( tab === 'intake' ) {
			return config ? (
				<IntakeTab config={ config } setConfig={ setConfig } />
			) : (
				<LoadingPanel label="Loading intake settings..." />
			);
		}
		if ( tab === 'team' ) {
			return (
				<>
					<Status
						state={
							loadErrors.team
								? { tone: 'warning', message: loadErrors.team }
								: null
						}
						onRetry={ loadTeam }
					/>
					<TeamTab
						venueId={ selected.id }
						members={ members }
						invitations={ invitations }
						subscribers={ subscribers }
						onRefresh={ loadTeam }
					/>
				</>
			);
		}
		return (
			<>
				<Status
					state={
						loadErrors.claims
							? { tone: 'error', message: loadErrors.claims }
							: null
					}
					onRetry={ loadClaims }
				/>
				<ClaimsTab
					claims={ claims }
					venues={ context.claim_venues }
					onRefresh={ loadClaims }
				/>
			</>
		);
	};
	const renderWorkspace = () => {
		if ( ! selected || ! context.can_access ) {
			if ( context.user.is_admin ) {
				return (
					<>
						<InlineStatus tone="info">
							Administrator claim review is available without an
							active venue membership. Profile and booking
							settings still require canonical venue access.
						</InlineStatus>
						<Status
							state={
								loadErrors.claims
									? {
											tone: 'error',
											message: loadErrors.claims,
									  }
									: null
							}
							onRetry={ loadClaims }
						/>
						<ClaimsTab
							claims={ claims }
							venues={ context.claim_venues }
							onRefresh={ loadClaims }
						/>
					</>
				);
			}
			return (
				<ClaimPanel
					venues={ context.claim_venues }
					membership={ selected }
				/>
			);
		}

		return (
			<>
				{ loading && (
					<p className="screen-reader-text" aria-live="polite">
						Loading venue workspace.
					</p>
				) }
				<ResponsiveTabs
					tabs={ tabs }
					active={ activeTab }
					onChange={ setActiveTab }
					renderPanel={ renderPanel }
					mobileBreakpoint={ 720 }
					syncWithHash
					contextSurface="venue-settings"
				/>
			</>
		);
	};

	return (
		<BlockShell className="ec-venue-settings__shell">
			<BlockShellHeader
				title="Manage venue"
				description="Calendar, booking operations, profile, defaults, access, and onboarding in one venue-scoped workspace."
			/>
			<BlockShellInner>
				{ context.venues.length > 0 && (
					<FieldGroup
						label="Venue workspace"
						htmlFor="venue-workspace"
					>
						<select
							id="venue-workspace"
							value={ selected?.id || 0 }
							onChange={ switchVenue }
						>
							<option value="0">Choose a venue</option>
							{ context.venues.map( ( venue ) => (
								<option key={ venue.id } value={ venue.id }>
									{ venue.name }
									{ venue.status !== 'active'
										? ` (${ venue.status })`
										: '' }
								</option>
							) ) }
						</select>
					</FieldGroup>
				) }
				{ selected && (
					<div className="ec-venue-settings__context">
						<strong>{ selected.name }</strong>
						<span>
							<Badge>{ selected.status }</Badge>{ ' ' }
							{ selected.is_owner && <Badge>owner</Badge> }
						</span>
					</div>
				) }
				{ renderWorkspace() }
			</BlockShellInner>
		</BlockShell>
	);
}

document
	.querySelectorAll( '.ec-venue-settings__root' )
	.forEach( ( element ) => {
		const contextElement = document.getElementById(
			element.dataset.contextId
		);
		if ( ! contextElement ) {
			return;
		}
		try {
			createRoot( element ).render(
				<VenueSettingsApp
					context={ JSON.parse( contextElement.textContent ) }
				/>
			);
		} catch {
			element.innerHTML =
				'<div class="ec-inline-status ec-inline-status--error" role="alert">Venue settings could not start. Refresh the page to try again.</div>';
		}
	} );
