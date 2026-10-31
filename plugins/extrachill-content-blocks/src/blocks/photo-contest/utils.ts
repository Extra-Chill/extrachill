import type { Entry } from './types';

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

/**
 * Pick the top N visible entries that have at least one vote.
 * @param entries Entries.
 * @param limit   Max entries.
 */
export function selectHotEntries<
	T extends Pick< Entry, 'id' | 'hidden' | 'vote_count' >,
>( entries: T[], limit = 3 ): T[] {
	return entries
		.filter( ( e ) => ! e.hidden && e.vote_count > 0 )
		.sort( ( a, b ) =>
			b.vote_count !== a.vote_count
				? b.vote_count - a.vote_count
				: a.id - b.id
		)
		.slice( 0, limit );
}
