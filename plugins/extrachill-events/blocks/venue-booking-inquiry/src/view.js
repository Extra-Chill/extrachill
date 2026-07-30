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
	Grid,
	InlineStatus,
	Panel,
	PanelHeader,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import { buildPayload, errorState, newIdempotencyKey } from './submission';

const initialValues = ( config ) => ( {
	artistName: '',
	contactName: '',
	contactEmail: '',
	contactPhone: '',
	spaceKey:
		config.spaces.find( ( space ) => space.is_default )?.key ||
		config.spaces[ 0 ]?.key ||
		'',
	startAt: '',
	endAt: '',
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
			<label className="ec-booking-inquiry__checkbox" htmlFor={ id }>
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

function BookingInquiry( { config, wrapper } ) {
	const [ values, setValues ] = useState( () => initialValues( config ) );
	const [ status, setStatus ] = useState( null );
	const [ submitting, setSubmitting ] = useState( false );
	const [ receipt, setReceipt ] = useState( null );
	const key = useRef( newIdempotencyKey() );
	const turnstileTarget = useRef();
	const resultRef = useRef();
	const prefix = config.instanceId;

	useEffect( () => {
		const source = wrapper.querySelector( '[data-booking-turnstile]' );
		if ( source && turnstileTarget.current ) {
			turnstileTarget.current.appendChild( source );
		}
	}, [ wrapper ] );
	useEffect( () => {
		if ( status || receipt ) {
			resultRef.current?.focus();
		}
	}, [ status, receipt ] );

	const update = ( patch ) => {
		key.current = newIdempotencyKey();
		setValues( ( current ) => ( { ...current, ...patch } ) );
		setStatus( null );
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
	const submit = async ( event ) => {
		event.preventDefault();
		if ( submitting || ! event.currentTarget.reportValidity() ) {
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
							<Badge tone="success">Inquiry received</Badge>
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

	return (
		<BlockShell>
			<BlockShellHeader
				title={ config.heading }
				description={ `Send a performance inquiry directly to ${ config.venue.name }.` }
			/>
			<BlockShellInner>
				<PanelHeader
					className="ec-booking-inquiry__identity"
					title={
						<>
							<Badge tone="success">Now booking</Badge>
							<span>{ config.venue.name }</span>
						</>
					}
					description={ config.venue.address }
					actions={
						config.authenticated ? (
							<Badge tone="info">Signed-in inquiry</Badge>
						) : null
					}
				/>
				{ config.venue.description && (
					<p className="ec-booking-inquiry__description">
						{ config.venue.description }
					</p>
				) }
				{ config.requirements.length > 0 && (
					<Panel>
						<h4>Before you reach out</h4>
						<ul className="ec-booking-inquiry__requirements">
							{ config.requirements.map( ( requirement ) => (
								<li key={ requirement }>{ requirement }</li>
							) ) }
						</ul>
					</Panel>
				) }
				<form
					className="ec-booking-inquiry__form"
					onSubmit={ submit }
					noValidate={ false }
				>
					<Grid minColumnWidth="16rem" maxColumns={ 2 }>
						<FieldGroup
							label="Artist or project name"
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
									update( { artistName: event.target.value } )
								}
							/>
						</FieldGroup>
						<FieldGroup
							label="Contact name"
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
										contactName: event.target.value,
									} )
								}
							/>
						</FieldGroup>
						<FieldGroup
							label="Contact email"
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
										contactEmail: event.target.value,
									} )
								}
							/>
						</FieldGroup>
						<FieldGroup
							label="Contact phone"
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
										contactPhone: event.target.value,
									} )
								}
							/>
						</FieldGroup>
						{ config.spaces.length > 0 && (
							<FieldGroup
								label="Requested space"
								htmlFor={ `${ prefix }-space` }
							>
								<select
									id={ `${ prefix }-space` }
									value={ values.spaceKey }
									onChange={ ( event ) =>
										update( {
											spaceKey: event.target.value,
										} )
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
							label="Requested start"
							htmlFor={ `${ prefix }-start` }
							required
						>
							<input
								id={ `${ prefix }-start` }
								type="datetime-local"
								value={ values.startAt }
								required
								onChange={ ( event ) =>
									update( { startAt: event.target.value } )
								}
							/>
						</FieldGroup>
						<FieldGroup
							label="Requested end"
							htmlFor={ `${ prefix }-end` }
							help="Optional if timing is flexible."
						>
							<input
								id={ `${ prefix }-end` }
								type="datetime-local"
								value={ values.endAt }
								min={ values.startAt }
								onChange={ ( event ) =>
									update( { endAt: event.target.value } )
								}
							/>
						</FieldGroup>
						{ config.fields.map( ( field ) => (
							<Field
								key={ field.key }
								field={ field }
								value={ values.fields[ field.key ] }
								prefix={ prefix }
								onChange={ ( value ) =>
									updateField( field.key, value )
								}
							/>
						) ) }
					</Grid>
					<FieldGroup
						label="Performance details"
						htmlFor={ `${ prefix }-message` }
						help="Share lineup, draw, routing, links, and anything the venue should know."
						required
					>
						<textarea
							id={ `${ prefix }-message` }
							rows="6"
							maxLength="10000"
							value={ values.message }
							required
							onChange={ ( event ) =>
								update( { message: event.target.value } )
							}
						/>
					</FieldGroup>
					<label
						className="ec-booking-inquiry__consent"
						htmlFor={ `${ prefix }-consent` }
					>
						<input
							id={ `${ prefix }-consent` }
							type="checkbox"
							required={ config.consent.required }
							checked={ values.consent }
							onChange={ ( event ) =>
								update( { consent: event.target.checked } )
							}
						/>{ ' ' }
						<span>
							{ config.consent.label }
							{ config.consent.required && (
								<span aria-hidden="true"> *</span>
							) }
						</span>
					</label>
					<div
						className="ec-booking-inquiry__turnstile"
						ref={ turnstileTarget }
					/>
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
							disabled={ submitting }
						>
							{ submitting ? 'Sending...' : config.buttonLabel }
						</button>
						<span className="ec-booking-inquiry__privacy">
							Your inquiry is sent privately and never added to
							the public venue page.
						</span>
					</ActionRow>
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
