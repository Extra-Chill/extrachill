/**
 * Close dates are site-local datetime strings; the client compares against
 * device time as a UX hint only — the server enforces windows authoritatively.
 * @param closeAt Datetime-local string from block attributes.
 */
export function isClosed( closeAt: string ): boolean {
	if ( ! closeAt ) {
		return false;
	}
	const ts = Date.parse( closeAt );
	return ! Number.isNaN( ts ) && Date.now() >= ts;
}

export function hasOpened( openAt: string ): boolean {
	const ts = Date.parse( openAt );
	return ! openAt || Number.isNaN( ts ) || Date.now() >= ts;
}

