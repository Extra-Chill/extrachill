/**
 * WordPress dependencies
 */
import { createRoot, useEffect, useRef, useState } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	ActionRow,
	BlockShell,
	BlockShellHeader,
	BlockShellInner,
	FieldGroup,
	Grid,
	InlineStatus,
	Panel,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import {
	availabilityErrorState,
	buildAvailabilityPayload,
	buildPayload,
	errorState,
	newIdempotencyKey,
} from './submission';
import {
	linkCollectionValue,
	updateLinkCollection,
} from '../../venue-settings/src/booking-links';

const initialValues = ( config ) => ( {
	artistName: '',
	contactName: '',
	contactEmail: '',
	contactPhone: '',
	spaceKey:
		config.spaces.find( ( space ) => space.is_default )?.key ||
		config.spaces[ 0 ]?.key ||
		'',
	requestedDate: '',
	message: '',
	consent: false,
	fields: Object.fromEntries(
		config.fields.map( ( field ) => [
			field.key,
			field.type === 'checkbox' ? false : '',
		] )
	),
} );

const Field = ( { field, value, onChange, prefix } ) => {
	const id = `${ prefix }-${ field.key }`;
	if ( field.type === 'checkbox' ) {
		return (
			<label className="ec-checkbox-row" htmlFor={ id }>
				<input
					id={ id }
					type="checkbox"
					checked={ value === true }
					required={ field.required }
					onChange={ ( event ) => onChange( event.target.checked ) }
				/>{ ' ' }
				{ field.label }
				{ field.required && <span aria-hidden="true"> *</span> }
			</label>
		);
	}
	const inputType = field.type === 'phone' ? 'tel' : field.type;
	let control = (
		<input
			id={ id }
			type={ inputType }
			value={ value }
			required={ field.required }
			onChange={ ( event ) => onChange( event.target.value ) }
		/>
	);
	if ( field.type === 'textarea' ) {
		control = (
			<textarea
				id={ id }
				rows="4"
				value={ value }
				required={ field.required }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
		);
	} else if ( field.type === 'url_list' ) {
		control = (
			<textarea
				id={ id }
				rows="2"
				value={ value }
				required={ field.required }
				placeholder="One https:// link per line"
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
		);
	} else if ( field.type === 'select' ) {
		control = (
			<select
				id={ id }
				value={ value }
				required={ field.required }
				onChange={ ( event ) => onChange( event.target.value ) }
			>
				<option value="">Choose an option</option>
				{ field.options.map( ( option ) => (
					<option key={ option } value={ option }>
						{ option }
					</option>
				) ) }
			</select>
		);
	}
	return (
		<FieldGroup
			label={ field.label }
			htmlFor={ id }
			required={ field.required }
		>
			{ control }
		</FieldGroup>
	);
};

export function BookingInquiry( { config, wrapper, preview = false } ) {
	const [ values, setValues ] = useState( () => initialValues( config ) );
	const [ status, setStatus ] = useState( null );
	const [ submitting, setSubmitting ] = useState( false );
	const [ checking, setChecking ] = useState( false );
	const [ intervalOpen, setIntervalOpen ] = useState( preview );
	const [ receipt, setReceipt ] = useState( null );
	const key = useRef( newIdempotencyKey() );
	const turnstileTarget = useRef();
	const resultRef = useRef();
	const prefix = config.instanceId;

	useEffect( () => {
		if ( preview || ! wrapper ) {
			return;
		}
		const source = wrapper.querySelector( '[data-booking-turnstile]' );
		if ( source && turnstileTarget.current ) {
			turnstileTarget.current.appendChild( source );
		}
	}, [ wrapper, intervalOpen, preview ] );
	useEffect( () => {
		if ( status || receipt ) {
			resultRef.current?.focus();
		}
	}, [ status, receipt ] );

	const update = ( patch, resetInterval = false ) => {
		key.current = newIdempotencyKey();
		setValues( ( current ) => ( { ...current, ...patch } ) );
		setStatus( null );
		if ( resetInterval ) {
			setIntervalOpen( false );
		}
	};
	const updateField = ( fieldKey, value ) =>
		update( { fields: { ...values.fields, [ fieldKey ]: value } } );
	const resetTurnstile = () => {
		const widget = wrapper.querySelector( '.cf-turnstile' );
		if ( widget ) {
			widget.replaceChildren();
			widget.removeAttribute( 'data-ec-turnstile-rendered' );
			window.ecTurnstileBoot?.();
		}
	};
	const checkAvailability = async () => {
		setChecking( true );
		setStatus( { tone: 'info', message: 'Checking that date...' } );
		try {
			const headers = { 'Content-Type': 'application/json' };
			if ( config.restNonce ) {
				headers[ 'X-WP-Nonce' ] = config.restNonce;
			}
			const response = await fetch( config.availabilityEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: JSON.stringify(
					buildAvailabilityPayload( config, values )
				),
			} );
			const payload = await response.json().catch( () => ( {} ) );
			if ( ! response.ok ) {
				setStatus( availabilityErrorState( response, payload ) );
				return;
			}
			if ( payload.available !== true ) {
				setStatus( {
					tone: 'warning',
					message:
						'That date is unavailable. Choose another date to continue.',
				} );
				return;
			}
			setIntervalOpen( true );
			setStatus( {
				tone: 'success',
				message:
					'That date is available for submissions. Complete the booking details below.',
			} );
		} catch {
			setStatus( {
				tone: 'error',
				message: 'The availability check was interrupted. Try again.',
			} );
		} finally {
			setChecking( false );
		}
	};
	const submit = async ( event ) => {
		event.preventDefault();
		if ( preview ) {
			return;
		}
		if ( submitting || ! event.currentTarget.reportValidity() ) {
			return;
		}
		if ( ! intervalOpen ) {
			await checkAvailability();
			return;
		}
		const token =
			wrapper
				.querySelector( 'input[name="cf-turnstile-response"]' )
				?.value?.trim() || '';
		if ( ! token ) {
			setStatus( {
				tone: 'warning',
				message: 'Complete the security check before sending.',
			} );
			return;
		}
		setSubmitting( true );
		setStatus( { tone: 'info', message: 'Sending your inquiry...' } );
		try {
			const headers = { 'Content-Type': 'application/json' };
			if ( config.restNonce ) {
				headers[ 'X-WP-Nonce' ] = config.restNonce;
			}
			const response = await fetch( config.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: JSON.stringify(
					buildPayload( config, values, key.current, token )
				),
			} );
			const payload = await response.json().catch( () => ( {} ) );
			resetTurnstile();
			if ( ! response.ok ) {
				const next = errorState( response, payload );
				if ( next.rotateKey ) {
					key.current = newIdempotencyKey();
				}
				if ( next.resetAvailability ) {
					setIntervalOpen( false );
				}
				setStatus( next );
				return;
			}
			setReceipt( payload );
			setStatus( null );
		} catch {
			setStatus( {
				tone: 'error',
				retryable: true,
				message:
					'The network interrupted this attempt. Your details are still here; retry with the same details.',
			} );
		} finally {
			setSubmitting( false );
		}
	};

	if ( receipt ) {
		return (
			<BlockShell>
				<BlockShellInner>
					<Panel>
						<div
							className="ec-booking-inquiry__result"
							ref={ resultRef }
							tabIndex="-1"
							role="status"
						>
							<span className="taxonomy-badge">
								Inquiry received
							</span>
							<h3>
								Thanks for reaching out to { config.venue.name }
								.
							</h3>
							<p>
								Keep this submission reference for your records:
							</p>
							<strong className="ec-booking-inquiry__reference">
								{ receipt.public_id }
							</strong>
						</div>
					</Panel>
				</BlockShellInner>
			</BlockShell>
		);
	}
	const visibleFields = config.fields.filter( ( field ) => {
		if ( ! field.visible_when ) {
			return true;
		}
		return (
			String( values.fields[ field.visible_when.field ] ?? '' ) ===
			field.visible_when.value
		);
	} );
	const proposalFields = visibleFields.filter(
		( field ) => ! [ 'url', 'url_list' ].includes( field.type )
	);
	const linkFields = visibleFields.filter( ( field ) =>
		[ 'url', 'url_list' ].includes( field.type )
	);
	const linkCollection = linkFields.length
		? {
				...linkFields[ 0 ],
				key: 'links',
				label: 'Links',
				type: 'url_list',
				required: linkFields.some( ( field ) => field.required ),
		  }
		: null;
	let submitLabel = 'Check availability';
	if ( checking ) {
		submitLabel = 'Checking...';
	} else if ( intervalOpen ) {
		submitLabel = submitting ? 'Sending...' : config.buttonLabel;
	}

	return (
		<BlockShell>
			{ config.venue.logoUrl && (
				<img
					className="ec-booking-inquiry__logo"
					src={ config.venue.logoUrl }
					alt=""
				/>
			) }
			<BlockShellHeader title={ `Booking at ${ config.venue.name }` } />
			<BlockShellInner className="ec-panel ec-booking-inquiry__panel">
				<form
					className="ec-booking-inquiry__form"
					onSubmit={ submit }
					noValidate={ false }
				>
					<section className="ec-booking-inquiry__step">
						<h3>1. Check your requested date</h3>
						<Grid minColumnWidth="16rem" maxColumns={ 2 }>
							{ config.spaces.length > 1 && (
								<FieldGroup
									label="Requested space"
									htmlFor={ `${ prefix }-space` }
									required
								>
									<select
										id={ `${ prefix }-space` }
										value={ values.spaceKey }
										required
										onChange={ ( event ) =>
											update(
												{
													spaceKey:
														event.target.value,
												},
												true
											)
										}
									>
										{ config.spaces.map( ( space ) => (
											<option
												key={ space.key }
												value={ space.key }
											>
												{ space.name }
											</option>
										) ) }
									</select>
								</FieldGroup>
							) }
							<FieldGroup
								label="Requested date"
								htmlFor={ `${ prefix }-date` }
								required
							>
								<input
									id={ `${ prefix }-date` }
									type="date"
									value={ values.requestedDate }
									required
									onChange={ ( event ) =>
										update(
											{
												requestedDate:
													event.target.value,
											},
											true
										)
									}
								/>
							</FieldGroup>
						</Grid>
					</section>
					{ intervalOpen && (
						<>
							<section className="ec-booking-inquiry__step">
								<h3>2. Complete your booking inquiry</h3>
								<section className="ec-booking-inquiry__section">
									<h3>Your contact</h3>
									<Grid
										minColumnWidth="16rem"
										maxColumns={ 2 }
									>
										<FieldGroup
											label={
												config.presentation
													.artist_name_label
											}
											htmlFor={ `${ prefix }-artist` }
											required
										>
											<input
												id={ `${ prefix }-artist` }
												value={ values.artistName }
												maxLength="255"
												required
												autoComplete="organization"
												onChange={ ( event ) =>
													update( {
														artistName:
															event.target.value,
													} )
												}
											/>
										</FieldGroup>
										<FieldGroup
											label={
												config.presentation
													.contact_name_label
											}
											htmlFor={ `${ prefix }-contact` }
											required
										>
											<input
												id={ `${ prefix }-contact` }
												value={ values.contactName }
												maxLength="255"
												required
												autoComplete="name"
												onChange={ ( event ) =>
													update( {
														contactName:
															event.target.value,
													} )
												}
											/>
										</FieldGroup>
										<FieldGroup
											label={
												config.presentation
													.contact_email_label
											}
											htmlFor={ `${ prefix }-email` }
											required
										>
											<input
												id={ `${ prefix }-email` }
												type="email"
												value={ values.contactEmail }
												maxLength="255"
												required
												autoComplete="email"
												onChange={ ( event ) =>
													update( {
														contactEmail:
															event.target.value,
													} )
												}
											/>
										</FieldGroup>
										<FieldGroup
											label={
												config.presentation
													.contact_phone_label
											}
											htmlFor={ `${ prefix }-phone` }
										>
											<input
												id={ `${ prefix }-phone` }
												type="tel"
												value={ values.contactPhone }
												maxLength="64"
												autoComplete="tel"
												onChange={ ( event ) =>
													update( {
														contactPhone:
															event.target.value,
													} )
												}
											/>
										</FieldGroup>
									</Grid>
								</section>
								{ proposalFields.length > 0 && (
									<section className="ec-booking-inquiry__section">
										<h3>Event details</h3>
										<Grid
											minColumnWidth="16rem"
											maxColumns={ 2 }
										>
											{ proposalFields.map( ( field ) => (
												<Field
													key={ field.key }
													field={ field }
													value={
														values.fields[
															field.key
														]
													}
													prefix={ prefix }
													onChange={ ( value ) =>
														updateField(
															field.key,
															value
														)
													}
												/>
											) ) }
										</Grid>
									</section>
								) }
								{ linkCollection && (
									<section className="ec-booking-inquiry__section">
										<h3>Links</h3>
										<Field
											field={ linkCollection }
											value={ linkCollectionValue(
												linkFields,
												values.fields
											) }
											prefix={ prefix }
											onChange={ ( value ) =>
												update( {
													fields: updateLinkCollection(
														linkFields,
														values.fields,
														value
													),
												} )
											}
										/>
									</section>
								) }
							</section>
							<section className="ec-booking-inquiry__section">
								<FieldGroup
									label={ config.presentation.message_label }
									htmlFor={ `${ prefix }-message` }
									help={ config.presentation.message_help }
									required
								>
									<textarea
										id={ `${ prefix }-message` }
										rows="5"
										maxLength="10000"
										value={ values.message }
										required
										onChange={ ( event ) =>
											update( {
												message: event.target.value,
											} )
										}
									/>
								</FieldGroup>
							</section>
							<label
								className="ec-checkbox-row"
								htmlFor={ `${ prefix }-consent` }
							>
								<input
									id={ `${ prefix }-consent` }
									type="checkbox"
									required={ config.consent.required }
									checked={ values.consent }
									onChange={ ( event ) =>
										update( {
											consent: event.target.checked,
										} )
									}
								/>{ ' ' }
								<span>
									{ config.consent.label }
									{ config.consent.required && (
										<span aria-hidden="true"> *</span>
									) }
								</span>
							</label>
							{ ! preview && (
								<div
									className="ec-booking-inquiry__turnstile"
									ref={ turnstileTarget }
								/>
							) }
						</>
					) }
					<div ref={ resultRef } tabIndex="-1" aria-live="polite">
						{ status && (
							<InlineStatus tone={ status.tone }>
								{ status.message }
							</InlineStatus>
						) }
					</div>
					<ActionRow>
						<button
							className="button-1 button-large"
							type="submit"
							disabled={ preview || submitting || checking }
						>
							{ submitLabel }
						</button>
						<span className="ec-booking-inquiry__privacy">
							Your inquiry is sent privately and never added to
							the public venue page.
						</span>
					</ActionRow>
					<p className="ec-booking-inquiry__powered">
						Powered by Extra Chill
					</p>
				</form>
			</BlockShellInner>
		</BlockShell>
	);
}

document
	.querySelectorAll( '.ec-venue-booking-inquiry' )
	.forEach( ( wrapper ) => {
		const app = wrapper.querySelector( '[data-booking-app]' );
		const data = wrapper.querySelector( 'script[type="application/json"]' );
		if ( ! app || ! data ) {
			return;
		}
		try {
			createRoot( app ).render(
				<BookingInquiry
					config={ JSON.parse( data.textContent ) }
					wrapper={ wrapper }
				/>
			);
		} catch {
			app.textContent = 'This booking form is temporarily unavailable.';
		}
	} );
