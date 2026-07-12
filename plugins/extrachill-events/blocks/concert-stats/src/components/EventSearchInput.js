/**
 * EventSearchInput — Canonical search input wrapper.
 *
 * Thin adapter over the canonical `SearchBox` primitive from
 * `@extrachill/components`. The parent owns the committed query value;
 * `onChange(value)` is invoked when the user hits Enter, clicks the
 * Search button, or clears the input. Debouncing (if any) lives in the
 * consuming hook (`useEventSearch`).
 *
 * @package
 */

/**
 * External dependencies
 */
import { SearchBox } from '@extrachill/components';

const EventSearchInput = ( { value, onChange, placeholder } ) => {
	return (
		<SearchBox
			value={ value }
			onSearch={ ( next ) => onChange( next ) }
			onClear={ () => onChange( '' ) }
			placeholder={
				placeholder || 'Search past shows by artist, venue, or title…'
			}
		/>
	);
};

export default EventSearchInput;
