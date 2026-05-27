/**
 * EventSearchInput — Controlled search input with a clear button.
 *
 * The debounce lives in the consuming hook (`useEventSearch`), so this
 * component just controls the input value and emits onChange immediately.
 *
 * @package ExtraChillEvents
 */

const EventSearchInput = ( { value, onChange, placeholder } ) => {
	return (
		<div className="ec-concert-stats__search">
			<input
				type="search"
				className="ec-concert-stats__search-input"
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
				placeholder={ placeholder || 'Search past shows by artist, venue, or title…' }
				aria-label="Search past events"
			/>
			{ value && (
				<button
					type="button"
					className="ec-concert-stats__search-clear"
					onClick={ () => onChange( '' ) }
					aria-label="Clear search"
				>
					×
				</button>
			) }
		</div>
	);
};

export default EventSearchInput;
