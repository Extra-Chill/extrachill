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
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import {
	availabilityErrorState,
	buildAvailabilityPayload,
	buildPayload,
	clearDraft,
	DRAFT_SCOPE_DEVICE,
	DRAFT_SCOPE_SESSION,
	errorState,
	loadDraft,
	newIdempotencyKey,
	saveDraft,
} from './submission';
import { clearReceipt, loadReceipt, saveReceipt } from './follow-through';
import { BookingFollowThrough, ReceiptRecovery } from './follow-through-view';

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
	const restored = useRef();
	if ( ! restored.current ) {
		restored.current = preview
			? {
					values: initialValues( config ),
					scope: DRAFT_SCOPE_SESSION,
					outcome: 'none',
			  }
			: loadDraft( config, initialValues( config ) );
	}
	const [ values, setValues ] = useState( restored.current.values );
	const [ draftScope, setDraftScope ] = useState( restored.current.scope );
	const [ draftStatus, setDraftStatus ] = useState( '' );
	const [ status, setStatus ] = useState( null );
	const [ submitting, setSubmitting ] = useState( false );
	const [ checking, setChecking ] = useState( false );
	const [ intervalOpen, setIntervalOpen ] = useState( preview );
	const [ receipt, setReceipt ] = useState( () =>
		preview ? null : loadReceipt( config )
	);
	const [ recoveryOpen, setRecoveryOpen ] = useState( false );
	const key = useRef( newIdempotencyKey() );
	const turnstileTarget = useRef();
	const resultRef = useRef();
	const skipDraftSave = useRef( false );
	const prefix = config.instanceId;
	const HeadingTag = `h${ config.headingLevel || 2 }`;
	const heading = (
		<BlockShellHeader
			title={
				<HeadingTag
					id={ `${ prefix }-heading` }
					className="ec-booking-inquiry__heading"
				>
					Booking at { config.venue.name }
				</HeadingTag>
			}
		/>
	);

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
		if ( ! preview && ! receipt ) {
			if ( skipDraftSave.current ) {
				skipDraftSave.current = false;
				return;
			}
			const saved = saveDraft( config, values, draftScope );
			if ( ! saved ) {
				setDraftStatus(
					'Draft storage is unavailable. Your details will remain only on this page.'
				);
			}
		}
	}, [ config, draftScope, values, preview, receipt ] );
	useEffect( () => {
		if ( restored.current.outcome === 'restored' ) {
			setDraftStatus(
				restored.current.scope === DRAFT_SCOPE_DEVICE
					? 'Your saved 24-hour device draft was restored.'
					: 'Your draft from this browser tab was restored.'
			);
		} else if ( restored.current.outcome === 'expired' ) {
			setDraftStatus( 'Your expired saved draft was cleared.' );
		} else if ( restored.current.outcome === 'incompatible' ) {
			setDraftStatus( 'An outdated saved draft was cleared.' );
		} else if ( restored.current.outcome === 'read-failed' ) {
			setDraftStatus(
				'Draft storage is unavailable. Your details will remain only on this page.'
			);
		}
	}, [] );
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
	const changeDraftScope = ( persistOnDevice ) => {
		const nextScope = persistOnDevice
			? DRAFT_SCOPE_DEVICE
			: DRAFT_SCOPE_SESSION;
		setDraftScope( nextScope );
		setDraftStatus(
			persistOnDevice
				? 'This draft will be kept on this device for up to 24 hours.'
				: 'This draft will be kept only in this browser tab.'
		);
	};
	const clearSavedDraft = () => {
		const cleared = clearDraft( config );
		setValues( initialValues( config ) );
		setDraftScope( DRAFT_SCOPE_SESSION );
		setIntervalOpen( false );
		setStatus( null );
		setDraftStatus(
			cleared
				? 'Saved draft cleared. The form is empty.'
				: 'The form is empty, but browser storage could not be fully cleared. Close this tab before leaving this device.'
		);
	};
	const resetTurnstile = () => {
		const widget = wrapper.querySelector( '.cf-turnstile' );
		if ( widget ) {
			widget.replaceChildren();
			widget.removeAttribute( 'data-ec-turnstile-rendered' );
			window.ecTurnstileBoot?.();
		}
	};
	const parkTurnstile = () => {
		const source = wrapper?.querySelector( '[data-booking-turnstile]' );
		if ( source ) {
			wrapper.appendChild( source );
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
			clearDraft( config );
			parkTurnstile();
			saveReceipt( config, payload );
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
	const resetPrivateFormState = () => {
		parkTurnstile();
		const receiptCleared = clearReceipt( config );
		const draftsCleared = clearDraft( config );
		skipDraftSave.current = true;
		key.current = newIdempotencyKey();
		setValues( initialValues( config ) );
		setDraftScope( DRAFT_SCOPE_SESSION );
		setDraftStatus(
			receiptCleared && draftsCleared
				? 'Receipt and saved form details cleared.'
				: "The form was reset, but browser storage may remain. Close this tab and clear this device's site data before leaving a shared device."
		);
		setIntervalOpen( false );
		setStatus( null );
		setRecoveryOpen( false );
	};
	const acceptRecoveredReceipt = ( nextReceipt ) => {
		clearDraft( config );
		key.current = newIdempotencyKey();
		setValues( initialValues( config ) );
		setDraftScope( DRAFT_SCOPE_SESSION );
		setIntervalOpen( false );
		setStatus( null );
		setRecoveryOpen( false );
		setReceipt( nextReceipt );
	};

	if ( receipt ) {
		return (
			<BookingFollowThrough
				config={ config }
				receipt={ receipt }
				wrapper={ wrapper }
				heading={ heading }
				onClear={ () => {
					resetPrivateFormState();
					setReceipt( null );
				} }
				onReceipt={ acceptRecoveredReceipt }
			/>
		);
	}
	if ( recoveryOpen ) {
		return (
			<ReceiptRecovery
				config={ config }
				wrapper={ wrapper }
				heading={ heading }
				onCancel={ () => setRecoveryOpen( false ) }
				onAccess={ acceptRecoveredReceipt }
			/>
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
			{ heading }
			<BlockShellInner className="ec-panel ec-booking-inquiry__panel">
				<form
					className="ec-booking-inquiry__form"
					onSubmit={ submit }
					noValidate={ false }
				>
					<section className="ec-booking-inquiry__step">
						<div className="ec-booking-inquiry__preflight">
							<strong>Send a concise booking pitch</strong>
							<p>
								Plan for a few minutes to share your project,
								requested date, contact details, and the
								configured event information. After submission,
								the venue reviews it and follows up directly.
							</p>
							{ config.fields.some(
								( field ) => field.required
							) && (
								<p>
									Have ready:{ ' ' }
									{ config.fields
										.filter( ( field ) => field.required )
										.map( ( field ) => field.label )
										.join( ', ' ) }
								</p>
							) }
							{ config.presentation.message_help && (
								<p>{ config.presentation.message_help }</p>
							) }
							<div className="ec-booking-inquiry__draft-controls">
								<p>
									As you type, these details are saved on this
									device for reload and Back recovery. By
									default, they stay only in this browser tab
									and are removed when you close it.
								</p>
								<label
									className="ec-checkbox-row"
									htmlFor={ `${ prefix }-persist-draft` }
								>
									<input
										id={ `${ prefix }-persist-draft` }
										type="checkbox"
										checked={
											draftScope === DRAFT_SCOPE_DEVICE
										}
										onChange={ ( event ) =>
											changeDraftScope(
												event.target.checked
											)
										}
									/>{ ' ' }
									<span>
										Keep this draft on this device for 24
										hours
									</span>
								</label>
								<p>
									On a shared device, leave that option off
									and clear the draft when finished.
								</p>
								<button
									type="button"
									className="button-2"
									onClick={ clearSavedDraft }
								>
									Clear saved draft
								</button>
								<button
									type="button"
									className="button-2"
									onClick={ () => {
										parkTurnstile();
										setRecoveryOpen( true );
									} }
								>
									Recover an existing inquiry
								</button>
								<div
									className="ec-booking-inquiry__draft-status"
									role="status"
									aria-live="polite"
								>
									{ draftStatus }
								</div>
							</div>
						</div>
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
								{ visibleFields.filter(
									( field ) => field.required
								).length > 0 && (
									<section className="ec-booking-inquiry__section">
										<h3>Event details</h3>
										<Grid
											minColumnWidth="16rem"
											maxColumns={ 2 }
										>
											{ visibleFields.map(
												( field ) =>
													field.required && (
														<Field
															key={ field.key }
															field={ field }
															value={
																values.fields[
																	field.key
																]
															}
															prefix={ prefix }
															onChange={ (
																value
															) =>
																updateField(
																	field.key,
																	value
																)
															}
														/>
													)
											) }
										</Grid>
									</section>
								) }
								{ visibleFields.filter(
									( field ) => ! field.required
								).length > 0 && (
									<details className="ec-booking-inquiry__optional-fields">
										<summary>
											Optional links and details
										</summary>
										<Grid
											minColumnWidth="16rem"
											maxColumns={ 2 }
										>
											{ visibleFields.map(
												( field ) =>
													! field.required && (
														<Field
															key={ field.key }
															field={ field }
															value={
																values.fields[
																	field.key
																]
															}
															prefix={ prefix }
															onChange={ (
																value
															) =>
																updateField(
																	field.key,
																	value
																)
															}
														/>
													)
											) }
										</Grid>
									</details>
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
