/**
 * RsvpPassBadge — RSVP perk pass display for a My Shows show card (#877
 * slice 2).
 *
 * Fetches this plugin's own extrachill/get-my-event-pass ability
 * (read-only, never issues — see inc/Abilities/RsvpPassAbilities.php) on
 * mount and renders nothing at all when the event has no perk or the
 * viewer holds no active pass. Entirely self-contained: My Shows
 * (blocks/concert-stats) and the RSVP perk feature both live in
 * extrachill-events, so this calls its own plugin's ability directly —
 * no extrachill-users involvement, matching the perk feature's existing
 * layer boundary (extrachill-users has no concept of perks).
 *
 * Owner-only: rendered by ShowCard only when `isOwn` — a pass code is the
 * same private, redeemable bearer token whether viewed on the event page
 * or here; a public visitor to someone else's My Shows page must not see
 * it, exactly as the door list must not leak private attendee identity.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * @param {Object} props         Component props.
 * @param {number} props.eventId Event post ID.
 */
const RsvpPassBadge = ( { eventId } ) => {
	const [ pass, setPass ] = useState( null );

	useEffect( () => {
		let cancelled = false;

		apiFetch( {
			path: '/wp-abilities/v1/abilities/extrachill/get-my-event-pass/run',
			method: 'POST',
			data: { input: { event_id: eventId } },
		} )
			.then( ( response ) => {
				if ( ! cancelled && response && response.issued ) {
					setPass( response );
				}
			} )
			.catch( () => {
				// No pass to show is a normal, silent outcome here.
			} );

		return () => {
			cancelled = true;
		};
	}, [ eventId ] );

	if ( ! pass ) {
		return null;
	}

	return (
		<div className="ec-concert-stats__rsvp-pass ec-surface-card ec-card-vertical-padding">
			{ pass.perk_text && (
				<p className="ec-concert-stats__rsvp-pass-perk">
					{ pass.perk_text }
				</p>
			) }
			<p className="ec-concert-stats__rsvp-pass-code">{ pass.code }</p>
		</div>
	);
};

export default RsvpPassBadge;
