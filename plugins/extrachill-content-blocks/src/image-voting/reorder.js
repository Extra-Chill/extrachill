jQuery(document).ready(function($) {
	// Find all image voting blocks on the page
	const $blocks = $('.extrachill-blocks-image-voting-container');

	// Only proceed if there are multiple blocks to sort
	if ($blocks.length <= 1) {
		return;
	}

	// Group blocks by their immediate parent container
	const blocksByParent = new Map();
	$blocks.each(function(index) {
		const $block = $(this);
		const $parent = $block.parent();
		const parentKey = $parent.get(0); // Use DOM element as key

		if (!blocksByParent.has(parentKey)) {
			blocksByParent.set(parentKey, []);
		}

		const voteCount = parseInt($block.find('.vote-number').text(), 10) || 0;
		blocksByParent.get(parentKey).push({
			element: $block,
			voteCount: voteCount,
			originalIndex: index
		});
	});

	// Sort blocks within each parent container independently
	blocksByParent.forEach((blocksData, parentElement) => {
		// Only sort if this parent has multiple blocks
		if (blocksData.length <= 1) {
			return;
		}

		// Sort by vote count (descending), maintaining original order for ties
		blocksData.sort((a, b) => {
			if (b.voteCount !== a.voteCount) {
				return b.voteCount - a.voteCount;
			}
			// If vote counts are equal, maintain original order
			return a.originalIndex - b.originalIndex;
		});

		// Detach all blocks from this parent
		blocksData.forEach(data => data.element.detach());

		// Re-append blocks in sorted order
		const $parent = $(parentElement);
		blocksData.forEach(data => {
			$parent.append(data.element);
		});
	});
});
