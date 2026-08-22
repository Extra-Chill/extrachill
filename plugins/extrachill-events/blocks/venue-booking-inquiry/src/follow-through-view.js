/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * External dependencies
 */
import {
	ActionRow,
	BlockShell,
	BlockShellInner,
	FieldGroup,
	InlineStatus,
	Panel,
} from '@extrachill/components';

/**
 * Internal dependencies
 */
import {
	accessPayload,
	clearReceipt,
	followThroughError,
	mutationPayload,
	recoveryError,
	recoveryPayload,
	saveReceipt,
} from './follow-through';
import { newIdempotencyKey } from './submission';

const requestHeaders = ( config ) => ( {
	'Content-Type': 'application/json',
	...( config.restNonce ? { 'X-WP-Nonce': config.restNonce } : {} ),
} );

const post = async ( config, endpoint, body ) => {
	const response = await fetch( endpoint, {
		method: 'POST',
		credentials: 'same-origin',
		headers: requestHeaders( config ),
		body: JSON.stringify( body ),
	} );
	return {
		response,
		payload: await response.json().catch( () => ( {} ) ),
	};
};

const turnstileToken = ( wrapper ) =>
	wrapper
		?.querySelector( 'input[name="cf-turnstile-response"]' )
		?.value?.trim() || '';

const resetTurnstile = ( wrapper ) => {
	const widget = wrapper?.querySelector( '.cf-turnstile' );
	if ( widget ) {
		widget.replaceChildren();
		widget.removeAttribute( 'data-ec-turnstile-rendered' );
		window.ecTurnstileBoot?.();
	}
};

export function ReceiptRecovery( {
	config,
	wrapper,
	initialReference = '',
	onCancel,
	onAccess,
} ) {
	const [ reference, setReference ] = useState( initialReference );
	const [ email, setEmail ] = useState( '' );
	const [ accessCode, setAccessCode ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ opening, setOpening ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const target = useRef();
	const result = useRef();
	const operationKey = useRef( null );

	useEffect( () => {
		const source = wrapper?.querySelector( '[data-booking-turnstile]' );
		if ( source && target.current ) {
			target.current.appendChild( source );
		}
		return () => {
			if ( source && wrapper?.isConnected ) {
				wrapper.appendChild( source );
			}
		};
	}, [ wrapper ] );
	useEffect( () => {
		if ( notice ) {
			result.current?.focus();
		}
	}, [ notice ] );

	const submit = async ( event ) => {
		event.preventDefault();
		if ( sending || ! event.currentTarget.reportValidity() ) {
			return;
		}
		const token = turnstileToken( wrapper );
		if ( ! token ) {
			setNotice( {
				tone: 'warning',
				message: 'Complete the security check before requesting email.',
			} );
			return;
		}
		operationKey.current ||= newIdempotencyKey();
		setSending( true );
		setNotice( {
			tone: 'info',
			message: 'Requesting confirmation email...',
		} );
		try {
			const { response, payload } = await post(
				config,
				config.followThrough.receiptRecovery,
				recoveryPayload( reference, email, operationKey.current, token )
			);
			resetTurnstile( wrapper );
			if ( ! response.ok || response.status !== 202 ) {
				setNotice( recoveryError( response, payload ) );
				return;
			}
			operationKey.current = null;
			setEmail( '' );
			setNotice( {
				tone: 'success',
				message:
					'If those details match an inquiry, an access-recovery email containing the private access code will arrive shortly. Check spam too.',
			} );
		} catch {
			resetTurnstile( wrapper );
			setNotice( {
				tone: 'error',
				message:
					'The request was interrupted. Try again; the same safe request can be retried.',
			} );
		} finally {
			setSending( false );
		}
	};
	const openWithAccessCode = async () => {
		const capability = accessCode.trim().toLowerCase();
		const authenticated = Boolean( config.restNonce );
		const candidate = {
			public_id: reference.trim(),
			venue_term_id: config.venue.id,
			...( authenticated ? {} : { capability } ),
		};
		if ( ! authenticated && ! /^[a-f0-9]{64}$/.test( capability ) ) {
			setNotice( {
				tone: 'warning',
				message:
					'Enter the complete 64-character access code from the email.',
			} );
			return;
		}
		setOpening( true );
		setNotice( { tone: 'info', message: 'Opening inquiry...' } );
		try {
			const { response, payload } = await post(
				config,
				config.followThrough.status,
				accessPayload( candidate )
			);
			if ( ! response.ok ) {
				setNotice( {
					tone: 'error',
					...followThroughError( response, payload ),
				} );
				return;
			}
			if ( ! saveReceipt( config, candidate ) ) {
				setNotice( {
					tone: 'warning',
					message:
						'The inquiry opened, but this tab could not save access. Keep the email available.',
				} );
			}
			setAccessCode( '' );
			onAccess?.( candidate, payload );
		} catch {
			setNotice( {
				tone: 'error',
				message: 'The access check was interrupted. Try again.',
			} );
		} finally {
			setOpening( false );
		}
	};

	return (
		<BlockShell>
			<BlockShellInner className="ec-panel ec-booking-inquiry__panel">
				<form
					className="ec-booking-inquiry__recovery"
					onSubmit={ submit }
				>
					<h3>Recover your inquiry access</h3>
					<FieldGroup
						label="Confirmation reference"
						htmlFor={ `${ config.instanceId }-recovery-reference` }
						required
					>
						<input
							id={ `${ config.instanceId }-recovery-reference` }
							value={ reference }
							required
							autoComplete="off"
							onChange={ ( event ) =>
								setReference( event.target.value )
							}
						/>
					</FieldGroup>
					<section className="ec-booking-inquiry__access-code">
						<h4>
							{ config.restNonce
								? 'Open with your signed-in account'
								: 'Have an access code?' }
						</h4>
						<p>
							{ config.restNonce
								? 'Enter the reference. Your signed-in account authorizes the private status request.'
								: 'Enter the reference and private access code from the email. They are sent only in the request body, never in the URL.' }
						</p>
						{ ! config.restNonce && (
							<FieldGroup
								label="Access code"
								htmlFor={ `${ config.instanceId }-recovery-access-code` }
							>
								<input
									id={ `${ config.instanceId }-recovery-access-code` }
									value={ accessCode }
									autoComplete="off"
									maxLength="64"
									onChange={ ( event ) =>
										setAccessCode( event.target.value )
									}
								/>
							</FieldGroup>
						) }
						<button
							className="button-1"
							type="button"
							disabled={ opening }
							onClick={ openWithAccessCode }
						>
							{ opening ? 'Opening...' : 'Open inquiry' }
						</button>
					</section>
					<h4>Need a new access email?</h4>
					<p>
						Enter the confirmation reference and contact email used
						for the inquiry. For privacy, the result is the same
						whether or not they match.
					</p>
					<FieldGroup
						label="Contact email"
						htmlFor={ `${ config.instanceId }-recovery-email` }
						required
					>
						<input
							id={ `${ config.instanceId }-recovery-email` }
							type="email"
							value={ email }
							required
							autoComplete="email"
							onChange={ ( event ) =>
								setEmail( event.target.value )
							}
						/>
					</FieldGroup>
					<p className="ec-booking-inquiry__privacy">
						The email contains the access code. Keep it and the
						reference private. The email address is not saved in
						this browser.
					</p>
					<div
						className="ec-booking-inquiry__turnstile"
						ref={ target }
					/>
					<div
						ref={ result }
						tabIndex="-1"
						role="status"
						aria-live="polite"
					>
						{ notice && (
							<InlineStatus tone={ notice.tone }>
								{ notice.message }
							</InlineStatus>
						) }
					</div>
					<ActionRow>
						<button
							className="button-1"
							type="submit"
							disabled={ sending }
						>
							{ sending
								? 'Requesting...'
								: 'Email my access code' }
						</button>
						{ onCancel && (
							<button
								className="button-2"
								type="button"
								onClick={ onCancel }
							>
								Back to booking form
							</button>
						) }
					</ActionRow>
				</form>
			</BlockShellInner>
		</BlockShell>
	);
}

export function BookingFollowThrough( {
	config,
	receipt,
	wrapper,
	onClear,
	onReceipt,
} ) {
	const [ inquiry, setInquiry ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ correctionOpen, setCorrectionOpen ] = useState( false );
	const [ correction, setCorrection ] = useState( '' );
	const [ recovering, setRecovering ] = useState( false );
	const [ actionsFresh, setActionsFresh ] = useState( false );
	const [ confirmAction, setConfirmAction ] = useState( null );
	const result = useRef();
	const turnstileTarget = useRef();
	const operation = useRef( null );

	const readStatus = async ( announce = true ) => {
		setLoading( true );
		if ( announce ) {
			setNotice( {
				tone: 'info',
				message: 'Refreshing inquiry status...',
			} );
		}
		try {
			const { response, payload } = await post(
				config,
				config.followThrough.status,
				accessPayload( receipt )
			);
			if ( ! response.ok ) {
				setActionsFresh( false );
				const error = followThroughError( response, payload );
				if ( error.authorityLost ) {
					clearReceipt( config );
				}
				setNotice( { tone: 'error', ...error } );
				return false;
			}
			setInquiry( payload );
			setActionsFresh( true );
			setConfirmAction( null );
			setNotice(
				announce
					? { tone: 'success', message: 'Inquiry status refreshed.' }
					: null
			);
			return true;
		} catch {
			setActionsFresh( false );
			setNotice( {
				tone: 'error',
				message: 'The status check was interrupted. Try again.',
			} );
			return false;
		} finally {
			setLoading( false );
		}
	};

	useEffect( () => {
		void readStatus( false );
		// The immutable receipt defines this component's access session.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );
	useEffect( () => {
		if ( notice ) {
			result.current?.focus();
		}
	}, [ notice ] );
	useEffect( () => {
		if ( recovering ) {
			return;
		}
		const source = wrapper?.querySelector( '[data-booking-turnstile]' );
		if ( source && turnstileTarget.current ) {
			turnstileTarget.current.appendChild( source );
		}
		return () => {
			if ( source && wrapper?.isConnected ) {
				wrapper.appendChild( source );
			}
		};
	}, [ inquiry?.status, recovering, wrapper ] );

	const mutate = async ( action ) => {
		if ( ! inquiry || loading || ! actionsFresh ) {
			return;
		}
		const isCorrection = action === 'request_correction';
		if ( isCorrection && ! correction.trim() ) {
			setNotice( {
				tone: 'warning',
				message: 'Describe the correction first.',
			} );
			return;
		}
		const token = turnstileToken( wrapper );
		if ( ! token ) {
			setNotice( {
				tone: 'warning',
				message:
					'Complete the security check before sending this request.',
			} );
			return;
		}
		if ( operation.current?.action !== action ) {
			operation.current = null;
		}
		operation.current ||= {
			action,
			key: newIdempotencyKey(),
			version: inquiry.version,
		};
		setLoading( true );
		setNotice( { tone: 'info', message: 'Sending your request...' } );
		try {
			const endpoint = isCorrection
				? config.followThrough.correction
				: config.followThrough.withdrawal;
			const { response, payload } = await post(
				config,
				endpoint,
				mutationPayload(
					receipt,
					operation.current.version,
					operation.current.key,
					{
						...( isCorrection
							? { correction: correction.trim() }
							: {} ),
						turnstile_response: token,
					}
				)
			);
			resetTurnstile( wrapper );
			if ( ! response.ok ) {
				const error = followThroughError( response, payload );
				if ( error.authorityLost ) {
					clearReceipt( config );
				}
				if ( error.stale ) {
					setActionsFresh( false );
					operation.current = null;
					const staleNotice = { tone: 'warning', ...error };
					setNotice( staleNotice );
					if ( await readStatus( false ) ) {
						setNotice( {
							...staleNotice,
							message:
								'The inquiry changed while you were working. Review the refreshed status before trying again.',
						} );
					}
				} else {
					setNotice( { tone: 'error', ...error } );
				}
				return;
			}
			operation.current = null;
			setCorrection( '' );
			setCorrectionOpen( false );
			setConfirmAction( null );
			let message = 'Your correction request was sent to the venue.';
			if ( payload.operation === 'withdrawn' ) {
				message = 'Your inquiry was withdrawn.';
			} else if ( payload.operation === 'cancellation_requested' ) {
				message = 'Your cancellation request was sent to the venue.';
			}
			const successNotice = {
				tone: 'success',
				message,
			};
			if ( await readStatus( false ) ) {
				setNotice( successNotice );
			}
		} catch {
			resetTurnstile( wrapper );
			setNotice( {
				tone: 'error',
				message:
					'The request was interrupted. Retry to safely continue the same request.',
			} );
		} finally {
			setLoading( false );
		}
	};

	if ( recovering ) {
		return (
			<ReceiptRecovery
				config={ config }
				wrapper={ wrapper }
				initialReference={ receipt.public_id }
				onCancel={ () => setRecovering( false ) }
				onAccess={ onReceipt }
			/>
		);
	}

	const permitted = inquiry?.permitted_actions || [];
	const actionDisabled = loading || ! actionsFresh;
	return (
		<BlockShell>
			<BlockShellInner>
				<Panel>
					<div
						className="ec-booking-inquiry__result"
						ref={ result }
						tabIndex="-1"
						role="status"
						aria-live="polite"
					>
						<span className="taxonomy-badge">Inquiry received</span>
						<h3>Booking inquiry for { config.venue.name }</h3>
						<p>
							A confirmation email should arrive within a few
							minutes. Check spam if it does not. Venue response
							timing varies.
						</p>
						<p>
							Keep this reference
							{ receipt.capability
								? ' and access code'
								: '' }{ ' ' }
							private:
						</p>
						<strong className="ec-booking-inquiry__reference">
							{ receipt.public_id }
						</strong>
						{ inquiry && (
							<div className="ec-booking-inquiry__status-card">
								<span>Status</span>
								<strong>{ inquiry.status_label }</strong>
								{ inquiry.requested_interval?.start_at && (
									<span>
										Requested:{ ' ' }
										{ inquiry.requested_interval.start_at }
									</span>
								) }
								<span>
									Space: { inquiry.requested_space?.label }
								</span>
							</div>
						) }
						{ notice && (
							<InlineStatus tone={ notice.tone }>
								{ notice.message }
							</InlineStatus>
						) }
					</div>
					{ correctionOpen &&
						permitted.includes( 'request_correction' ) && (
							<FieldGroup
								label="Correction for venue review"
								htmlFor={ `${ config.instanceId }-correction` }
								help="Describe only what needs to change. This sends a request; it does not silently rewrite the inquiry."
								required
							>
								<textarea
									id={ `${ config.instanceId }-correction` }
									rows="4"
									maxLength="2000"
									value={ correction }
									required
									onChange={ ( event ) =>
										setCorrection( event.target.value )
									}
								/>
							</FieldGroup>
						) }
					{ permitted.includes( 'withdraw' ) && (
						<p className="ec-booking-inquiry__action-help">
							Withdrawal ends this pending inquiry immediately.
							You will be asked to confirm before it is withdrawn.
						</p>
					) }
					{ permitted.includes( 'request_cancellation' ) && (
						<p className="ec-booking-inquiry__action-help">
							A cancellation request asks the venue to review
							cancellation; it does not immediately cancel a
							confirmed booking.
						</p>
					) }
					{ permitted.length > 0 && (
						<div
							className="ec-booking-inquiry__turnstile"
							ref={ turnstileTarget }
						/>
					) }
					<ActionRow>
						<button
							className="button-2"
							type="button"
							disabled={ loading }
							onClick={ () => readStatus() }
						>
							{ loading ? 'Working...' : 'Refresh status' }
						</button>
						{ permitted.includes( 'request_correction' ) && (
							<button
								className="button-2"
								type="button"
								disabled={ actionDisabled }
								onClick={ () =>
									correctionOpen
										? mutate( 'request_correction' )
										: setCorrectionOpen( true )
								}
							>
								{ correctionOpen
									? 'Send correction request'
									: 'Request correction' }
							</button>
						) }
						{ permitted.includes( 'withdraw' ) && (
							<button
								className="button-2"
								type="button"
								disabled={ actionDisabled }
								onClick={ () => {
									if ( confirmAction === 'withdraw' ) {
										mutate( 'withdraw' );
									} else {
										setConfirmAction( 'withdraw' );
										setNotice( {
											tone: 'warning',
											message:
												'Confirm withdrawal to end this inquiry immediately.',
										} );
									}
								} }
							>
								{ confirmAction === 'withdraw'
									? 'Confirm withdrawal'
									: 'Withdraw inquiry' }
							</button>
						) }
						{ permitted.includes( 'request_cancellation' ) && (
							<button
								className="button-2"
								type="button"
								disabled={ actionDisabled }
								onClick={ () =>
									mutate( 'request_cancellation' )
								}
							>
								Request cancellation
							</button>
						) }
						{ receipt.capability && (
							<button
								className="button-2"
								type="button"
								onClick={ () => setRecovering( true ) }
							>
								Request access email
							</button>
						) }
						<button
							className="button-2"
							type="button"
							onClick={ onClear }
						>
							Clear this receipt
						</button>
					</ActionRow>
				</Panel>
			</BlockShellInner>
		</BlockShell>
	);
}
