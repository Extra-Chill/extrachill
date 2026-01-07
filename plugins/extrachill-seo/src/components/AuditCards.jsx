/**
 * Audit Cards Component
 *
 * Displays metric cards for each audit category.
 */
import { useAudit } from '../context/AuditContext';

const METRICS = [
	{ key: 'missing_excerpts', label: 'Posts Missing Excerpts' },
	{ key: 'missing_alt_text', label: 'Images Missing Alt Text' },
	{ key: 'missing_featured', label: 'Posts Without Featured Images' },
	{ key: 'broken_images', label: 'Broken Images' },
	{ key: 'broken_internal_links', label: 'Broken Internal Links' },
	{ key: 'broken_external_links', label: 'Broken External Links' },
];

const getCountClass = ( count ) => {
	if ( count === 0 ) {
		return 'extrachill-seo-card-count zero';
	}
	if ( count > 50 ) {
		return 'extrachill-seo-card-count high';
	}
	if ( count > 10 ) {
		return 'extrachill-seo-card-count warning';
	}
	return 'extrachill-seo-card-count';
};

const AuditCard = ( { metricKey, label, data, onViewDetails } ) => {
	const total = data?.total ?? 0;
	const bySite = data?.by_site ?? {};
	const sites = Object.values( bySite );
	const nonZeroSites = sites.filter( ( s ) => s.count > 0 );
	const countClassName = getCountClass( total );

	return (
		<div className="extrachill-seo-card">
			<span className={ countClassName }>
				{ total.toLocaleString() }
			</span>
			<span className="extrachill-seo-card-label">{ label }</span>

			{ nonZeroSites.length > 0 && (
				<details className="extrachill-seo-card-breakdown">
					<summary>Per-site breakdown</summary>
					<ul>
						{ nonZeroSites.map( ( site ) => (
							<li key={ site.label }>
								{ site.label }: { site.count.toLocaleString() }
							</li>
						) ) }
					</ul>
				</details>
			) }

			{ total > 0 && (
				<button
					type="button"
					className="button button-small"
					onClick={ () => onViewDetails( metricKey ) }
				>
					View Details
				</button>
			) }
		</div>
	);
};

const AuditCards = () => {
	const { results, loadDetails } = useAudit();

	return (
		<div className="extrachill-seo-cards">
			{ METRICS.map( ( metric ) => (
				<AuditCard
					key={ metric.key }
					metricKey={ metric.key }
					label={ metric.label }
					data={ results[ metric.key ] }
					onViewDetails={ loadDetails }
				/>
			) ) }
		</div>
	);
};

export default AuditCards;
