/**
 * Giveaway entry counting and winner selection.
 *
 * Mirrors ec_studio_filter_giveaway_entries() / ec_studio_pick_giveaway_winners()
 * in inc/abilities/run-giveaway.php so the Studio tab and the
 * extrachill/run-giveaway ability count entries the same way.
 */

/**
 * Minimal comment shape the giveaway rules need.
 */
export interface GiveawayComment {
	id: string;
	author_username: string;
	mentions?: string[];
}

/**
 * - per_comment: every qualifying comment is one entry ("more tags = more entries").
 * - per_user: every qualifying person is one entry.
 */
export type GiveawayEntryMode = 'per_comment' | 'per_user';

export const DEFAULT_ENTRY_MODE: GiveawayEntryMode = 'per_comment';

export interface GiveawayRules {
	requireTag: boolean;
	minTags: number;
	winnerCount: number;
	entryMode: GiveawayEntryMode;
}

export interface GiveawayStats {
	entryMode: GiveawayEntryMode;
	totalComments: number;
	validEntries: number;
	filteredOut: number;
	uniqueUsers: number;
	entriesByUser: Record< string, number >;
}

export interface GiveawayWinner< T extends GiveawayComment > {
	comment: T;
	index: number;
}

/**
 * Apply giveaway rules to a list of comments.
 *
 * A comment that fails the tag rule never counts for its author, so in
 * per_user mode a person's entry is their first qualifying comment.
 *
 * @param comments         All comments on the post.
 * @param rules            Giveaway rules.
 * @param excludeUsernames Usernames to drop entirely (e.g. previous winners).
 */
export const filterEntries = < T extends GiveawayComment >(
	comments: T[],
	rules: Pick< GiveawayRules, 'requireTag' | 'minTags' > & {
		entryMode?: GiveawayEntryMode;
	},
	excludeUsernames: string[] = []
): { entries: T[]; stats: GiveawayStats } => {
	const entryMode: GiveawayEntryMode =
		rules.entryMode === 'per_user' ? 'per_user' : DEFAULT_ENTRY_MODE;
	const minTags = Math.max( 1, rules.minTags || 1 );
	const excludeSet = new Set(
		excludeUsernames.map( ( u ) => u.toLowerCase() )
	);
	const entriesByUser: Record< string, number > = {};
	const entries: T[] = [];

	for ( const comment of comments ) {
		const username = ( comment.author_username || '' ).toLowerCase();

		if ( excludeSet.has( username ) ) {
			continue;
		}

		if ( rules.requireTag && ( comment.mentions?.length ?? 0 ) < minTags ) {
			continue;
		}

		if ( entryMode === 'per_user' && entriesByUser[ username ] ) {
			continue;
		}

		entriesByUser[ username ] = ( entriesByUser[ username ] ?? 0 ) + 1;
		entries.push( comment );
	}

	return {
		entries,
		stats: {
			entryMode,
			totalComments: comments.length,
			validEntries: entries.length,
			filteredOut: comments.length - entries.length,
			uniqueUsers: Object.keys( entriesByUser ).length,
			entriesByUser,
		},
	};
};

/**
 * Unbiased crypto-secure integer in [0, max).
 *
 * @param max Exclusive upper bound.
 */
export const randomIndex = ( max: number ): number => {
	const limit = Math.floor( 0x100000000 / max ) * max;
	const buffer = new Uint32Array( 1 );
	do {
		crypto.getRandomValues( buffer );
	} while ( buffer[ 0 ] >= limit );
	return buffer[ 0 ] % max;
};

/**
 * Draw winners. Every entry has equal odds; once someone wins, their other
 * entries leave the pool, so winners are always distinct people.
 *
 * @param entries Filtered valid entries.
 * @param count   Number of winners to pick.
 * @param random  Index source (injectable for tests).
 */
export const pickWinners = < T extends GiveawayComment >(
	entries: T[],
	count: number,
	random: ( max: number ) => number = randomIndex
): GiveawayWinner< T >[] => {
	let pool = entries.map( ( comment, index ) => ( { comment, index } ) );
	const winners: GiveawayWinner< T >[] = [];

	while ( winners.length < count && pool.length > 0 ) {
		const winner = pool[ random( pool.length ) ];
		winners.push( winner );

		const username = ( winner.comment.author_username || '' ).toLowerCase();
		pool = pool.filter( ( entry ) =>
			username === ''
				? entry !== winner
				: ( entry.comment.author_username || '' ).toLowerCase() !==
				  username
		);
	}

	return winners;
};
