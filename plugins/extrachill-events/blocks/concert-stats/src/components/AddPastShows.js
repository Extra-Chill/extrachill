/**
 * AddPastShows — owner-only "add a past show" affordance.
 *
 * #837: the search-and-mark surface generalized into EventQuickAdd
 * (period-scoped); this thin wrapper keeps the Past tab's exact prior
 * behavior — same defaults, same section class — so PastTab stays
 * untouched.
 *
 * @package
 */

/**
 * Internal dependencies
 */
import EventQuickAdd from './EventQuickAdd';

const AddPastShows = ( { onMarked } ) => (
	<EventQuickAdd
		period="past"
		className="ec-concert-stats__add-past"
		onMarked={ onMarked }
	/>
);

export default AddPastShows;
