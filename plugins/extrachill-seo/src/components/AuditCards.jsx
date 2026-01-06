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
	if ( count === 0 ) return 'zero';
	if ( count > 50 ) return 'error';
	if ( count > 10 ) return 'warning';
	return '';
};

const AuditCard = ( { metricKey, label, data, onViewDetails } ) => {
	const total = data?.total || 0;
	const sites = Object.values( data?.by_site || {} );
	const nonZeroSites = sites.filter( ( s ) => s.count > 0 );
	const countClass = getCountClass( total );

	return (
		<div className="extrachill-seo-card">
			<div className={ `extrachill-seo-card-count ${ countClass }` }>
				{ total.toLocaleString() }
			</div>
			<div className="extrachill-seo-card-label">{ label }</div>

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
			{ METRICS.map( ( { key, label } ) => (
				<AuditCard
					key={ key }
					metricKey={ key }
					label={ label }
					data={ results[ key ] }
					onViewDetails={ loadDetails }
				/>
			) ) }
		</div>
	);
};

export default AuditCards;
