/** Copy the host's share URL; leave it selectable when clipboard access fails. */
document.addEventListener( 'click', async ( event ) => {
	const i18n = window.ecGuestListManagement || {};
	const button = event.target.closest( '[data-copy-guest-list]' );
	if ( ! button ) return;
	const container = button.closest( '[data-guest-list-share]' );
	const input = container.querySelector( 'input' );
	const status = container.querySelector( '[data-copy-status]' );
	try {
		await navigator.clipboard.writeText( input.value );
		status.textContent = i18n.copied || '';
	} catch {
		input.focus();
		input.select();
		status.textContent = i18n.copyHint || '';
	}
} );

/** Ask before a host takes a guest off the list from the door list. */
document.addEventListener( 'submit', ( event ) => {
	const form = event.target.closest( 'form[data-confirm]' );
	// eslint-disable-next-line no-alert -- A native confirm is the right guard against a mis-tap at the door.
	if ( form && ! window.confirm( form.dataset.confirm ) ) {
		event.preventDefault();
	}
} );

/**
 * Door list type-to-filter (extrachill#1142): hide rows whose name does not
 * contain the typed text. A party (a primary and its "+N" rows share
 * data-door-group) stays visible together when any of its names match.
 */
document.addEventListener( 'input', ( event ) => {
	const input = event.target.closest( '[data-door-list-filter]' );
	if ( ! input ) return;
	const list = document.getElementById( input.getAttribute( 'aria-controls' ) );
	if ( ! list ) return;
	const fold = ( text ) =>
		text
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.toLowerCase()
			.replace( /\s+/g, ' ' )
			.trim();
	const query = fold( input.value );
	const rows = Array.from( list.querySelectorAll( '.ec-door-list__row' ) );
	const name = ( row ) => {
		const el = row.querySelector( '.ec-door-list__name' );
		return fold( el ? el.textContent : '' );
	};
	const groups = new Set();
	rows.forEach( ( row ) => {
		if ( '' === query || name( row ).includes( query ) ) {
			groups.add( row.dataset.doorGroup || row );
		}
	} );
	let shown = 0;
	rows.forEach( ( row ) => {
		const visible = groups.has( row.dataset.doorGroup || row );
		row.style.display = visible ? '' : 'none';
		shown += visible ? 1 : 0;
	} );
	const status = input
		.closest( '.ec-door-list' )
		?.querySelector( '[data-door-list-filter-status]' );
	if ( status ) {
		status.textContent = shown ? '' : input.dataset.noMatch || '';
	}
} );
