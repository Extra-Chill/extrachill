export const newIdempotencyKey = () => {
	if ( window.crypto?.randomUUID ) {
		return window.crypto.randomUUID();
	}
	return `booking-${ Date.now() }-${ Math.random()
		.toString( 36 )
		.slice( 2 ) }`;
};

export const DRAFT_VERSION = 2;
export const DRAFT_TTL = 24 * 60 * 60 * 1000;
const DRAFT_MAX_BYTES = 12000;
export const DRAFT_SCOPE_SESSION = 'session';
export const DRAFT_SCOPE_DEVICE = 'device';

const browserStorage = () => ( {
	session: ( () => {
		try {
			return window.sessionStorage;
		} catch {
			return null;
		}
	} )(),
	device: ( () => {
		try {
			return window.localStorage;
		} catch {
			return null;
		}
	} )(),
} );

export const draftStorageKey = ( config ) =>
	`extrachill.booking-inquiry.v1.${ config.venue.id }.${ config.revision }`;

const draftValues = ( config, values ) => ( {
	artistName: String( values.artistName || '' ),
	contactName: String( values.contactName || '' ),
	contactEmail: String( values.contactEmail || '' ),
	contactPhone: String( values.contactPhone || '' ),
	spaceKey: String( values.spaceKey || '' ),
	requestedDate: String( values.requestedDate || '' ),
	message: String( values.message || '' ),
	fields: Object.fromEntries(
		( config.fields || [] ).map( ( field ) => [
			field.key,
			field.type === 'checkbox'
				? values.fields?.[ field.key ] === true
				: String( values.fields?.[ field.key ] || '' ),
		] )
	),
} );

const hasDraftValues = ( values ) =>
	[
		values.artistName,
		values.contactName,
		values.contactEmail,
		values.contactPhone,
		values.requestedDate,
		values.message,
	].some( Boolean ) ||
	Object.values( values.fields || {} ).some( ( value ) => Boolean( value ) );

const clearStaleDrafts = ( config, storage ) => {
	const prefix = `extrachill.booking-inquiry.v1.${ config.venue.id }.`;
	const current = draftStorageKey( config );
	for ( let index = storage.length - 1; index >= 0; index-- ) {
		const key = storage.key( index );
		if ( key?.startsWith( prefix ) && key !== current ) {
			storage.removeItem( key );
		}
	}
};

export const saveDraft = (
	config,
	values,
	scope = DRAFT_SCOPE_SESSION,
	storage = browserStorage(),
	now = Date.now()
) => {
	try {
		const selected = storage[ scope ];
		const other =
			storage[
				scope === DRAFT_SCOPE_DEVICE
					? DRAFT_SCOPE_SESSION
					: DRAFT_SCOPE_DEVICE
			];
		if ( ! selected ) {
			return false;
		}
		other?.removeItem( draftStorageKey( config ) );
		if ( ! hasDraftValues( values ) ) {
			selected.removeItem( draftStorageKey( config ) );
			return true;
		}
		const draft = JSON.stringify( {
			version: DRAFT_VERSION,
			venueId: config.venue.id,
			revision: config.revision,
			scope,
			savedAt: now,
			values: draftValues( config, values ),
		} );
		if ( draft.length <= DRAFT_MAX_BYTES ) {
			selected.setItem( draftStorageKey( config ), draft );
			return true;
		}
		return false;
	} catch {
		return false;
	}
};

export const loadDraft = (
	config,
	initial,
	storage = browserStorage(),
	now = Date.now()
) => {
	let sessionReadFailed = false;
	for ( const scope of [ DRAFT_SCOPE_SESSION, DRAFT_SCOPE_DEVICE ] ) {
		const selected = storage[ scope ];
		if ( ! selected ) {
			sessionReadFailed ||= scope === DRAFT_SCOPE_SESSION;
			continue;
		}
		try {
			clearStaleDrafts( config, selected );
			const raw = selected.getItem( draftStorageKey( config ) );
			if ( ! raw ) {
				continue;
			}
			const draft = JSON.parse( raw );
			const validSavedAt = Number.isFinite( draft.savedAt );
			const expired =
				validSavedAt &&
				( now - draft.savedAt > DRAFT_TTL || now < draft.savedAt );
			if (
				draft.version !== DRAFT_VERSION ||
				draft.venueId !== config.venue.id ||
				draft.revision !== config.revision ||
				draft.scope !== scope ||
				! validSavedAt ||
				! draft.values ||
				expired
			) {
				selected.removeItem( draftStorageKey( config ) );
				return {
					values: initial,
					scope: DRAFT_SCOPE_SESSION,
					outcome: expired ? 'expired' : 'incompatible',
				};
			}
			return {
				values: {
					...initial,
					...draft.values,
					consent: false,
					fields: {
						...initial.fields,
						...( draft.values.fields || {} ),
					},
				},
				scope,
				outcome: 'restored',
			};
		} catch {
			sessionReadFailed ||= scope === DRAFT_SCOPE_SESSION;
		}
	}
	return {
		values: initial,
		scope: DRAFT_SCOPE_SESSION,
		outcome: sessionReadFailed ? 'read-failed' : 'none',
	};
};

export const clearDraft = ( config, storage = browserStorage() ) => {
	let cleared = true;
	for ( const scope of [ DRAFT_SCOPE_SESSION, DRAFT_SCOPE_DEVICE ] ) {
		try {
			if ( ! storage[ scope ] ) {
				cleared = false;
				continue;
			}
			storage[ scope ].removeItem( draftStorageKey( config ) );
		} catch {
			cleared = false;
		}
	}
	return cleared;
};

export const bookingDateInterval = ( value ) => {
	if ( ! value ) {
		return { start: null, end: null };
	}
	const [ year, month, day ] = value.split( '-' ).map( Number );
	const next = new Date( Date.UTC( year, month - 1, day + 1 ) );
	return {
		start: `${ value } 00:00:00`,
		end: `${ next.toISOString().slice( 0, 10 ) } 00:00:00`,
	};
};

export const buildPayload = ( config, values, idempotencyKey, token ) => {
	const interval = bookingDateInterval( values.requestedDate );
	return {
		venue: config.venue.id,
		idempotency_key: idempotencyKey,
		artist_name: values.artistName,
		contact_name: values.contactName || null,
		contact_email: values.contactEmail || null,
		contact_phone: values.contactPhone || null,
		requested_space_key: values.spaceKey || null,
		requested_start_at: interval.start,
		requested_end_at: interval.end,
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
	};
};

export const buildAvailabilityPayload = ( config, values ) => {
	const interval = bookingDateInterval( values.requestedDate );
	return {
		venue: config.venue.id,
		requested_space_key: values.spaceKey,
		requested_start_at: interval.start,
		requested_end_at: interval.end,
	};
};

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
			'Availability could not be checked. Review the date and try again.',
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
				'That date filled while you completed the form. Choose another date and try again.',
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
