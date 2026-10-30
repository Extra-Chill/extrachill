/**
 * ShowRating — the owner's private 1–5 rating of a past show on My Shows.
 *
 * Ratings are personal: only the fan sees them (extrachill-users stores
 * them on their own attendance record). An unrated show shows a quiet
 * "Rate" button rather than a row of empty stars, so the list reads as a
 * concert history, not a to-do list. Tapping it opens the stars; picking
 * one saves. Tapping the current star again clears the rating.
 *
 * @package
 */

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

const STARS = [ 1, 2, 3, 4, 5 ];

/**
 * @param {Object}   props
 * @param {number}   props.eventId Event post ID.
 * @param {number}   props.rating  Current rating, 0 when unrated.
 * @param {Function} props.onRate  ( eventId, rating ) => Promise.
 * @param {?string}  props.error   Save error, if any.
 * @param {string}   props.title   Show title, for accessible labels.
 */
const ShowRating = ( { eventId, rating = 0, onRate, error = null, title } ) => {
	const [ open, setOpen ] = useState( false );
	const [ hover, setHover ] = useState( 0 );
	const shown = hover || rating;

	const pick = ( value ) => {
		setHover( 0 );
		setOpen( false );
		onRate( eventId, value === rating ? 0 : value );
	};

	return (
		<span className="ec-concert-stats__show-rating">
			{ ! rating && ! open ? (
				<button
					type="button"
					className="ec-concert-stats__show-rating-toggle"
					aria-label={ title ? `Rate ${ title }` : 'Rate this show' }
					onClick={ () => setOpen( true ) }
				>
					Rate
				</button>
			) : (
				<span
					className="ec-concert-stats__show-rating-stars"
					role="group"
					aria-label={
						title ? `Your rating of ${ title }` : 'Your rating'
					}
					onMouseLeave={ () => setHover( 0 ) }
				>
					{ STARS.map( ( value ) => (
						<button
							key={ value }
							type="button"
							className={
								'ec-concert-stats__show-rating-star' +
								( value <= shown ? ' is-on' : '' )
							}
							aria-label={ `${ value } star${
								value > 1 ? 's' : ''
							}` }
							aria-pressed={ value === rating }
							onMouseEnter={ () => setHover( value ) }
							onFocus={ () => setHover( value ) }
							onBlur={ () => setHover( 0 ) }
							onClick={ () => pick( value ) }
						>
							★
						</button>
					) ) }
				</span>
			) }
			{ error && (
				<span
					className="ec-concert-stats__show-rating-error"
					role="alert"
				>
					{ error }
				</span>
			) }
		</span>
	);
};

export default ShowRating;
