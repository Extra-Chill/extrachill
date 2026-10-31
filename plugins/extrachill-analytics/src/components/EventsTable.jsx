/**
 * EventsTable Component
 *
 * Displays analytics events in a paginated table with expandable detail rows.
 */

import { useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
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
			case '404_error':
				return 'ec-badge ec-badge--error';
			default:
				return 'ec-badge';
		}
	};

	return (
		<div className="ec-analytics__table-container">
			{ isLoading ? (
				<div className="ec-analytics__loading">
					<Spinner />
					<span>Loading events...</span>
				</div>
			) : events.length === 0 ? (
				<p className="ec-analytics__empty">
					No events found. Try adjusting your filters.
				</p>
			) : (
				<div className="ec-analytics__table">
					<table className="widefat striped">
						<thead>
							<tr>
								<th>ID</th>
								<th>Event Type</th>
								<th>Source URL</th>
								<th>Blog</th>
								<th>User</th>
								<th>Date</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ events.map( ( event ) => (
								<tr key={ event.id }>
									<td>{ event.id }</td>
									<td>
										<span
											className={ getEventTypeBadgeClass(
												event.event_type
											) }
										>
											{ event.event_type
												? event.event_type.replace(
														/_/g,
														' '
												  )
												: 'Unknown' }
										</span>
									</td>
									<td>
										<span title={ event.source_url }>
											{ truncateUrl( event.source_url ) }
										</span>
									</td>
									<td>{ event.blog_id }</td>
									<td>{ event.user_id || 'Anon' }</td>
									<td>{ formatDate( event.created_at ) }</td>
									<td>
										<Button
											variant="tertiary"
											onClick={ () =>
												toggleExpand( event.id )
											}
											className="ec-analytics__expand-btn"
										>
											{ expandedEventId === event.id
												? 'Hide'
												: 'View' }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ /* Expanded Event Detail */ }
			{ expandedEventId && (
				<EventDetail
					event={ events.find( ( e ) => e.id === expandedEventId ) }
					onClose={ () => setExpandedEventId( null ) }
				/>
			) }

			{ /* Pagination */ }
			{ totalPages > 1 && (
				<div className="ec-analytics__pagination">
					<Button
						variant="secondary"
						disabled={ currentPage <= 1 }
						onClick={ () => onPageChange( currentPage - 1 ) }
					>
						Previous
					</Button>
					<span>
						Page { currentPage } of { totalPages } ({ totalItems }{ ' ' }
						total)
					</span>
					<Button
						variant="secondary"
						disabled={ currentPage >= totalPages }
						onClick={ () => onPageChange( currentPage + 1 ) }
					>
						Next
					</Button>
				</div>
			) }
		</div>
	);
}
