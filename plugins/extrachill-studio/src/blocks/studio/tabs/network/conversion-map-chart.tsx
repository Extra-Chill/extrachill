/**
 * Conversion map — top entry articles and the share of their visitors that
 * reach a platform surface (events / community / artist).
 *
 * Reads GET /extrachill/v1/analytics/conversion-map. Rendered as a ranked table
 * (DataTable) rather than a chart because the signal is per-article rates with
 * labels — a table reads better than a bar here and keeps the entry-article
 * titles legible. The overall reach rate is the headline.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import {
	DataTable,
	StatGroup,
	StatTile,
	type DataTableColumn,
} from '@extrachill/components';

import { studioAnalyticsApi } from '../../app/client';
import type {
	ConversionMapResponse,
	ConversionRow,
} from '../../types/analytics';
import { ChartCard, type ChartCardState } from './chart-card';

const pct = ( rate: number ): string => `${ Math.round( rate * 1000 ) / 10 }%`;

interface ArticleTableRow extends Record< string, unknown > {
	id: number;
	title: string;
	entry_sessions: number;
	reached_any_rate: string;
}

export const ConversionMapChart = (): ReactElement => {
	const [ data, setData ] = useState< ConversionMapResponse | null >( null );
	const [ state, setState ] = useState< ChartCardState >( 'loading' );
	const [ errorMessage, setErrorMessage ] = useState( '' );

	useEffect( () => {
		let cancelled = false;

		studioAnalyticsApi
			.getConversionMap( {
				days: 28,
				top_articles: 10,
				min_entry_sessions: 1,
			} )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}
				setData( response );
				const hasArticles = ( response.by_article?.length ?? 0 ) > 0;
				setState( hasArticles ? 'ready' : 'empty' );
			} )
			.catch( ( error: unknown ) => {
				if ( cancelled ) {
					return;
				}
				setErrorMessage( ( error as Error )?.message || '' );
				setState( 'error' );
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	const rows = useMemo< ArticleTableRow[] >( () => {
		const articles = data?.by_article ?? [];
		return articles.map( ( row: ConversionRow, index ) => ( {
			id: row.post_id ?? index,
			title: row.title || __( '(untitled)', 'extrachill-studio' ),
			entry_sessions: row.entry_sessions,
			reached_any_rate: pct( row.reached_any_rate ),
		} ) );
	}, [ data ] );

	const columns: DataTableColumn< ArticleTableRow >[] = [
		{ key: 'title', label: __( 'Entry article', 'extrachill-studio' ) },
		{
			key: 'entry_sessions',
			label: __( 'Sessions', 'extrachill-studio' ),
			width: '90px',
		},
		{
			key: 'reached_any_rate',
			label: __( 'Reached platform', 'extrachill-studio' ),
			width: '140px',
		},
	];

	const headline = data ? (
		<StatGroup>
			<StatTile
				value={ pct( data.overall.reached_any_rate ) }
				label={ __( 'Overall reach to platform', 'extrachill-studio' ) }
			/>
			<StatTile
				value={ data.overall.entry_sessions.toLocaleString() }
				label={ __( 'Editorial entry sessions', 'extrachill-studio' ) }
				tone="muted"
			/>
		</StatGroup>
	) : null;

	return (
		<ChartCard
			title={ __( 'Top content → platform', 'extrachill-studio' ) }
			description={ __(
				'Visitors who entered on an article and reached events, community, or artist — last 28 days.',
				'extrachill-studio'
			) }
			className="ec-studio-network__card--wide"
			state={ state }
			errorMessage={ errorMessage }
			emptyMessage={ __(
				'No editorial-entry sessions recorded in this window yet.',
				'extrachill-studio'
			) }
			headline={ headline }
		>
			<DataTable< ArticleTableRow >
				columns={ columns }
				data={ rows }
				rowKey="id"
				emptyMessage={ __(
					'No ranked entry articles yet.',
					'extrachill-studio'
				) }
			/>
		</ChartCard>
	);
};

export default ConversionMapChart;
