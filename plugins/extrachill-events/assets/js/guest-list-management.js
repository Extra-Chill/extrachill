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
