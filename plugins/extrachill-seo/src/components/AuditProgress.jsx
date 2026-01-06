/**
 * Audit Progress Component
 *
 * Displays progress bar during batch audit.
 */
import { useAudit } from '../context/AuditContext';

const CHECK_NAMES = {
	missing_excerpts: 'Missing Excerpts',
	missing_alt_text: 'Missing Alt Text',
	missing_featured: 'Missing Featured Images',
	broken_images: 'Broken Images',
	broken_internal_links: 'Broken Internal Links',
	broken_external_links: 'Broken External Links',
};

const AuditProgress = () => {
	const { progress } = useAudit();

	if ( ! progress ) return null;

	const { urls_checked = 0, urls_total = 0, checks = [], current_check_index = 0 } = progress;
	const percent = urls_total > 0 ? Math.round( ( urls_checked / urls_total ) * 100 ) : 0;
	const currentCheck = checks[ current_check_index ] || '';
	const checkName = CHECK_NAMES[ currentCheck ] || currentCheck;

	return (
		<div className="extrachill-seo-progress">
			<div className="extrachill-seo-progress-text">
				{ checkName }: { urls_checked } / { urls_total } URLs checked
			</div>
			<div className="extrachill-seo-progress-bar">
				<div
					className="extrachill-seo-progress-bar-fill"
					style={ { width: `${ percent }%` } }
				/>
			</div>
		</div>
	);
};

export default AuditProgress;
