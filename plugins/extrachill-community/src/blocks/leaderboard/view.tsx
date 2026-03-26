import { useState, useEffect, useCallback } from '@wordpress/element';
import { createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { ExtraChillClient } from '@extrachill/api-client';
import { WpApiFetchTransport } from '@extrachill/api-client/wordpress';
import { ActionRow, BlockShell, BlockShellHeader, BlockShellInner, InlineStatus, Panel } from '@extrachill/components';
import '@extrachill/components/styles/components.scss';
import { cssVar, spacing, colors, fontSize } from '@extrachill/tokens';
import type { LeaderboardResponse, LeaderboardEntry } from '@extrachill/api-client';

const client = new ExtraChillClient( new WpApiFetchTransport( apiFetch ) );

// ─── Styles ──────────────────────────────────────────────────────────────────

const styles = {
	table: {
		width: '100%',
		borderCollapse: 'collapse' as const,
	},
	th: {
		padding: `${ cssVar( spacing.spacingSm ) } ${ cssVar( spacing.spacingMd ) }`,
		borderBottom: `1px solid ${ cssVar( colors.borderColor ) }`,
		textAlign: 'left' as const,
	},
	td: {
		padding: `${ cssVar( spacing.spacingSm ) } ${ cssVar( spacing.spacingMd ) }`,
		borderBottom: `1px solid ${ cssVar( colors.borderColor ) }`,
		textAlign: 'left' as const,
	},
	pagination: {
		display: 'flex',
		gap: cssVar( spacing.spacingMd ),
		alignItems: 'center',
		justifyContent: 'space-between',
		marginTop: cssVar( spacing.spacingMd ),
	},
	pageLabel: {
		color: cssVar( colors.mutedText ),
		fontSize: cssVar( fontSize.fontSizeBase ),
	},
	headerRegion: {
		display: 'grid',
		gap: cssVar( spacing.spacingLg ),
	},
	tableWrap: {
		overflowX: 'auto' as const,
	},
} as const;

// ─── Sub-components ──────────────────────────────────────────────────────────

interface BadgeProps {
	icon: string;
	className: string;
	title: string;
	spriteUrl: string;
}

function Badge( { icon, className, title, spriteUrl }: BadgeProps ) {
	return (
		<span className={ className } data-title={ title }>
			<svg className="ec-icon">
				<use href={ `${ spriteUrl }#${ icon }` } />
			</svg>
		</span>
	);
}

interface UserCellProps {
	item: LeaderboardEntry;
	spriteUrl: string;
}

function UserCell( { item, spriteUrl }: UserCellProps ) {
	const name = item.display_name || item.username || '';
	const nameEl = item.profile_url ? (
		<a href={ item.profile_url }>{ name }</a>
	) : (
		<>{ name }</>
	);

	const hasBadges = Array.isArray( item.badges ) && item.badges.length > 0 && spriteUrl;

	return (
		<td style={ styles.td }>
			{ nameEl }
			{ hasBadges && (
				<span className="ec-user-badges">
					{ item.badges.map( ( badge, i ) => {
						if ( ! badge?.icon || ! badge?.class_name || ! badge?.title ) {
							return null;
						}
						return (
							<Badge
								key={ i }
								icon={ badge.icon }
								className={ badge.class_name }
								title={ badge.title }
								spriteUrl={ spriteUrl }
							/>
						);
					} ) }
				</span>
			) }
		</td>
	);
}

function formatDate( iso: string ): string {
	const date = new Date( iso );
	if ( Number.isNaN( date.getTime() ) ) {
		return '';
	}
	return date.toLocaleDateString();
}

// ─── Main component ──────────────────────────────────────────────────────────

interface LeaderboardProps {
	perPage: number;
	spriteUrl: string;
}

function Leaderboard( { perPage, spriteUrl }: LeaderboardProps ) {
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState< LeaderboardResponse | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	const load = useCallback( ( targetPage: number ) => {
		setLoading( true );
		setError( null );
		client.users
			.leaderboard( targetPage, perPage )
			.then( ( response ) => {
				setData( response );
				setLoading( false );
			} )
			.catch( () => {
				setError( 'Unable to load leaderboard.' );
				setLoading( false );
			} );
	}, [ perPage ] );

	useEffect( () => {
		load( page );
	}, [ page, load ] );

	if ( loading ) {
		return <InlineStatus tone="info">Loading leaderboard…</InlineStatus>;
	}

	if ( error ) {
		return <InlineStatus tone="error">{ error }</InlineStatus>;
	}

	if ( ! data || ! Array.isArray( data.items ) ) {
		return <InlineStatus tone="info">No leaderboard data.</InlineStatus>;
	}

	const totalPages = data.pagination?.total_pages ?? 1;

	return (
		<BlockShell className="ec-community-leaderboard-shell">
			<BlockShellInner className="ec-community-leaderboard-shell__inner" maxWidth="narrow">
				<div style={ styles.headerRegion }>
				<BlockShellHeader
					title="Leaderboard"
					description="See the most active members of the Extra Chill community."
				/>
				<Panel depth={ 1 }>
					<div style={ styles.tableWrap }>
						<table style={ styles.table }>
							<thead>
								<tr>
									<th style={ styles.th }>#</th>
									<th style={ styles.th }>User</th>
									<th style={ styles.th }>Points</th>
									<th style={ styles.th }>Rank</th>
									<th style={ styles.th }>Joined</th>
								</tr>
							</thead>
							<tbody>
								{ data.items.map( ( item ) => (
									<tr key={ item.id }>
										<td style={ styles.td }>{ item.position }</td>
										<UserCell item={ item } spriteUrl={ spriteUrl } />
										<td style={ styles.td }>{ item.points }</td>
										<td style={ styles.td }>{ item.rank }</td>
										<td style={ styles.td }>{ item.registered ? formatDate( item.registered ) : '' }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					<ActionRow align="between">
						<button
							type="button"
							className="button-3 button-small"
							disabled={ page <= 1 }
							onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
						>
							Previous
						</button>
						<span style={ styles.pageLabel }>
							Page { page } of { totalPages }
						</span>
						<button
							type="button"
							className="button-3 button-small"
							disabled={ page >= totalPages }
							onClick={ () => setPage( ( p ) => p + 1 ) }
						>
							Next
						</button>
					</ActionRow>
				</Panel>
				</div>
			</BlockShellInner>
		</BlockShell>
	);
}

// ─── Hydration ───────────────────────────────────────────────────────────────

function init(): void {
	document
		.querySelectorAll< HTMLElement >( '.wp-block-extrachill-leaderboard' )
		.forEach( ( container ) => {
			if ( container.dataset.initialized === '1' ) {
				return;
			}
			container.dataset.initialized = '1';

			const perPage = Math.max(
				1,
				Math.min( 100, parseInt( container.dataset.perPage || '25', 10 ) ),
			);
			const spriteUrl = container.dataset.spriteUrl || '';

			const root = createRoot( container );
			root.render( <Leaderboard perPage={ perPage } spriteUrl={ spriteUrl } /> );
		} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
