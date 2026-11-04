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
