import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactElement } from 'react';
import type { StudioPaneProps } from '../../types/studio';

interface Submission {
	id: number;
	title: string;
	author: string;
	submitted_at: string;
	modified_gmt: string;
	edit_url?: string;
	content?: string;
	featured_image?: string;
	categories?: string[];
	tags?: string[];
}

const run = < T, >( action: string, input: Record< string, unknown > = {} ): Promise< T > =>
	apiFetch( {
		path: `/wp-abilities/v1/abilities/extrachill/${ action }-review-submissions/run?${ new URLSearchParams( { input: JSON.stringify( input ) } ).toString() }`,
		method: 'GET',
	} );

export default function ReviewPane( { context }: StudioPaneProps ): ReactElement | null {
	const [ rows, setRows ] = useState< Submission[] >( [] );
	const [ article, setArticle ] = useState< Submission | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		if ( ! context.canReviewSubmissions ) {
			return;
		}
		let active = true;
		run< Submission[] >( 'list' ).then( ( result ) => {
			if ( active ) {
				setRows( result );
			}
		} ).catch( () => {
			if ( active ) {
				setError( __( 'Unable to load submissions.', 'extrachill-studio' ) );
			}
		} ).finally( () => {
			if ( active ) {
				setLoading( false );
			}
		} );
		return () => { active = false; };
	}, [ context.canReviewSubmissions ] );

	if ( ! context.canReviewSubmissions ) {
		return null;
	}

	const open = async ( id: number ): Promise< void > => {
		setLoading( true );
		setError( '' );
		try {
			setArticle( await run< Submission >( 'read', { id } ) );
		} catch {
			setError( __( 'This submission is unavailable.', 'extrachill-studio' ) );
		} finally {
			setLoading( false );
		}
	};

	return <section aria-label={ __( 'Review submissions', 'extrachill-studio' ) }>
		{ error && <p role="alert">{ error }</p> }
		{ loading ? <p role="status">{ __( 'Loading…', 'extrachill-studio' ) }</p> : article ? <>
			<button type="button" onClick={ () => setArticle( null ) }>{ __( 'Back to submissions', 'extrachill-studio' ) }</button>
			<article className="ec-surface-card ec-card-padding">
				<h2>{ article.title }</h2>
				<p>{ article.author } · { article.submitted_at || article.modified_gmt }</p>
				{ article.featured_image && <img src={ article.featured_image } alt="" /> }
				<p>{ [ ...( article.categories || [] ), ...( article.tags || [] ) ].join( ', ' ) }</p>
				<div className="entry-content" dangerouslySetInnerHTML={ { __html: article.content || '' } } />
				{ article.edit_url && <a href={ article.edit_url }>{ __( 'Edit / publish on main', 'extrachill-studio' ) }</a> }
			</article>
		</> : rows.length ? <ul>{ rows.map( ( row ) => <li key={ row.id }>
			<button type="button" onClick={ () => void open( row.id ) }>{ row.title }</button>
			<p>{ row.author } · { row.submitted_at || row.modified_gmt }</p>
		</li> ) }</ul> : <p>{ __( 'No pending submissions.', 'extrachill-studio' ) }</p> }
	</section>;
}
