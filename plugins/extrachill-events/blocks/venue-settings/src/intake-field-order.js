export const hasValidFieldOrder = ( fields ) =>
	fields.every( ( field, index ) => {
		if ( ! field.visible_when ) {
			return true;
		}
		return fields
			.slice( 0, index )
			.some(
				( candidate ) => candidate.key === field.visible_when.field
			);
	} );
