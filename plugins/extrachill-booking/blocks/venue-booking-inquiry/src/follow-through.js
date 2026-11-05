/* A receipt is deliberately scoped to one tab and contains no inquiry details. */
export const receiptStorageKey = ( config ) =>
	`extrachill.booking-inquiry.receipt.v1.${ config.venue.id }`;

const browserSessionStorage = () => {
	try {
		return window.sessionStorage;
	} catch {
		return null;
	}
};

const isUuid = ( value ) =>
	/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
		String( value || '' )
	);
const isCapability = ( value ) => /^[a-f0-9]{64}$/.test( value );

export const boundedReceipt = ( config, value ) => {
	if (
		! value ||
		! isUuid( value.public_id ) ||
		Number( value.venue_term_id ) !== Number( config.venue.id )
	) {
		return null;
	}
	const receipt = {
		public_id: value.public_id,
		venue_term_id: Number( value.venue_term_id ),
	};
	if ( typeof value.capability !== 'undefined' ) {
		if ( ! isCapability( value.capability ) ) {
			return null;
		}
		receipt.capability = value.capability;
	}
	return receipt;
};

export const saveReceipt = (
	config,
	value,
	storage = browserSessionStorage()
) => {
	const receipt = boundedReceipt( config, value );
	if ( ! receipt || ! storage ) {
		return false;
	}
	try {
		storage.setItem(
			receiptStorageKey( config ),
			JSON.stringify( receipt )
		);
		return true;
	} catch {
		return false;
	}
};

export const loadReceipt = ( config, storage = browserSessionStorage() ) => {
	if ( ! storage ) {
		return null;
	}
	try {
		const key = receiptStorageKey( config );
		const raw = storage.getItem( key );
		if ( ! raw ) {
			return null;
		}
		const receipt = boundedReceipt( config, JSON.parse( raw ) );
		if ( ! receipt ) {
			storage.removeItem( key );
		}
		return receipt;
	} catch {
		return null;
	}
};

export const clearReceipt = ( config, storage = browserSessionStorage() ) => {
	try {
		if ( ! storage ) {
			return false;
		}
		storage.removeItem( receiptStorageKey( config ) );
		return true;
	} catch {
		return false;
	}
};

export const accessPayload = ( receipt ) => ( {
	public_id: receipt.public_id,
	...( receipt.capability ? { capability: receipt.capability } : {} ),
} );

export const mutationPayload = (
	receipt,
	version,
	idempotencyKey,
	extra = {}
) => ( {
	...accessPayload( receipt ),
	expected_version: version,
	idempotency_key: idempotencyKey,
	...extra,
} );

export const recoveryPayload = (
	publicId,
	contactEmail,
	idempotencyKey,
	turnstileResponse
) => ( {
	public_id: publicId.trim(),
	contact_email: contactEmail.trim(),
	idempotency_key: idempotencyKey,
	turnstile_response: turnstileResponse,
} );

export const followThroughError = ( response, payload ) => {
	const code = String( payload?.code || '' );
	if (
		response.status === 401 ||
		response.status === 403 ||
		code === 'booking_inquiry_forbidden' ||
		( response.status === 404 && code === 'booking_inquiry_unavailable' )
	) {
		return {
			authorityLost: true,
			message:
				'This inquiry cannot be opened with the saved access. Request a new confirmation email to recover it.',
		};
	}
	if ( code === 'booking_version_conflict' ) {
		return {
			stale: true,
			message:
				'The inquiry changed while you were working. Its latest status is being loaded before you try again.',
		};
	}
	if ( response.status === 429 ) {
		return {
			message:
				'Too many requests were made. Wait a moment and try again.',
		};
	}
	if ( code === 'turnstile_failed' || code === 'turnstile_missing_token' ) {
		return {
			challenge: true,
			message:
				'The security check expired or failed. Complete the refreshed challenge and try again.',
		};
	}
	return {
		message:
			response.status >= 500
				? 'The inquiry service is temporarily unavailable. Try again.'
				: 'This request could not be completed. Refresh the status and try again.',
	};
};

export const recoveryError = ( response, payload ) => {
	const code = String( payload?.code || '' );
	if ( code === 'turnstile_failed' || code === 'turnstile_missing_token' ) {
		return {
			tone: 'warning',
			message:
				'The security check expired or failed. Complete the refreshed challenge and try again.',
		};
	}
	if ( response.status === 429 ) {
		return {
			tone: 'warning',
			message:
				'Too many recovery requests were made. Wait a moment and try again.',
		};
	}
	return {
		tone: 'error',
		message:
			'The recovery service is temporarily unavailable. No match information was disclosed; retry this request shortly.',
	};
};
