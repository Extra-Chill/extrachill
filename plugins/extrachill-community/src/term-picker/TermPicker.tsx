/**
 * Edit-only taxonomy correction using network-owned term abilities.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { NetworkTerm, TaxonomyConfig, Term } from './types';

const SEARCH_DEBOUNCE_MS = 250;
const MAX_SUGGESTIONS = 10;
const SEARCH_ABILITY = 'extrachill/search-network-terms';
const PROJECT_ABILITY = 'extrachill/project-network-term';

interface TermPickerProps {
	config: TaxonomyConfig;
	topicId: number;
}

interface SearchResponse {
	terms: NetworkTerm[];
}

interface ProjectionResponse {
	taxonomy: string;
	slug: string;
	term_id: number;
}

function abilityPath( name: string ): string {
	return `/wp-abilities/v1/abilities/${ name }/run`;
}

export function buildNetworkSearchPath(
	taxonomy: string,
	search: string
): string {
	const input = {
		site: 'community',
		post_type: 'topic',
		taxonomy,
		query: search,
		limit: MAX_SUGGESTIONS,
	};

	return `${ abilityPath( SEARCH_ABILITY ) }?input=${ encodeURIComponent(
		JSON.stringify( input )
	) }`;
}

export function projectNetworkTerm(
	topicId: number,
	term: NetworkTerm
): Promise< ProjectionResponse > {
	return apiFetch< ProjectionResponse >( {
		path: abilityPath( PROJECT_ABILITY ),
		method: 'POST',
		data: {
			input: {
				site: 'community',
				post_id: topicId,
				taxonomy: term.taxonomy,
				slug: term.slug,
			},
		},
	} );
}

export function TermPicker( { config, topicId }: TermPickerProps ) {
	const {
		taxonomy,
		label,
		placeholder,
		field,
		selected: initialSelected,
	} = config;
	const [ query, setQuery ] = useState( '' );
	const [ suggestions, setSuggestions ] = useState< NetworkTerm[] >( [] );
	const [ selected, setSelected ] = useState< Term[] >( initialSelected );
	const [ loading, setLoading ] = useState( false );
	const [ projecting, setProjecting ] = useState( false );
	const [ open, setOpen ] = useState( false );
	const [ activeIndex, setActiveIndex ] = useState( -1 );
	const [ unavailable, setUnavailable ] = useState( false );
	const wrapperRef = useRef< HTMLDivElement | null >( null );
	const searchSequence = useRef( 0 );

	const selectedSlugs = useMemo(
		() => new Set( selected.map( ( term ) => term.slug ) ),
		[ selected ]
	);
	const visibleSuggestions = useMemo(
		() =>
			suggestions.filter( ( term ) => ! selectedSlugs.has( term.slug ) ),
		[ suggestions, selectedSlugs ]
	);

	useEffect( () => {
		const trimmed = query.trim();
		const sequence = ++searchSequence.current;

		if ( trimmed.length < 1 ) {
			setSuggestions( [] );
			setLoading( false );
			return;
		}

		setLoading( true );
		setUnavailable( false );
		const timeout = setTimeout( () => {
			apiFetch< SearchResponse >( {
				path: buildNetworkSearchPath( taxonomy, trimmed ),
				method: 'GET',
			} )
				.then( ( response ) => {
					if ( sequence !== searchSequence.current ) {
						return;
					}
					const terms = Array.isArray( response.terms )
						? response.terms
						: [];
					setSuggestions( terms );
					setActiveIndex( terms.length > 0 ? 0 : -1 );
				} )
				.catch( () => {
					if ( sequence !== searchSequence.current ) {
						return;
					}
					setSuggestions( [] );
					setActiveIndex( -1 );
					setUnavailable( true );
				} )
				.finally( () => {
					if ( sequence === searchSequence.current ) {
						setLoading( false );
					}
				} );
		}, SEARCH_DEBOUNCE_MS );

		return () => clearTimeout( timeout );
	}, [ query, taxonomy ] );

	useEffect( () => {
		function closeOnOutsideClick( event: MouseEvent ) {
			if (
				wrapperRef.current &&
				! wrapperRef.current.contains( event.target as Node )
			) {
				setOpen( false );
			}
		}

		document.addEventListener( 'mousedown', closeOnOutsideClick );
		return () =>
			document.removeEventListener( 'mousedown', closeOnOutsideClick );
	}, [] );

	const addTerm = useCallback(
		async ( term: NetworkTerm ) => {
			setProjecting( true );
			setUnavailable( false );
			try {
				const projection = await projectNetworkTerm( topicId, term );
				if ( projection.term_id < 1 ) {
					throw new Error( 'Missing local term ID' );
				}
				setSelected( ( current ) =>
					current.some( ( item ) => item.id === projection.term_id )
						? current
						: [
								...current,
								{
									id: projection.term_id,
									name: term.name,
									slug: projection.slug,
								},
						  ]
				);
				setQuery( '' );
				setSuggestions( [] );
				setActiveIndex( -1 );
				setOpen( false );
			} catch {
				setUnavailable( true );
			} finally {
				setProjecting( false );
			}
		},
		[ topicId ]
	);

	const removeTerm = useCallback( ( id: number ) => {
		setSelected( ( current ) =>
			current.filter( ( term ) => term.id !== id )
		);
	}, [] );

	const onKeyDown = useCallback(
		( event: React.KeyboardEvent< HTMLInputElement > ) => {
			if ( ! open || visibleSuggestions.length === 0 ) {
				if ( event.key === 'ArrowDown' && query.trim().length > 0 ) {
					setOpen( true );
				}
				return;
			}

			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					setActiveIndex(
						( index ) => ( index + 1 ) % visibleSuggestions.length
					);
					break;
				case 'ArrowUp':
					event.preventDefault();
					setActiveIndex(
						( index ) =>
							( index - 1 + visibleSuggestions.length ) %
							visibleSuggestions.length
					);
					break;
				case 'Enter':
					if (
						activeIndex >= 0 &&
						activeIndex < visibleSuggestions.length &&
						! projecting
					) {
						event.preventDefault();
						void addTerm( visibleSuggestions[ activeIndex ] );
					}
					break;
				case 'Escape':
					setOpen( false );
					break;
			}
		},
		[ activeIndex, addTerm, open, projecting, query, visibleSuggestions ]
	);

	const listboxId = `ec-term-picker-listbox-${ taxonomy }`;
	const inputId = `ec-term-picker-input-${ taxonomy }`;

	return (
		<div
			className="ec-term-picker"
			ref={ wrapperRef }
			data-taxonomy={ taxonomy }
		>
			<input type="hidden" name={ `${ field }_submitted` } value="1" />
			{ selected.map( ( term ) => (
				<input
					key={ term.id }
					type="hidden"
					name={ `${ field }[]` }
					value={ term.id }
				/>
			) ) }

			<label className="ec-term-picker__label" htmlFor={ inputId }>
				{ label }
			</label>

			{ selected.length > 0 && (
				<ul
					className="ec-term-picker__chips"
					aria-label={ sprintf(
						/* translators: %s: taxonomy label, e.g. Location */
						__( 'Selected %s terms', 'extra-chill-community' ),
						label
					) }
				>
					{ selected.map( ( term ) => (
						<li key={ term.id } className="ec-term-picker__chip">
							<span className="ec-term-picker__chip-label">
								{ term.name }
							</span>
							<button
								type="button"
								className="ec-term-picker__chip-remove"
								aria-label={ sprintf(
									/* translators: %s: term name */
									__( 'Remove %s', 'extra-chill-community' ),
									term.name
								) }
								onClick={ () => removeTerm( term.id ) }
							>
								&times;
							</button>
						</li>
					) ) }
				</ul>
			) }

			<div className="ec-term-picker__control">
				<input
					id={ inputId }
					type="text"
					className="ec-term-picker__input"
					value={ query }
					placeholder={ placeholder }
					autoComplete="off"
					role="combobox"
					aria-expanded={ open && visibleSuggestions.length > 0 }
					aria-controls={ listboxId }
					aria-autocomplete="list"
					disabled={ projecting }
					onChange={ ( event ) => {
						setQuery( event.target.value );
						setOpen( true );
					} }
					onFocus={ () => {
						if ( query.trim().length > 0 ) {
							setOpen( true );
						}
					} }
					onKeyDown={ onKeyDown }
				/>

				{ unavailable && (
					<p className="ec-term-picker__status" role="status">
						{ __(
							'Term suggestions are unavailable. You can still save your topic.',
							'extra-chill-community'
						) }
					</p>
				) }

				{ open && query.trim().length > 0 && ! unavailable && (
					<ul
						id={ listboxId }
						className="ec-term-picker__suggestions"
						role="listbox"
					>
						{ ( loading || projecting ) && (
							<li
								className="ec-term-picker__suggestion ec-term-picker__suggestion--status"
								role="option"
								aria-disabled="true"
							>
								{ projecting
									? __(
											'Adding term…',
											'extra-chill-community'
									  )
									: __(
											'Searching…',
											'extra-chill-community'
									  ) }
							</li>
						) }
						{ ! loading &&
							! projecting &&
							visibleSuggestions.length === 0 && (
								<li
									className="ec-term-picker__suggestion ec-term-picker__suggestion--status"
									role="option"
									aria-disabled="true"
								>
									{ __(
										'No approved network terms found.',
										'extra-chill-community'
									) }
								</li>
							) }
						{ ! loading &&
							! projecting &&
							visibleSuggestions.map( ( term, index ) => (
								<li
									key={ `${ term.taxonomy }:${ term.slug }` }
									id={ `${ listboxId }-option-${ term.slug }` }
									className={
										'ec-term-picker__suggestion' +
										( index === activeIndex
											? ' is-active'
											: '' )
									}
									role="option"
									aria-selected={ index === activeIndex }
									onMouseEnter={ () =>
										setActiveIndex( index )
									}
									onMouseDown={ ( event ) => {
										event.preventDefault();
										void addTerm( term );
									} }
								>
									{ term.name }
								</li>
							) ) }
					</ul>
				) }
			</div>
		</div>
	);
}
