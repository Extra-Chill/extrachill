/**
 * Composer term-picker — curated, pick-from-existing, no freeform creation.
 *
 * Autocompletes against EXISTING curated network taxonomy terms via the WP
 * REST API (NO AJAX, per the system-wide rule). Users select terms as chips;
 * the picker writes hidden inputs (`${field}[]`) that the bbPress save handler
 * reads on submit. Typing a non-matching string creates NOTHING — the network's
 * curated taxonomy tree never drifts from the composer.
 *
 * The component is taxonomy-parameterized via TaxonomyConfig, so location today
 * and artist/festival/venue later share one implementation.
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import type { TaxonomyConfig, Term } from './types';

const SEARCH_DEBOUNCE_MS = 250;
const MAX_SUGGESTIONS = 10;

interface TermPickerProps {
	config: TaxonomyConfig;
}

/**
 * Build the REST search path for a taxonomy. Read-only: search only, the
 * picker never hits a write verb on this endpoint.
 * @param restBase
 * @param search
 */
function buildSearchPath( restBase: string, search: string ): string {
	const params = new URLSearchParams( {
		search,
		per_page: String( MAX_SUGGESTIONS ),
		_fields: 'id,name,parent',
		orderby: 'count',
		order: 'desc',
	} );
	return `/wp/v2/${ restBase }?${ params.toString() }`;
}

export function TermPicker( { config }: TermPickerProps ) {
	const {
		taxonomy,
		restBase,
		label,
		placeholder,
		hierarchical,
		field,
		selected: initialSelected,
	} = config;

	const [ query, setQuery ] = useState( '' );
	const [ suggestions, setSuggestions ] = useState< Term[] >( [] );
	const [ selected, setSelected ] = useState< Term[] >( initialSelected );
	const [ loading, setLoading ] = useState( false );
	const [ open, setOpen ] = useState( false );
	const [ activeIndex, setActiveIndex ] = useState( -1 );

	const wrapperRef = useRef< HTMLDivElement | null >( null );
	const debounceRef = useRef< ReturnType< typeof setTimeout > | null >(
		null
	);
	// Parent-id -> name cache so hierarchical suggestions can show "Term, Parent".
	const parentNameCache = useRef< Map< number, string > >( new Map() );

	const selectedIds = useMemo(
		() => new Set( selected.map( ( t ) => t.id ) ),
		[ selected ]
	);

	/**
	 * Resolve parent names for hierarchical context (e.g. "Charleston, South
	 * Carolina"). Read-only REST lookups, cached to avoid repeat fetches.
	 */
	const resolveParentNames = useCallback(
		async ( terms: Term[] ) => {
			if ( ! hierarchical ) {
				return;
			}
			const missing = Array.from(
				new Set(
					terms
						.map( ( t ) => t.parent )
						.filter(
							( id ) =>
								id > 0 && ! parentNameCache.current.has( id )
						)
				)
			);
			if ( missing.length === 0 ) {
				return;
			}
			const params = new URLSearchParams( {
				include: missing.join( ',' ),
				per_page: String( missing.length ),
				_fields: 'id,name',
			} );
			try {
				const parents = await apiFetch<
					Array< { id: number; name: string } >
				>( {
					path: `/wp/v2/${ restBase }?${ params.toString() }`,
				} );
				parents.forEach( ( p ) =>
					parentNameCache.current.set( p.id, p.name )
				);
				// Force a re-render so freshly resolved parent names appear.
				setSuggestions( ( prev ) => [ ...prev ] );
			} catch {
				// Non-fatal: suggestions still render without parent context.
			}
		},
		[ hierarchical, restBase ]
	);

	// Debounced REST search against existing terms.
	useEffect( () => {
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}

		const trimmed = query.trim();
		if ( trimmed.length < 1 ) {
			setSuggestions( [] );
			setLoading( false );
			return;
		}

		setLoading( true );
		debounceRef.current = setTimeout( () => {
			apiFetch< Term[] >( { path: buildSearchPath( restBase, trimmed ) } )
				.then( ( results ) => {
					setSuggestions( results );
					setActiveIndex( results.length > 0 ? 0 : -1 );
					void resolveParentNames( results );
				} )
				.catch( () => {
					setSuggestions( [] );
					setActiveIndex( -1 );
				} )
				.finally( () => setLoading( false ) );
		}, SEARCH_DEBOUNCE_MS );

		return () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		};
	}, [ query, restBase, resolveParentNames ] );

	// Close the suggestion list on outside click.
	useEffect( () => {
		function onDocClick( event: MouseEvent ) {
			if (
				wrapperRef.current &&
				! wrapperRef.current.contains( event.target as Node )
			) {
				setOpen( false );
			}
		}
		document.addEventListener( 'mousedown', onDocClick );
		return () => document.removeEventListener( 'mousedown', onDocClick );
	}, [] );

	const termLabel = useCallback(
		( term: Term ): string => {
			if ( hierarchical && term.parent > 0 ) {
				const parentName = parentNameCache.current.get( term.parent );
				if ( parentName ) {
					return `${ term.name }, ${ parentName }`;
				}
			}
			return term.name;
		},
		[ hierarchical ]
	);

	const addTerm = useCallback( ( term: Term ) => {
		setSelected( ( prev ) =>
			prev.some( ( t ) => t.id === term.id ) ? prev : [ ...prev, term ]
		);
		setQuery( '' );
		setSuggestions( [] );
		setActiveIndex( -1 );
		setOpen( false );
	}, [] );

	const removeTerm = useCallback( ( id: number ) => {
		setSelected( ( prev ) => prev.filter( ( t ) => t.id !== id ) );
	}, [] );

	const visibleSuggestions = useMemo(
		() => suggestions.filter( ( t ) => ! selectedIds.has( t.id ) ),
		[ suggestions, selectedIds ]
	);

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
						( i ) => ( i + 1 ) % visibleSuggestions.length
					);
					break;
				case 'ArrowUp':
					event.preventDefault();
					setActiveIndex(
						( i ) =>
							( i - 1 + visibleSuggestions.length ) %
							visibleSuggestions.length
					);
					break;
				case 'Enter':
					// Only commit an existing suggestion. Never mint a new term
					// from free text — curated taxonomy, pick-from-existing only.
					if (
						activeIndex >= 0 &&
						activeIndex < visibleSuggestions.length
					) {
						event.preventDefault();
						addTerm( visibleSuggestions[ activeIndex ] );
					}
					break;
				case 'Escape':
					setOpen( false );
					break;
			}
		},
		[ open, visibleSuggestions, activeIndex, addTerm, query ]
	);

	const listboxId = `ec-term-picker-listbox-${ taxonomy }`;
	const inputId = `ec-term-picker-input-${ taxonomy }`;

	return (
		<div
			className="ec-term-picker"
			ref={ wrapperRef }
			data-taxonomy={ taxonomy }
		>
			{ /* Hidden inputs the bbPress save handler reads on submit. An empty
			     marker guarantees the field is present even with zero selections
			     so the server can clear the relationship. */ }
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
								{ termLabel( term ) }
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
					onChange={ ( e ) => {
						setQuery( e.target.value );
						setOpen( true );
					} }
					onFocus={ () => {
						if ( query.trim().length > 0 ) {
							setOpen( true );
						}
					} }
					onKeyDown={ onKeyDown }
				/>

				{ open && query.trim().length > 0 && (
					<ul
						id={ listboxId }
						className="ec-term-picker__suggestions"
						role="listbox"
					>
						{ loading && (
							<li
								className="ec-term-picker__suggestion ec-term-picker__suggestion--status"
								aria-disabled="true"
							>
								{ __( 'Searching…', 'extra-chill-community' ) }
							</li>
						) }
						{ ! loading && visibleSuggestions.length === 0 && (
							<li
								className="ec-term-picker__suggestion ec-term-picker__suggestion--status"
								aria-disabled="true"
							>
								{ __(
									'No matching terms. You can only pick from existing terms.',
									'extra-chill-community'
								) }
							</li>
						) }
						{ ! loading &&
							visibleSuggestions.map( ( term, index ) => (
								<li
									key={ term.id }
									id={ `${ listboxId }-option-${ term.id }` }
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
									onMouseDown={ ( e ) => {
										// mousedown (not click) so it fires before input blur.
										e.preventDefault();
										addTerm( term );
									} }
								>
									{ termLabel( term ) }
								</li>
							) ) }
					</ul>
				) }
			</div>
		</div>
	);
}
