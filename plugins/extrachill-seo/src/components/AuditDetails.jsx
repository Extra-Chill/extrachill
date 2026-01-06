/**
 * Audit Details Component
 *
 * Displays detailed results for a specific audit category.
 */
import { DataTable, Pagination } from '@extrachill/components';
import { useAudit } from '../context/AuditContext';

const CHECK_NAMES = {
	missing_excerpts: 'Posts Missing Excerpts',
	missing_alt_text: 'Images Missing Alt Text',
	missing_featured: 'Posts Without Featured Images',
	broken_images: 'Broken Images',
	broken_internal_links: 'Broken Internal Links',
	broken_external_links: 'Broken External Links',
};

const COLUMN_SETS = {
	missing_excerpts: [
		{ key: 'site_label', label: 'Site' },
		{ key: 'title', label: 'Title' },
		{ key: 'post_type', label: 'Type' },
		{ key: 'edit_url', label: 'Action' },
	],
	missing_alt_text: [
		{ key: 'site_label', label: 'Site' },
		{ key: 'filename', label: 'Image' },
		{ key: 'parent_title', label: 'Parent Post' },
		{ key: 'edit_url', label: 'Action' },
	],
	missing_featured: [
		{ key: 'site_label', label: 'Site' },
		{ key: 'title', label: 'Title' },
		{ key: 'post_type', label: 'Type' },
		{ key: 'edit_url', label: 'Action' },
	],
	broken_images: [
		{ key: 'site_label', label: 'Site' },
		{ key: 'image_url', label: 'Image URL' },
		{ key: 'post_title', label: 'Parent Post' },
		{ key: 'edit_url', label: 'Action' },
	],
	broken_internal_links: [
		{ key: 'site_label', label: 'Site' },
		{ key: 'link_url', label: 'Link URL' },
		{ key: 'post_title', label: 'Parent Post' },
		{ key: 'edit_url', label: 'Action' },
	],
	broken_external_links: [
		{ key: 'site_label', label: 'Site' },
		{ key: 'link_url', label: 'Link URL' },
		{ key: 'post_title', label: 'Parent Post' },
		{ key: 'edit_url', label: 'Action' },
	],
};

const renderCell = ( item, column ) => {
	const value = item[ column.key ] || '';

	if ( column.key === 'edit_url' && value ) {
		return (
			<a href={ value } target="_blank" rel="noopener noreferrer">
				Edit
			</a>
		);
	}

	if ( column.key === 'image_url' || column.key === 'link_url' ) {
		const truncated = value.length > 50 ? value.substring( 0, 50 ) + '...' : value;
		return <span title={ value }>{ truncated }</span>;
	}

	return value;
};

const AuditDetails = () => {
	const {
		detailsCategory,
		detailsItems,
		detailsPage,
		detailsTotal,
		detailsTotalPages,
		detailsLoading,
		loadDetails,
		closeDetails,
		handleExport,
	} = useAudit();

	if ( ! detailsCategory ) return null;

	const columns = COLUMN_SETS[ detailsCategory ] || [];
	const title = CHECK_NAMES[ detailsCategory ] || detailsCategory;

	const handlePageChange = ( newPage ) => {
		loadDetails( detailsCategory, newPage );
	};

	return (
		<div className="extrachill-seo-details">
			<div className="extrachill-seo-details-header">
				<h3>Details: { title }</h3>
				<div className="extrachill-seo-details-actions">
					<button type="button" className="button" onClick={ handleExport }>
						Export JSON
					</button>
					<button type="button" className="button" onClick={ closeDetails }>
						Close
					</button>
				</div>
			</div>

			{ detailsLoading ? (
				<p>Loading...</p>
			) : (
				<>
					<DataTable
						columns={ columns }
						data={ detailsItems }
						renderCell={ renderCell }
						emptyMessage="No items found."
					/>

					{ detailsTotalPages > 1 && (
						<Pagination
							currentPage={ detailsPage }
							totalPages={ detailsTotalPages }
							totalItems={ detailsTotal }
							onPageChange={ handlePageChange }
						/>
					) }
				</>
			) }
		</div>
	);
};

export default AuditDetails;
