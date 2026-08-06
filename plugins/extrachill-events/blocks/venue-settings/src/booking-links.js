export const isLinkField = ( field ) =>
	[ 'url', 'url_list' ].includes( field.type );

export const logicalIntakeRows = ( fields ) => {
	const links = fields.filter( isLinkField );
	const rows = fields
		.map( ( field, index ) => ( { field, index } ) )
		.filter( ( row ) => ! isLinkField( row.field ) );

	if ( links.length ) {
		rows.push( { links, index: fields.findIndex( isLinkField ) } );
		rows.sort( ( left, right ) => left.index - right.index );
	}

	return rows;
};

export const updateLogicalLinkFields = ( fields, patch ) => {
	let primaryUpdated = false;
	return fields.map( ( field ) => {
		if ( ! isLinkField( field ) ) {
			return field;
		}
		if ( ! primaryUpdated ) {
			primaryUpdated = true;
			return { ...field, ...patch, type: 'url_list' };
		}
		return Object.prototype.hasOwnProperty.call( patch, 'required' )
			? { ...field, required: false }
			: field;
	} );
};

export const linkCollectionValue = ( fields, values ) =>
	[
		...new Set(
			fields.flatMap( ( field ) =>
				String( values[ field.key ] || '' )
					.split( /\r?\n/ )
					.map( ( value ) => value.trim() )
					.filter( Boolean )
			)
		),
	].join( '\n' );

export const updateLinkCollection = ( fields, values, value ) => {
	const links = value
		.split( /\r?\n/ )
		.map( ( link ) => link.trim() )
		.filter( Boolean );
	const requiredFallback = links.slice( 0, 20 ).join( '\n' );
	return {
		...values,
		...Object.fromEntries(
			fields.map( ( field ) => {
				if ( field.type === 'url' ) {
					return [ field.key, links.shift() || '' ];
				}
				const assigned = links.splice( 0, 20 ).join( '\n' );
				return [
					field.key,
					assigned || ( field.required ? requiredFallback : '' ),
				];
			} )
		),
	};
};
