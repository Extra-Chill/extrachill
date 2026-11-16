/**
 * Shows who is voting on this device and lets the next person switch.
 *
 * After one vote the email is remembered so later votes are one tap. On a
 * shared phone or venue kiosk that would let the next person vote as the
 * first, so a visible "Not you?" control forgets the remembered email.
 * Signed-in visitors vote with their account email; there is nothing to
 * forget, so they only see which email is voting.
 * @param root0
 * @param root0.email
 * @param root0.canForget
 * @param root0.onForget
 */
export function VoterIdentity( {
	email,
	canForget,
	onForget,
}: {
	email: string;
	canForget: boolean;
	onForget: () => void;
} ) {
	if ( ! email ) {
		return null;
	}
	return (
		<p className="extrachill-blocks-photo-contest-cta-meta">
			Voting as { email }.{ ' ' }
			{ canForget && (
				<button
					type="button"
					className="button-3 button-small"
					onClick={ onForget }
				>
					Not you?
				</button>
			) }
		</p>
	);
}
