const EMAIL_STORAGE_KEY = 'extrachill_voter_email';

export function getSavedEmail(): string {
	try {
		return localStorage.getItem( EMAIL_STORAGE_KEY ) || '';
	} catch {
		return '';
	}
}

export function saveEmail( email: string ): void {
	try {
		localStorage.setItem( EMAIL_STORAGE_KEY, email );
	} catch {
		// localStorage unavailable; ignore.
	}
}

export function entryStorageKey( contestId: string ): string {
	return `extrachill_pc_entry_${ contestId }`;
}

export function getSavedEntryId( contestId: string ): number | null {
	try {
		const saved = JSON.parse(
			localStorage.getItem( entryStorageKey( contestId ) ) || 'null'
		);
		return Number.isSafeInteger( saved?.entryId ) && saved.entryId > 0
			? saved.entryId
			: null;
	} catch {
		return null;
	}
}

export function saveEntryId( contestId: string, entryId: number | null ): void {
	try {
		if ( entryId === null ) {
			localStorage.removeItem( entryStorageKey( contestId ) );
		} else {
			localStorage.setItem(
				entryStorageKey( contestId ),
				JSON.stringify( { entryId } )
			);
		}
	} catch {
		// Storage is optional; the current session still remembers the entry.
	}
}

export function votedStorageKey( contestId: string ): string {
	return `extrachill_pc_voted_${ contestId }`;
}

export function getVotedEntries( contestId: string ): number[] {
	try {
		const raw = localStorage.getItem( votedStorageKey( contestId ) );
		return raw ? ( JSON.parse( raw ) as number[] ) : [];
	} catch {
		return [];
	}
}

export function rememberVote( contestId: string, entryId: number ): void {
	try {
		const voted = getVotedEntries( contestId );
		if ( ! voted.includes( entryId ) ) {
			voted.push( entryId );
			localStorage.setItem(
				votedStorageKey( contestId ),
				JSON.stringify( voted )
			);
		}
	} catch {
		// ignore.
	}
}

