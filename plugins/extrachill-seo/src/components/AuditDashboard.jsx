/**
 * Audit Dashboard Component
 *
 * Main component for the SEO audit tab.
 */
import { useAudit } from '../context/AuditContext';
import AuditActions from './AuditActions';
import AuditProgress from './AuditProgress';
import AuditCards from './AuditCards';
import AuditDetails from './AuditDetails';

const formatTimestamp = ( timestamp ) => {
	if ( ! timestamp ) return null;
	const date = new Date( timestamp * 1000 );
	return date.toLocaleString();
};

const AuditDashboard = () => {
	const { status, timestamp, results } = useAudit();

	const hasResults = status !== 'none' && Object.keys( results ).length > 0;
	const isInProgress = status === 'in_progress';
	const formattedTime = formatTimestamp( timestamp );

	return (
		<div className="extrachill-seo-audit">
			<h2>SEO Health Dashboard</h2>
			<p className="description">
				Audit your network for SEO issues including missing meta descriptions, alt text, and broken links.
			</p>

			<AuditActions />
			<AuditProgress />

			{ formattedTime && (
				<div className="extrachill-seo-timestamp">
					Last audit: { formattedTime }
					{ isInProgress && <strong> (In Progress)</strong> }
				</div>
			) }

			{ hasResults ? (
				<AuditCards />
			) : (
				<div className="extrachill-seo-empty">
					<p>No audit data available. Run an audit to see SEO health metrics.</p>
				</div>
			) }

			<AuditDetails />
		</div>
	);
};

export default AuditDashboard;
