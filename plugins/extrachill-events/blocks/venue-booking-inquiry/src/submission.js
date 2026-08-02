export const newIdempotencyKey = () => {
	if ( window.crypto?.randomUUID ) {
		return window.crypto.randomUUID();
	}
	return `booking-${ Date.now() }-${ Math.random()
		.toString( 36 )
		.slice( 2 ) }`;
};

export const apiDate = ( value ) => {
	if ( ! value ) {
		return null;
	}
	return `${ value.replace( 'T', ' ' ) }${
		value.length === 16 ? ':00' : ''
	}`;
};

export const buildPayload = ( config, values, idempotencyKey, token ) => ( {
	venue: config.venue.id,
	idempotency_key: idempotencyKey,
	artist_name: values.artistName,
	contact_name: values.contactName || null,
	contact_email: values.contactEmail || null,
	contact_phone: values.contactPhone || null,
	requested_space_key: values.spaceKey || null,
	requested_start_at: apiDate( values.startAt ),
	requested_end_at: apiDate( values.endAt ),
	intake: {
		config_revision: config.revision,
		message: values.message,
		fields: values.fields,
		consent: {
			id: config.consent.id,
			version: config.consent.version,
			accepted: values.consent,
		},
	},
	turnstile_response: token,
} );

export const buildAvailabilityPayload = ( config, values ) => ( {
	venue: config.venue.id,
	requested_space_key: values.spaceKey,
	requested_start_at: apiDate( values.startAt ),
	requested_end_at: apiDate( values.endAt ),
} );

export const availabilityErrorState = ( response, payload ) => {
	if (
		response.status === 429 ||
		payload?.code === 'public_read_rate_limited'
	) {
		return {
			tone: 'warning',
			message:
				'Too many availability checks were made. Wait a moment and try again.',
		};
	}
	return {
		tone: response.status >= 500 ? 'error' : 'warning',
		message:
			payload?.message ||
			'Availability could not be checked. Review the time and try again.',
	};
};

export const errorState = ( response, payload ) => {
	const code = payload?.code || '';
	if ( response.status === 429 || code === 'public_write_rate_limited' ) {
		const seconds = Number( response.headers.get( 'Retry-After' ) || 60 );
		return {
			tone: 'warning',
			retryable: true,
			message: `Too many inquiries were sent. Try again in ${ seconds } seconds.`,
		};
	}
	if ( code === 'booking_inquiry_stale_config' ) {
		return {
			tone: 'warning',
			retryable: false,
			message:
				'This venue updated its booking form. Refresh the page before sending.',
		};
	}
	if ( code === 'booking_inquiry_interval_unavailable' ) {
		return {
			tone: 'warning',
			retryable: true,
			rotateKey: true,
			resetAvailability: true,
			message:
				payload?.message ||
				'That time filled while you completed the form. Choose another time and try again.',
		};
	}
	if ( code === 'booking_idempotency_conflict' ) {
		return {
			tone: 'warning',
			retryable: true,
			rotateKey: true,
			message:
				'These details changed after an earlier attempt. Review them and try once more.',
		};
	}
	if ( code === 'turnstile_failed' || code === 'turnstile_missing_token' ) {
		return {
			tone: 'warning',
			retryable: true,
			message:
				'The security check expired or failed. Complete the refreshed challenge and try again.',
		};
	}
	if ( code === 'booking_inquiry_reconciliation_required' ) {
		return {
			tone: 'error',
			retryable: false,
			message:
				'The venue received an uncertain result. Do not resend yet; contact the venue with the time of this attempt.',
		};
	}
	if ( code === 'booking_inquiry_unavailable' ) {
		return {
			tone: 'warning',
			retryable: response.status >= 500,
			message:
				payload?.message ||
				'Booking inquiries are temporarily unavailable.',
		};
	}
	return {
		tone: 'error',
		retryable: response.status >= 500,
		message:
			payload?.message ||
			'The inquiry could not be sent. Check the form and try again.',
	};
};
