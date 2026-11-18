/**
 * EventDetail Component
 *
 * Displays parsed event data based on event type.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';

export default function EventDetail( { event, onClose } ) {
	if ( ! event ) {
		return null;
	}

	const {
		event_type: eventType,
		event_data: eventData,
		source_url: sourceUrl,
		blog_id: blogId,
		user_id: userId,
		created_at: createdAt,
	} = event;

	/**
	 * Render parsed event data based on event type.
	 */
	const renderEventData = () => {
		if ( ! eventData ) {
			return (
				<p className="ec-event-detail__no-data">No additional data.</p>
			);
		}

		switch ( eventType ) {
			case 'share_click':
				return (
					<dl className="ec-event-detail__parsed">
						<dt>Platform</dt>
						<dd>{ eventData.destination || '-' }</dd>
						<dt>Shared URL</dt>
						<dd>
							{ eventData.share_url ? (
								<a
									href={ eventData.share_url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ eventData.share_url }
								</a>
							) : (
								'-'
							) }
						</dd>
					</dl>
				);

			case 'newsletter_signup':
				return (
					<dl className="ec-event-detail__parsed">
						<dt>Context</dt>
						<dd>{ eventData.context || '-' }</dd>
						<dt>List ID</dt>
						<dd>{ eventData.list_id || '-' }</dd>
					</dl>
				);

			case 'user_registration':
				return (
					<dl className="ec-event-detail__parsed">
						<dt>Registered User ID</dt>
						<dd>{ eventData.user_id || '-' }</dd>
						<dt>Source</dt>
						<dd>{ eventData.source || '-' }</dd>
						<dt>Method</dt>
						<dd>{ eventData.method || '-' }</dd>
					</dl>
				);

			case 'search':
				return (
					<dl className="ec-event-detail__parsed">
						<dt>Search Term</dt>
						<dd>{ eventData.search_term || '-' }</dd>
						<dt>Result Count</dt>
						<dd>
							{ eventData.result_count !== undefined
								? eventData.result_count
								: '-' }
						</dd>
					</dl>
				);

			case '404_error':
				return (
					<dl className="ec-event-detail__parsed">
						<dt>Requested URL</dt>
						<dd>{ eventData.requested_url || '-' }</dd>
						<dt>Referer</dt>
						<dd>
							{ eventData.referer ? (
								<a
									href={ eventData.referer }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ eventData.referer }
								</a>
							) : (
								'Direct / None'
							) }
						</dd>
						<dt>User Agent</dt>
						<dd>
							<code>{ eventData.user_agent || '-' }</code>
						</dd>
					</dl>
				);

			default:
				// For unknown event types, show raw JSON
				return (
					<pre className="ec-event-detail__raw">
						{ JSON.stringify( eventData, null, 2 ) }
					</pre>
				);
		}
	};

	const formatDate = ( dateString ) => {
		const date = new Date( dateString );
		return date.toLocaleString();
	};

	return (
		<div className="ec-event-detail">
			<div className="ec-event-detail__header">
				<h3>Event Details (ID: { event.id })</h3>
				<Button variant="tertiary" onClick={ onClose }>
					Close
				</Button>
			</div>

			<div className="ec-event-detail__content">
				<div className="ec-event-detail__meta">
					<dl>
						<dt>Event Type</dt>
						<dd>
							<span className="ec-badge ec-badge--info">
								{ eventType.replace( /_/g, ' ' ) }
							</span>
						</dd>
						<dt>Source URL</dt>
						<dd>
							{ sourceUrl ? (
								<a
									href={ sourceUrl }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ sourceUrl }
								</a>
							) : (
								'-'
							) }
						</dd>
						<dt>Blog ID</dt>
						<dd>{ blogId }</dd>
						<dt>User ID</dt>
						<dd>{ userId || 'Anonymous' }</dd>
						<dt>Timestamp</dt>
						<dd>{ formatDate( createdAt ) }</dd>
					</dl>
				</div>

				<div className="ec-event-detail__data">
					<h4>Event Data</h4>
					{ renderEventData() }
				</div>
			</div>
		</div>
	);
}
