/**
 * Shared venue-settings state helpers.
 */

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
