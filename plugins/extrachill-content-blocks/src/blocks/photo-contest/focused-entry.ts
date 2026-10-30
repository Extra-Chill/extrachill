/** Select only a visible entry with an exact positive integer deep-link id. */
export function selectFocusedEntry<
	T extends { id: number; hidden?: boolean },
>( entries: T[], search: string ): T | null {
	const value = new URLSearchParams( search ).get( 'entry' );
	if ( ! value || ! /^[1-9]\d*$/.test( value ) ) {
		return null;
	}
	const id = Number( value );
	if ( ! Number.isSafeInteger( id ) ) {
		return null;
	}
	return (
		entries.find( ( entry ) => entry.id === id && ! entry.hidden ) ?? null
	);
}
