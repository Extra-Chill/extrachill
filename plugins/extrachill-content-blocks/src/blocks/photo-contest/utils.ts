export function isValidEmail( email: string ): boolean {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
}

/**
 * Fisher–Yates shuffle (new array). Fresh order on every page load.
 * @param items
 */
export function shuffle< T >( items: T[] ): T[] {
	const copy = [ ...items ];
	for ( let i = copy.length - 1; i > 0; i-- ) {
		const j = Math.floor( Math.random() * ( i + 1 ) );
		[ copy[ i ], copy[ j ] ] = [ copy[ j ], copy[ i ] ];
	}
	return copy;
}

