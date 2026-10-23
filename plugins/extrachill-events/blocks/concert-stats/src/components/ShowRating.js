/**
 * ShowRating — inline 1–5 star rating for a past show on the owner's My
 * Shows list. Writes through the data-machine-events rating primitive; the
 * event page shows the full review thread.
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
	const [ hover, setHover ] = useState( 0 );
	const shown = hover || rating;

	return (
		<span className="ec-concert-stats__show-rating">
			<span
				className="ec-concert-stats__show-rating-stars"
				role="group"
				aria-label={ title ? `Rate ${ title }` : 'Rate this show' }
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
						onClick={ () => onRate( eventId, value ) }
					>
						★
					</button>
				) ) }
			</span>
			{ ! rating && ! error && (
				<span className="ec-concert-stats__show-rating-hint">
					Rate it
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
