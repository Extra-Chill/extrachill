import { __, sprintf } from '@wordpress/i18n';
import { createElement, useState } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import { ActionRow, FieldGroup, InlineStatus, Panel, PanelHeader } from '@extrachill/components';

import { studioSocialsApi } from '../../app/client';
import type { SocialComment } from '../../app/client';
import type { StudioPaneProps } from '../../types/studio';

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const ActionRowView = ActionRow as unknown as ( props: any ) => ReactElement;
const FieldGroupView = FieldGroup as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;

/**
 * Instagram shortcode alphabet for base64 decoding.
 * Shortcodes use this custom alphabet to encode the numeric media ID.
 */
const IG_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

/**
 * Decode an Instagram shortcode to a numeric media ID.
 * Pure math — no API call needed.
 */
const shortcodeToMediaId = ( shortcode: string ): string => {
	let id = BigInt( 0 );
	for ( const char of shortcode ) {
		const index = IG_ALPHABET.indexOf( char );
		if ( index === -1 ) {
			return '';
		}
		id = id * BigInt( 64 ) + BigInt( index );
	}
	return id.toString();
};

/**
 * Extract a shortcode from an Instagram URL.
 * Supports /p/SHORTCODE/, /reel/SHORTCODE/, /tv/SHORTCODE/.
 */
const extractShortcode = ( url: string ): string | null => {
	const match = url.match( /instagram\.com\/(?:p|reel|tv)\/([A-Za-z0-9_-]+)/ );
	return match ? match[ 1 ] : null;
};

/**
 * Parse user input into a platform + media ID.
 * Accepts:
 *   - A numeric media ID (e.g. 17891234567890123)
 *   - An Instagram URL (e.g. https://www.instagram.com/p/CxYz1234abc/)
 */
const parseMediaInput = ( input: string ): { platform: string; mediaId: string } | null => {
	const trimmed = input.trim();

	if ( ! trimmed ) {
		return null;
	}

	// Already a numeric media ID.
	if ( /^\d{10,}$/.test( trimmed ) ) {
		return { platform: 'instagram', mediaId: trimmed };
	}

	// Instagram URL — extract shortcode and decode to numeric ID.
	if ( trimmed.includes( 'instagram.com' ) ) {
		const shortcode = extractShortcode( trimmed );
		if ( shortcode ) {
			const mediaId = shortcodeToMediaId( shortcode );
			if ( mediaId ) {
				return { platform: 'instagram', mediaId };
			}
		}
		return { platform: 'instagram', mediaId: '' };
	}

	// Could be a bare shortcode — try decoding it.
	if ( /^[A-Za-z0-9_-]{6,}$/.test( trimmed ) ) {
		const mediaId = shortcodeToMediaId( trimmed );
		if ( mediaId ) {
			return { platform: 'instagram', mediaId };
		}
	}

	return { platform: 'instagram', mediaId: trimmed };
};

interface GiveawayRules {
	requireTag: boolean;
	minTags: number;
	winnerCount: number;
}

interface GiveawayWinner {
	comment: SocialComment;
	index: number;
}

interface GiveawayStats {
	totalComments: number;
	validEntries: number;
	filteredOut: number;
	uniqueUsers: number;
}

/**
 * Apply giveaway rules to a list of comments.
 * Returns filtered valid entries and stats.
 */
const filterEntries = (
	comments: SocialComment[],
	rules: GiveawayRules,
	excludeUsernames: string[] = []
): { entries: SocialComment[]; stats: GiveawayStats } => {
	const seen = new Set< string >();
	const entries: SocialComment[] = [];
	const excludeSet = new Set( excludeUsernames.map( ( u ) => u.toLowerCase() ) );

	for ( const comment of comments ) {
		const username = ( comment.author_username || '' ).toLowerCase();

		// Exclude previously drawn winners.
		if ( excludeSet.has( username ) ) {
			continue;
		}

		// Deduplicate by username (one entry per person).
		if ( seen.has( username ) ) {
			continue;
		}

		// Must have @mentions if required.
		if ( rules.requireTag && ( comment.mentions?.length ?? 0 ) < rules.minTags ) {
			continue;
		}

		seen.add( username );
		entries.push( comment );
	}

	return {
		entries,
		stats: {
			totalComments: comments.length,
			validEntries: entries.length,
			filteredOut: comments.length - entries.length,
			uniqueUsers: seen.size,
		},
	};
};

/**
 * Crypto-secure random selection of winners from entries.
 */
const pickWinners = ( entries: SocialComment[], count: number ): GiveawayWinner[] => {
	if ( entries.length === 0 || count <= 0 ) {
		return [];
	}

	const actualCount = Math.min( count, entries.length );
	const indices = new Set< number >();

	// Use crypto.getRandomValues for unbiased selection.
	const randomArray = new Uint32Array( actualCount * 3 ); // Extra entropy.
	crypto.getRandomValues( randomArray );

	let randomIndex = 0;
	while ( indices.size < actualCount && randomIndex < randomArray.length ) {
		const idx = randomArray[ randomIndex ] % entries.length;
		indices.add( idx );
		randomIndex++;
	}

	// Fallback if crypto doesn't give enough unique values (extremely unlikely).
	while ( indices.size < actualCount ) {
		indices.add( Math.floor( Math.random() * entries.length ) );
	}

	return Array.from( indices ).map( ( idx ) => ( {
		comment: entries[ idx ],
		index: idx,
	} ) );
};

const GiveawayPane = ( _props: StudioPaneProps ): ReactElement => {
	// Input state.
	const [ mediaInput, setMediaInput ] = useState( '' );
	const [ platform, setPlatform ] = useState( 'instagram' );

	// Rules state.
	const [ rules, setRules ] = useState< GiveawayRules >( {
		requireTag: true,
		minTags: 1,
		winnerCount: 1,
	} );

	// Data state.
	const [ allComments, setAllComments ] = useState< SocialComment[] >( [] );
	const [ stats, setStats ] = useState< GiveawayStats | null >( null );
	const [ winners, setWinners ] = useState< GiveawayWinner[] >( [] );
	const [ excludedWinners, setExcludedWinners ] = useState< string[] >( [] );

	// UI state.
	const [ isLoading, setIsLoading ] = useState( false );
	const [ isDrawing, setIsDrawing ] = useState( false );
	const [ isAnnouncing, setIsAnnouncing ] = useState( '' ); // comment_id being announced.
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ hasPreview, setHasPreview ] = useState( false );

	const updateRule = < K extends keyof GiveawayRules >( key: K, value: GiveawayRules[K] ): void => {
		setRules( ( current ) => ( { ...current, [ key ]: value } ) );
		// Re-filter if we already have comments.
		if ( allComments.length > 0 ) {
			const newRules = { ...rules, [ key ]: value };
			const { stats: newStats } = filterEntries( allComments, newRules, excludedWinners );
			setStats( newStats );
		}
	};

	/**
	 * Fetch all comments and compute preview stats.
	 */
	const loadPreview = async (): Promise< void > => {
		const parsed = parseMediaInput( mediaInput );

		if ( ! parsed || ! parsed.mediaId ) {
			setError( __( 'Paste an Instagram post URL or numeric media ID.', 'extrachill-studio' ) );
			return;
		}

		setPlatform( parsed.platform );
		setIsLoading( true );
		setError( '' );
		setStatus( __( 'Fetching all comments…', 'extrachill-studio' ) );
		setWinners( [] );
		setExcludedWinners( [] );

		try {
			const response = await studioSocialsApi.getAllComments( parsed.platform, parsed.mediaId );

			if ( ! response.success ) {
				throw new Error( response.error || __( 'Failed to fetch comments.', 'extrachill-studio' ) );
			}

			const comments = response.data.comments || [];
			setAllComments( comments );

			const { stats: newStats } = filterEntries( comments, rules );
			setStats( newStats );
			setHasPreview( true );

			const pageInfo = response.data.pages ? sprintf( __( ' (%d pages)', 'extrachill-studio' ), response.data.pages ) : '';
			const partialInfo = response.data.partial ? ' ' + __( '(partial — some pages could not be fetched)', 'extrachill-studio' ) : '';

			setStatus( sprintf(
				__( 'Loaded %d comments%s%s', 'extrachill-studio' ),
				comments.length,
				pageInfo,
				partialInfo
			) );
		} catch ( fetchError ) {
			setError( ( fetchError as Error )?.message || __( 'Failed to fetch comments.', 'extrachill-studio' ) );
			setStatus( '' );
		} finally {
			setIsLoading( false );
		}
	};

	/**
	 * Draw winners from filtered entries.
	 */
	const drawWinners = (): void => {
		setIsDrawing( true );
		setError( '' );

		const { entries, stats: newStats } = filterEntries( allComments, rules, excludedWinners );
		setStats( newStats );

		if ( entries.length === 0 ) {
			setError( __( 'No valid entries after applying rules.', 'extrachill-studio' ) );
			setIsDrawing( false );
			return;
		}

		const selected = pickWinners( entries, rules.winnerCount );
		setWinners( selected );

		setStatus( sprintf(
			__( 'Drew %d winner(s) from %d valid entries.', 'extrachill-studio' ),
			selected.length,
			entries.length
		) );

		setIsDrawing( false );
	};

	/**
	 * Re-draw: exclude current winners and pick again.
	 */
	const redraw = (): void => {
		const currentWinnerUsernames = winners.map( ( w ) => w.comment.author_username );
		setExcludedWinners( ( current ) => [ ...current, ...currentWinnerUsernames ] );
		setWinners( [] );

		// Use setTimeout to let state update before re-drawing.
		setTimeout( () => drawWinners(), 0 );
	};

	/**
	 * Announce winner by replying to their comment.
	 */
	const announceWinner = async ( winner: GiveawayWinner ): Promise< void > => {
		setIsAnnouncing( winner.comment.id );
		setError( '' );

		const message = sprintf(
			__( 'Congratulations @%s, you won the giveaway! Check your DMs for details.', 'extrachill-studio' ),
			winner.comment.author_username
		);

		try {
			const response = await studioSocialsApi.replyToComment( platform, winner.comment.id, message );

			if ( ! response.success ) {
				throw new Error( response.error || __( 'Failed to post announcement.', 'extrachill-studio' ) );
			}

			setStatus( sprintf(
				__( 'Winner announced! Replied to @%s\'s comment.', 'extrachill-studio' ),
				winner.comment.author_username
			) );
		} catch ( replyError ) {
			setError( ( replyError as Error )?.message || __( 'Failed to announce winner.', 'extrachill-studio' ) );
		} finally {
			setIsAnnouncing( '' );
		}
	};

	return h(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--giveaway' },

		// ── Setup Panel ──
		h(
			PanelView,
			{ className: 'ec-studio-panel', compact: true },
			h( PanelHeader, {
				description: __( 'Pick a random winner from Instagram post comments. Paste a post URL, load the comments, and draw.', 'extrachill-studio' ),
			} ),
			h(
				'div',
				{ className: 'ec-studio-composer' },

				h(
					FieldGroupView,
					{ label: __( 'Instagram Post', 'extrachill-studio' ), htmlFor: 'ec-studio-giveaway-post' },
					createElement( 'input', {
						id: 'ec-studio-giveaway-post',
						type: 'text',
						value: mediaInput,
						onChange: ( event: ChangeEvent< HTMLInputElement > ) => {
							setMediaInput( event.target.value );
							setHasPreview( false );
						},
						placeholder: __( 'https://www.instagram.com/p/... or numeric media ID', 'extrachill-studio' ),
					} )
				),

				// Rules.
				h(
					FieldGroupView,
					{ label: __( 'Rules', 'extrachill-studio' ) },
					createElement(
						'div',
						{ className: 'ec-studio-giveaway-rules' },
						createElement( 'label', { className: 'ec-studio-giveaway-rules__rule' },
							createElement( 'input', {
								type: 'checkbox',
								checked: rules.requireTag,
								onChange: ( event: ChangeEvent< HTMLInputElement > ) => updateRule( 'requireTag', event.target.checked ),
							} ),
							__( 'Must tag a friend', 'extrachill-studio' ),
							rules.requireTag
								? h( 'span', { className: 'ec-studio-giveaway-rules__inline' },
									__( ' (min:', 'extrachill-studio' ),
									createElement( 'input', {
										type: 'number',
										min: 1,
										max: 10,
										value: rules.minTags,
										onChange: ( event: ChangeEvent< HTMLInputElement > ) => updateRule( 'minTags', Math.max( 1, parseInt( event.target.value, 10 ) || 1 ) ),
										className: 'ec-studio-giveaway-rules__number',
									} ),
									')'
								)
								: null
						),
					)
				),

				h(
					FieldGroupView,
					{ label: __( 'Number of Winners', 'extrachill-studio' ), htmlFor: 'ec-studio-giveaway-winners' },
					createElement( 'input', {
						id: 'ec-studio-giveaway-winners',
						type: 'number',
						min: 1,
						max: 50,
						value: rules.winnerCount,
						onChange: ( event: ChangeEvent< HTMLInputElement > ) => updateRule( 'winnerCount', Math.max( 1, parseInt( event.target.value, 10 ) || 1 ) ),
					} )
				),

				// Actions.
				h(
					ActionRowView,
					{ className: 'ec-studio-composer__actions' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium',
							onClick: loadPreview,
							disabled: isLoading || ! mediaInput.trim(),
						},
						isLoading ? __( 'Loading…', 'extrachill-studio' ) : __( 'Load Comments', 'extrachill-studio' )
					),
					hasPreview
						? createElement(
							'button',
							{
								type: 'button',
								className: 'button-1 button-medium',
								onClick: drawWinners,
								disabled: isDrawing || ! stats || stats.validEntries === 0,
							},
							isDrawing ? __( 'Drawing…', 'extrachill-studio' ) : __( 'Draw Winner', 'extrachill-studio' )
						)
						: null
				)
			),
			error ? h( InlineStatusView, { tone: 'error', className: 'ec-studio-message' }, error ) : null,
			! error && status ? h( InlineStatusView, { tone: 'success', className: 'ec-studio-message' }, status ) : null
		),

		// ── Stats Panel ──
		stats
			? h(
				PanelView,
				{ className: 'ec-studio-panel', compact: true },
				h( PanelHeader, { description: __( 'Entry breakdown after applying rules.', 'extrachill-studio' ) } ),
				createElement(
					'div',
					{ className: 'ec-studio-giveaway-stats' },
					createElement( 'div', { className: 'ec-studio-giveaway-stats__item' },
						createElement( 'span', { className: 'ec-studio-giveaway-stats__value' }, String( stats.totalComments ) ),
						createElement( 'span', { className: 'ec-studio-giveaway-stats__label' }, __( 'Total Comments', 'extrachill-studio' ) )
					),
					createElement( 'div', { className: 'ec-studio-giveaway-stats__item' },
						createElement( 'span', { className: 'ec-studio-giveaway-stats__value' }, String( stats.validEntries ) ),
						createElement( 'span', { className: 'ec-studio-giveaway-stats__label' }, __( 'Valid Entries', 'extrachill-studio' ) )
					),
					createElement( 'div', { className: 'ec-studio-giveaway-stats__item' },
						createElement( 'span', { className: 'ec-studio-giveaway-stats__value' }, String( stats.filteredOut ) ),
						createElement( 'span', { className: 'ec-studio-giveaway-stats__label' }, __( 'Filtered Out', 'extrachill-studio' ) )
					),
					createElement( 'div', { className: 'ec-studio-giveaway-stats__item' },
						createElement( 'span', { className: 'ec-studio-giveaway-stats__value' }, String( stats.uniqueUsers ) ),
						createElement( 'span', { className: 'ec-studio-giveaway-stats__label' }, __( 'Unique Users', 'extrachill-studio' ) )
					)
				)
			)
			: null,

		// ── Winners Panel ──
		winners.length > 0
			? h(
				PanelView,
				{ className: 'ec-studio-panel', compact: true },
				h( PanelHeader, { description: __( 'Giveaway results — announce winners by replying to their comment.', 'extrachill-studio' ) } ),
				createElement(
					'ul',
					{ className: 'ec-studio-giveaway-winners' },
					...winners.map( ( winner, i ) => createElement(
						'li',
						{ key: winner.comment.id, className: 'ec-studio-giveaway-winners__item' },
						createElement(
							'div',
							{ className: 'ec-studio-giveaway-winners__header' },
							createElement( 'span', { className: 'ec-studio-giveaway-winners__rank' },
								sprintf( __( 'Winner #%d', 'extrachill-studio' ), i + 1 )
							),
							createElement( 'strong', { className: 'ec-studio-giveaway-winners__username' },
								`@${ winner.comment.author_username }`
							)
						),
						createElement( 'p', { className: 'ec-studio-giveaway-winners__text' }, winner.comment.text ),
						winner.comment.mentions.length > 0
							? createElement( 'p', { className: 'ec-studio-giveaway-winners__mentions' },
								__( 'Tagged: ', 'extrachill-studio' ) + winner.comment.mentions.map( ( m ) => `@${ m }` ).join( ', ' )
							)
							: null,
						h(
							ActionRowView,
							{ className: 'ec-studio-composer__actions' },
							createElement(
								'button',
								{
									type: 'button',
									className: 'button-1 button-medium',
									onClick: () => announceWinner( winner ),
									disabled: isAnnouncing === winner.comment.id,
								},
								isAnnouncing === winner.comment.id
									? __( 'Announcing…', 'extrachill-studio' )
									: __( 'Announce Winner', 'extrachill-studio' )
							)
						)
					) )
				),
				h(
					ActionRowView,
					{ className: 'ec-studio-composer__actions' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-2 button-medium',
							onClick: redraw,
						},
						__( 'Re-draw (exclude current winners)', 'extrachill-studio' )
					)
				)
			)
			: null
	);
};

export default GiveawayPane;
