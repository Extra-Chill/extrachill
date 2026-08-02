export const HOLD_TTL_MAX_MINUTES = 20160;

export const editableConfig = ( config ) => {
	const editable = { ...config };
	delete editable.revision;
	delete editable.updated_at;
	delete editable.updated_by_user_id;
	return editable;
};

export const sameDocument = ( left, right ) =>
	JSON.stringify( left ) === JSON.stringify( right );

export const profileChanges = ( current, baseline ) =>
	Object.fromEntries(
		Object.keys( baseline )
			.filter( ( key ) => ! [ 'term_id', 'revision' ].includes( key ) )
			.filter( ( key ) => current[ key ] !== baseline[ key ] )
			.map( ( key ) => [ key, current[ key ] ] )
	);

export const normalizeKey = ( value ) =>
	value
		.toLowerCase()
		.trim()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' )
		.slice( 0, 64 );

export const validateConfig = ( config ) => {
	const errors = [];
	if ( config.public_requirements.length > 20 ) {
		errors.push( 'Use no more than 20 public requirements.' );
	}
	if ( ! config.consent.label.trim() || config.consent.version < 1 ) {
		errors.push( 'Consent needs public wording and a positive version.' );
	}
	if ( config.booking_guide.entries.length > 50 ) {
		errors.push( 'Use no more than 50 booking guide entries.' );
	}
	const guideKeys = new Set();
	config.booking_guide.entries.forEach( ( entry ) => {
		if ( ! entry.key || ! entry.title.trim() || ! entry.body.trim() ) {
			errors.push( 'Each guide entry needs a key, title, and answer.' );
		}
		if ( guideKeys.has( entry.key ) ) {
			errors.push( 'Guide entry keys must be unique.' );
		}
		guideKeys.add( entry.key );
		if ( ! [ 'public', 'operator' ].includes( entry.visibility ) ) {
			errors.push( 'Guide visibility must be public or operators only.' );
		}
	} );
	const spaceKeys = new Set();
	let defaultSpaces = 0;
	config.spaces.forEach( ( space ) => {
		if ( ! space.key || ! space.name ) {
			errors.push( 'Each space needs a name and key.' );
		}
		if ( spaceKeys.has( space.key ) ) {
			errors.push( 'Space keys must be unique.' );
		}
		spaceKeys.add( space.key );
		if ( space.is_default ) {
			defaultSpaces += 1;
		}
	} );
	if ( config.spaces.length && defaultSpaces !== 1 ) {
		errors.push( 'Choose one default space.' );
	}

	const fieldKeys = new Set();
	config.intake.fields.forEach( ( field, index ) => {
		if ( ! field.key || ! field.label ) {
			errors.push( 'Each intake field needs a label and key.' );
		}
		if ( fieldKeys.has( field.key ) ) {
			errors.push( 'Intake field keys must be unique.' );
		}
		fieldKeys.add( field.key );
		if ( field.type === 'select' && ! field.options.length ) {
			errors.push( 'Select fields need at least one option.' );
		}
		if (
			field.visible_when &&
			( ! field.visible_when.value ||
				! config.intake.fields
					.slice( 0, index )
					.some(
						( candidate ) =>
							candidate.key === field.visible_when.field
					) )
		) {
			errors.push(
				'Conditional fields need an earlier field and matching value.'
			);
		}
	} );
	if (
		config.hold_ttl_minutes < 5 ||
		config.hold_ttl_minutes > HOLD_TTL_MAX_MINUTES
	) {
		errors.push( 'Hold duration must be between 5 minutes and 14 days.' );
	}
	if ( ! Number.isFinite( config.hold_ttl_minutes ) ) {
		errors.push( 'Hold duration must be a number.' );
	}
	if (
		! Number.isFinite( config.default_deal.guarantee_cents ) ||
		config.default_deal.guarantee_cents < 0
	) {
		errors.push( 'Guarantee must be zero or greater.' );
	}
	if ( ! /^[A-Z]{3}$/.test( config.default_deal.currency ) ) {
		errors.push( 'Currency must use a three-letter code.' );
	}
	if (
		! Number.isFinite( config.default_deal.revenue_share_basis_points ) ||
		config.default_deal.revenue_share_basis_points < 0 ||
		config.default_deal.revenue_share_basis_points > 10000
	) {
		errors.push( 'Revenue share must be between 0 and 100 percent.' );
	}
	return [ ...new Set( errors ) ];
};
