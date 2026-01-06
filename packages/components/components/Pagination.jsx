/**
 * Pagination Component
 *
 * Reusable pagination for admin interfaces.
 */

import { Button } from '@wordpress/components';

export default function Pagination( {
	currentPage,
	totalPages,
	totalItems,
	onPageChange,
} ) {
	if ( totalPages <= 1 ) {
		return null;
	}

	return (
		<div className="ec-pagination">
			<span className="ec-pagination__info">
				Page { currentPage } of { totalPages } ({ totalItems } items)
			</span>
			<div className="ec-pagination__buttons">
				<Button
					variant="secondary"
					disabled={ currentPage <= 1 }
					onClick={ () => onPageChange( currentPage - 1 ) }
				>
					Previous
				</Button>
				<Button
					variant="secondary"
					disabled={ currentPage >= totalPages }
					onClick={ () => onPageChange( currentPage + 1 ) }
				>
					Next
				</Button>
			</div>
		</div>
	);
}
