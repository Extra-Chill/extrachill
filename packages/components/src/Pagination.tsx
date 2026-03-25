export interface PaginationProps {
	currentPage: number;
	totalPages: number;
	totalItems: number;
	onPageChange: (page: number) => void;
}

export function Pagination({
	currentPage,
	totalPages,
	totalItems,
	onPageChange,
}: PaginationProps) {
	if (totalPages <= 1) {
		return null;
	}

	return (
		<div className="ec-pagination">
			<span className="ec-pagination__info">
				Page {currentPage} of {totalPages} ({totalItems} items)
			</span>
			<div className="ec-pagination__buttons">
				<button
					type="button"
					disabled={currentPage <= 1}
					onClick={() => onPageChange(currentPage - 1)}
				>
					Previous
				</button>
				<button
					type="button"
					disabled={currentPage >= totalPages}
					onClick={() => onPageChange(currentPage + 1)}
				>
					Next
				</button>
			</div>
		</div>
	);
}
