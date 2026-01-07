/**
 * EventFilters Component
 *
 * Filter controls for the analytics dashboard.
 */

import { useState } from '@wordpress/element';
import { SelectControl, Button, DatePicker, Popover, TextControl } from '@wordpress/components';

export default function EventFilters( {
	filters,
	onFilterChange,
	eventTypes = [],
	blogs = [],
	isLoadingMeta = false,
} ) {
	const [ showDateFrom, setShowDateFrom ] = useState( false );
	const [ showDateTo, setShowDateTo ] = useState( false );

	const handleChange = ( key, value ) => {
		onFilterChange( { ...filters, [ key ]: value } );
	};

	const handleSearch = ( searchTerm ) => {
		handleChange( 'search', searchTerm );
	};

	const handleClearFilters = () => {
		onFilterChange( {
			event_type: '',
			blog_id: '',
			date_from: '',
			date_to: '',
			search: '',
		} );
	};

	const formatDateForDisplay = ( dateString ) => {
		if ( ! dateString ) return 'Select date';
		return dateString;
	};

	const hasActiveFilters = filters.event_type || filters.blog_id || filters.date_from || filters.date_to || filters.search;

	// Build options arrays
	const eventTypeOptions = [
		{ label: 'All Event Types', value: '' },
		...eventTypes.map( ( type ) => ( {
			label: type.replace( /_/g, ' ' ).replace( /\b\w/g, ( c ) => c.toUpperCase() ),
			value: type,
		} ) ),
	];

	const blogOptions = [
		{ label: 'All Sites', value: '' },
		...blogs.map( ( blog ) => ( {
			label: `${ blog.name } (ID: ${ blog.id })`,
			value: String( blog.id ),
		} ) ),
	];

	return (
		<div className="ec-analytics__filters">
			<div className="ec-analytics__filters-row">
				{ /* Event Type */ }
				<SelectControl
					label="Event Type"
					value={ filters.event_type }
					options={ eventTypeOptions }
					onChange={ ( value ) => handleChange( 'event_type', value ) }
					disabled={ isLoadingMeta }
					__nextHasNoMarginBottom
				/>

				{ /* Blog */ }
				<SelectControl
					label="Site"
					value={ filters.blog_id }
					options={ blogOptions }
					onChange={ ( value ) => handleChange( 'blog_id', value ) }
					disabled={ isLoadingMeta }
					__nextHasNoMarginBottom
				/>

				{ /* Date From */ }
				<div className="ec-analytics__date-filter">
					<label>From Date</label>
					<Button
						variant="secondary"
						onClick={ () => setShowDateFrom( ! showDateFrom ) }
					>
						{ formatDateForDisplay( filters.date_from ) }
					</Button>
					{ showDateFrom && (
						<Popover onClose={ () => setShowDateFrom( false ) }>
							<DatePicker
								currentDate={ filters.date_from }
								onChange={ ( date ) => {
									handleChange( 'date_from', date ? date.split( 'T' )[ 0 ] : '' );
									setShowDateFrom( false );
								} }
							/>
						</Popover>
					) }
				</div>

				{ /* Date To */ }
				<div className="ec-analytics__date-filter">
					<label>To Date</label>
					<Button
						variant="secondary"
						onClick={ () => setShowDateTo( ! showDateTo ) }
					>
						{ formatDateForDisplay( filters.date_to ) }
					</Button>
					{ showDateTo && (
						<Popover onClose={ () => setShowDateTo( false ) }>
							<DatePicker
								currentDate={ filters.date_to }
								onChange={ ( date ) => {
									handleChange( 'date_to', date ? date.split( 'T' )[ 0 ] : '' );
									setShowDateTo( false );
								} }
							/>
						</Popover>
					) }
				</div>
			</div>

			<div className="ec-analytics__filters-row">
				{ /* Search */ }
				<TextControl
					label="Search"
					value={ filters.search }
					onChange={ ( value ) => handleSearch( value ) }
					placeholder="Search event data..."
					__nextHasNoMarginBottom
				/>

				{ /* Clear Filters */ }
				{ hasActiveFilters && (
					<Button
						variant="tertiary"
						onClick={ handleClearFilters }
						className="ec-analytics__clear-filters"
					>
						Clear All Filters
					</Button>
				) }
			</div>
		</div>
	);
}
