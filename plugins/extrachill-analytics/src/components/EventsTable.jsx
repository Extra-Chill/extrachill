/**
 * EventsTable Component
 *
 * Displays analytics events in a paginated table with expandable detail rows.
 */

import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { DataTable, Pagination } from '@extrachill/components';
import EventDetail from './EventDetail';

export default function EventsTable( {
	events,
	isLoading,
	currentPage,
	totalPages,
	totalItems,
	onPageChange,
} ) {
	const [ expandedEventId, setExpandedEventId ] = useState( null );

	const toggleExpand = ( eventId ) => {
		setExpandedEventId( expandedEventId === eventId ? null : eventId );
	};

	const formatDate = ( dateString ) => {
		const date = new Date( dateString );
		return date.toLocaleString();
	};

	const truncateUrl = ( url, maxLength = 40 ) => {
		if ( ! url ) return '-';
		if ( url.length <= maxLength ) return url;
		return url.substring( 0, maxLength ) + '...';
	};

	const getEventTypeBadgeClass = ( eventType ) => {
		switch ( eventType ) {
			case 'share_click':
				return 'ec-badge ec-badge--info';
			case 'newsletter_signup':
				return 'ec-badge ec-badge--success';
			case 'user_registration':
				return 'ec-badge ec-badge--success';
			case 'search':
				return 'ec-badge ec-badge--warning';
			default:
				return 'ec-badge';
		}
	};

	const columns = [
		{
			key: 'id',
			label: 'ID',
			width: '60px',
		},
		{
			key: 'event_type',
			label: 'Event Type',
			width: '150px',
			render: ( value ) => (
				<span className={ getEventTypeBadgeClass( value ) }>
					{ value.replace( /_/g, ' ' ) }
				</span>
			),
		},
		{
			key: 'source_url',
			label: 'Source URL',
			render: ( value ) => (
				<span title={ value }>{ truncateUrl( value ) }</span>
			),
		},
		{
			key: 'blog_id',
			label: 'Blog',
			width: '60px',
		},
		{
			key: 'user_id',
			label: 'User',
			width: '60px',
			render: ( value ) => value || 'Anon',
		},
		{
			key: 'created_at',
			label: 'Date',
			width: '180px',
			render: ( value ) => formatDate( value ),
		},
		{
			key: 'actions',
			label: '',
			width: '80px',
			render: ( _, row ) => (
				<Button
					variant="tertiary"
					onClick={ () => toggleExpand( row.id ) }
					className="ec-analytics__expand-btn"
				>
					{ expandedEventId === row.id ? 'Hide' : 'View' }
				</Button>
			),
		},
	];

	// Transform data to add actions column (DataTable expects column keys to exist)
	const tableData = events.map( ( event ) => ( {
		...event,
		actions: null, // Placeholder, rendered by custom render function
	} ) );

	return (
		<div className="ec-analytics__table-container">
			<DataTable
				columns={ columns }
				data={ tableData }
				isLoading={ isLoading }
				emptyMessage="No events found. Try adjusting your filters."
				rowKey="id"
			/>

			{ /* Expanded Event Detail */ }
			{ expandedEventId && (
				<EventDetail
					event={ events.find( ( e ) => e.id === expandedEventId ) }
					onClose={ () => setExpandedEventId( null ) }
				/>
			) }

			{ /* Pagination */ }
			<Pagination
				currentPage={ currentPage }
				totalPages={ totalPages }
				totalItems={ totalItems }
				onPageChange={ onPageChange }
			/>
		</div>
	);
}
