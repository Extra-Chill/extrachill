/**
 * Subscriber export → CSV download.
 *
 * The export ability returns subscriber rows; the spreadsheet is built in the
 * browser. Each row carries the subscriber's personal unsubscribe link, which
 * artists include in the emails they send from their own mailing tool.
 */

export const SUBSCRIBER_CSV_COLUMNS = [
	'email',
	'username',
	'subscribed_at',
	'unsubscribe_url',
];

/**
 * Quote one CSV cell. Cells that a spreadsheet would run as a formula are
 * prefixed with an apostrophe.
 *
 * @param {string|number|boolean|null|undefined} value Cell value.
 * @return {string} Quoted cell.
 */
export const csvCell = ( value ) => {
	let text = value === null || value === undefined ? '' : String( value );
	if ( /^[=+\-@\t\r]/.test( text ) ) {
		text = `'${ text }`;
	}
	return `"${ text.replace( /"/g, '""' ) }"`;
};

/**
 * Build CSV text from export rows.
 *
 * @param {Array<Object>} subscribers Export rows.
 * @return {string} CSV document.
 */
export const buildSubscribersCsv = ( subscribers = [] ) => {
	const lines = [ SUBSCRIBER_CSV_COLUMNS.join( ',' ) ];
	subscribers.forEach( ( row ) => {
		lines.push(
			SUBSCRIBER_CSV_COLUMNS.map( ( column ) =>
				csvCell( row?.[ column ] )
			).join( ',' )
		);
	} );
	return `${ lines.join( '\r\n' ) }\r\n`;
};

/**
 * File name for an export.
 *
 * @param {Object} data Export response.
 * @return {string} File name.
 */
export const subscribersCsvFilename = ( data = {} ) => {
	const slug =
		String( data.artist_name || 'artist' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' ) || 'artist';
	const date = data.export_date || new Date().toISOString().slice( 0, 10 );
	return `${ slug }-subscribers-${ date }.csv`;
};

/**
 * Trigger a browser download of the export.
 *
 * @param {Object} data Export response with a `subscribers` array.
 * @return {number} Number of rows downloaded.
 */
export const downloadSubscribersCsv = ( data = {} ) => {
	const subscribers = Array.isArray( data.subscribers )
		? data.subscribers
		: [];
	const blob = new Blob( [ buildSubscribersCsv( subscribers ) ], {
		type: 'text/csv;charset=utf-8',
	} );
	const url = window.URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = subscribersCsvFilename( data );
	document.body.appendChild( link );
	link.click();
	link.remove();
	window.URL.revokeObjectURL( url );
	return subscribers.length;
};
