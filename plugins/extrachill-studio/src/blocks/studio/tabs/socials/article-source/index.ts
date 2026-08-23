import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useState } from '@wordpress/element';
import type { ChangeEvent, ReactElement } from 'react';
import {
	ActionRow,
	FieldGroup,
	InlineStatus,
	Panel,
} from '@extrachill/components';

import { articlePostsUrl, normalizeArticlePost } from './contract';
import type { ArticleSource, CoreArticlePost } from './contract';

const h = createElement as typeof import('react').createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const FieldGroupView = FieldGroup as unknown as ( props: any ) => ReactElement;
const ActionRowView = ActionRow as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as (
	props: any
) => ReactElement;
const SEARCH_DEBOUNCE_MS = 300;

interface ArticleSourcePickerProps {
	mainSiteUrl: string;
	mainBlogId: number;
	selected: ArticleSource | null;
	onSelect: ( source: ArticleSource | null ) => void;
}

const ArticleSourcePicker = ( {
	mainSiteUrl,
	mainBlogId,
	selected,
	onSelect,
}: ArticleSourcePickerProps ): ReactElement => {
	const [ search, setSearch ] = useState( '' );
	const [ activeSearch, setActiveSearch ] = useState( '' );
	const [ articles, setArticles ] = useState< ArticleSource[] >( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		const timer = window.setTimeout(
			() => setActiveSearch( search.trim() ),
			SEARCH_DEBOUNCE_MS
		);
		return () => window.clearTimeout( timer );
	}, [ search ] );

	useEffect( () => {
		const controller = new AbortController();
		const load = async (): Promise< void > => {
			setIsLoading( true );
			setError( '' );
			try {
				const response = await fetch(
					articlePostsUrl( mainSiteUrl, activeSearch ),
					{ signal: controller.signal, credentials: 'include' }
				);
				if ( ! response.ok ) {
					throw new Error(
						__(
							'Unable to load Extra Chill articles.',
							'extrachill-studio'
						)
					);
				}
				const posts = ( await response.json() ) as CoreArticlePost[];
				setArticles(
					( Array.isArray( posts ) ? posts : [] )
						.map( ( post ) =>
							normalizeArticlePost( post, mainBlogId )
						)
						.filter( ( post ): post is ArticleSource =>
							Boolean( post )
						)
				);
			} catch ( fetchError ) {
				if ( ! controller.signal.aborted ) {
					setArticles( [] );
					setError(
						( fetchError as Error )?.message ||
							__(
								'Unable to load Extra Chill articles.',
								'extrachill-studio'
							)
					);
				}
			} finally {
				if ( ! controller.signal.aborted ) {
					setIsLoading( false );
				}
			}
		};
		load();
		return () => controller.abort();
	}, [ activeSearch, mainBlogId, mainSiteUrl ] );

	let resultStatus: string | null = null;
	if ( isLoading ) {
		resultStatus = __( 'Loading articles…', 'extrachill-studio' );
	} else if ( ! error && articles.length === 0 ) {
		resultStatus = sprintf(
			/* translators: %s: current article search term. */
			__( 'No published articles found for “%s”.', 'extrachill-studio' ),
			activeSearch
		);
	}

	return h(
		PanelView,
		{
			className: 'ec-studio-panel ec-studio-article-source',
			compact: true,
		},
		h(
			FieldGroupView,
			{
				label: __(
					'Start from an Extra Chill article',
					'extrachill-studio'
				),
				htmlFor: 'ec-studio-article-source-search',
				help: __(
					'Search published articles or choose a recent post.',
					'extrachill-studio'
				),
			},
			h( 'input', {
				id: 'ec-studio-article-source-search',
				type: 'search',
				value: search,
				placeholder: __(
					'Search article titles…',
					'extrachill-studio'
				),
				onChange: ( event: ChangeEvent< HTMLInputElement > ) =>
					setSearch( event.target.value ),
			} )
		),
		selected
			? h(
					'div',
					{ className: 'ec-studio-article-source__selected' },
					selected.featuredMedia
						? h( 'img', {
								src: selected.featuredMedia.url,
								alt: selected.featuredMedia.alt || '',
						  } )
						: null,
					h(
						'div',
						null,
						h( 'strong', null, selected.title ),
						h(
							'p',
							{ className: 'ec-studio-composer__hint' },
							[ selected.author, formatDate( selected.date ) ]
								.filter( Boolean )
								.join( ' · ' )
						),
						h(
							'a',
							{
								href: selected.url,
								target: '_blank',
								rel: 'noreferrer',
							},
							__( 'View article', 'extrachill-studio' )
						)
					),
					h(
						'button',
						{
							type: 'button',
							className:
								'button-1 button-medium button-secondary',
							onClick: () => onSelect( null ),
						},
						__( 'Clear source', 'extrachill-studio' )
					)
			  )
			: null,
		error
			? h(
					InlineStatusView,
					{ tone: 'error', className: 'ec-studio-message' },
					error
			  )
			: null,
		h(
			'ul',
			{ className: 'ec-studio-article-source__results' },
			...articles.map( ( article ) =>
				h(
					'li',
					{ key: article.id },
					h(
						'button',
						{
							type: 'button',
							className:
								article.id === selected?.id
									? 'is-selected'
									: '',
							onClick: () => onSelect( article ),
							'aria-pressed': article.id === selected?.id,
						},
						article.featuredMedia
							? h( 'img', {
									src: article.featuredMedia.url,
									alt: article.featuredMedia.alt || '',
									loading: 'lazy',
							  } )
							: h( 'span', {
									className:
										'ec-studio-article-source__placeholder',
							  } ),
						h(
							'span',
							null,
							h( 'strong', null, article.title ),
							h(
								'small',
								null,
								[ article.author, formatDate( article.date ) ]
									.filter( Boolean )
									.join( ' · ' )
							),
							article.excerpt
								? h( 'span', null, article.excerpt )
								: null
						)
					)
				)
			)
		),
		h(
			ActionRowView,
			{ className: 'ec-studio-article-source__status' },
			resultStatus
		)
	);
};

const formatDate = ( value: string ): string => {
	if ( ! value ) {
		return '';
	}
	const date = new Date( value );
	return Number.isNaN( date.getTime() )
		? ''
		: new Intl.DateTimeFormat( undefined, { dateStyle: 'medium' } ).format(
				date
		  );
};

export default ArticleSourcePicker;
