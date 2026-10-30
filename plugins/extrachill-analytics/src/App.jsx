/**
 * Analytics Dashboard App
 *
 * Main application component for the analytics dashboard.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { Notice } from '@wordpress/components';
import { useAnalytics } from './context/AnalyticsContext';
import { getEvents, getAnalyticsMeta } from './api/client';
import EventFilters from './components/EventFilters';
import EventsTable from './components/EventsTable';

const ITEMS_PER_PAGE = 50;

export default function App() {
	const { notices, removeNotice, addNotice } = useAnalytics();

	// Filter state
	const [ filters, setFilters ] = useState( {
		event_type: '',
		blog_id: '',
		date_from: '',
		date_to: '',
		search: '',
	} );

	// Data state
	const [ events, setEvents ] = useState( [] );
	const [ meta, setMeta ] = useState( { event_types: [], blogs: [] } );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isLoadingMeta, setIsLoadingMeta ] = useState( true );

	// Pagination state
	const [ page, setPage ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );

	// Load metadata (event types, blogs) on mount
	useEffect( () => {
		loadMeta();
	}, [] );

	// Load events when filters or page changes
	useEffect( () => {
		loadEvents();
	}, [ filters, page ] );

	const loadMeta = async () => {
		setIsLoadingMeta( true );
		try {
			const response = await getAnalyticsMeta();
			setMeta( response );
		} catch ( error ) {
			addNotice(
				'error',
				error.message || 'Failed to load filter options.'
			);
		} finally {
			setIsLoadingMeta( false );
		}
	};

	const loadEvents = useCallback( async () => {
		setIsLoading( true );
		try {
			const offset = ( page - 1 ) * ITEMS_PER_PAGE;
			const response = await getEvents( {
				...filters,
				limit: ITEMS_PER_PAGE,
				offset,
			} );
			setEvents( response.events || [] );
			setTotal( response.total || 0 );
		} catch ( error ) {
			addNotice( 'error', error.message || 'Failed to load events.' );
		} finally {
			setIsLoading( false );
		}
	}, [ filters, page, addNotice ] );

	const handleFilterChange = ( newFilters ) => {
		setFilters( newFilters );
		setPage( 1 ); // Reset to first page when filters change
	};

	const handlePageChange = ( newPage ) => {
		setPage( newPage );
	};

	const totalPages = Math.ceil( total / ITEMS_PER_PAGE );

	return (
		<div className="ec-analytics">
			{ /* Notices */ }
			<div className="ec-analytics__notices">
				{ notices.map( ( notice ) => (
					<Notice
						key={ notice.id }
						status={ notice.type }
						onRemove={ () => removeNotice( notice.id ) }
					>
						{ notice.message }
					</Notice>
				) ) }
			</div>

			{ /* Filters */ }
			<EventFilters
				filters={ filters }
				onFilterChange={ handleFilterChange }
				eventTypes={ meta.event_types }
				blogs={ meta.blogs }
				isLoadingMeta={ isLoadingMeta }
			/>

			{ /* Events Table */ }
			<EventsTable
				events={ events }
				isLoading={ isLoading }
				currentPage={ page }
				totalPages={ totalPages }
				totalItems={ total }
				onPageChange={ handlePageChange }
			/>
		</div>
	);
}
