import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useState } from '@wordpress/element';
import type { ReactElement, ChangeEvent } from 'react';
import { ActionRow, FieldGroup, InlineStatus, Panel, PanelHeader } from '@extrachill/components';

import { studioSocialsApi } from '../../../app/client';
import type { SocialComment } from '../../../app/client';

const h = createElement as typeof import( 'react' ).createElement;
const PanelView = Panel as unknown as ( props: any ) => ReactElement;
const ActionRowView = ActionRow as unknown as ( props: any ) => ReactElement;
const FieldGroupView = FieldGroup as unknown as ( props: any ) => ReactElement;
const InlineStatusView = InlineStatus as unknown as ( props: any ) => ReactElement;

export interface CommentsViewProps {
	slug: string;
	label: string;
}

/**
 * Standalone comments view for a social platform.
 *
 * Shows an inbox of recent comments with reply capability.
 * Supports both "all recent" mode and per-post filtering.
 */
const CommentsView = ( { slug, label }: CommentsViewProps ): ReactElement => {
	const [ comments, setComments ] = useState< SocialComment[] >( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ replyDrafts, setReplyDrafts ] = useState< Record< string, string > >( {} );
	const [ replyingCommentId, setReplyingCommentId ] = useState( '' );
	const [ postFilter, setPostFilter ] = useState( '' );
	const [ activeFilter, setActiveFilter ] = useState( '' );

	const loadComments = async ( mediaId: string = '' ): Promise< void > => {
		setIsLoading( true );
		setError( '' );
		setStatus( '' );

		try {
			const response = await studioSocialsApi.getAllComments( slug, mediaId );
			const loaded = response?.data?.comments || [];
			setComments( loaded );

			if ( loaded.length === 0 ) {
				setStatus( __( 'No comments found.', 'extrachill-studio' ) );
			} else {
				setStatus( sprintf( __( 'Loaded %d comments.', 'extrachill-studio' ), loaded.length ) );
			}
		} catch ( fetchError ) {
			setComments( [] );
			setError( ( fetchError as Error )?.message || __( 'Unable to load comments.', 'extrachill-studio' ) );
		} finally {
			setIsLoading( false );
		}
	};

	// Auto-load comments on mount (inbox mode — no media_id filter).
	useEffect( () => {
		loadComments( '' );
	}, [ slug ] );

	const handleFilter = (): void => {
		const mediaId = postFilter.trim();
		setActiveFilter( mediaId );
		loadComments( mediaId );
	};

	const clearFilter = (): void => {
		setPostFilter( '' );
		setActiveFilter( '' );
		loadComments( '' );
	};

	const setReplyDraft = ( commentId: string, value: string ): void => {
		setReplyDrafts( ( current ) => ( {
			...current,
			[ commentId ]: value,
		} ) );
	};

	const replyToComment = async ( commentId: string ): Promise< void > => {
		const message = ( replyDrafts[ commentId ] || '' ).trim();

		if ( ! message ) {
			setError( __( 'Write a reply before posting.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		setReplyingCommentId( commentId );
		setError( '' );
		setStatus( __( 'Posting reply…', 'extrachill-studio' ) );

		try {
			await studioSocialsApi.replyToComment( slug, commentId, message );
			setReplyDraft( commentId, '' );
			setStatus( __( 'Reply posted successfully.', 'extrachill-studio' ) );

			// Refresh the comment list.
			await loadComments( activeFilter );
		} catch ( replyError ) {
			setStatus( '' );
			setError( ( replyError as Error )?.message || __( 'Failed to reply to comment.', 'extrachill-studio' ) );
		} finally {
			setReplyingCommentId( '' );
		}
	};

	const platformLabel = label || slug;

	return h(
		'div',
		{ className: `ec-studio-pane ec-studio-pane--comments ec-studio-pane--${ slug }-comments` },
		// Filter bar
		h(
			PanelView,
			{ className: 'ec-studio-panel', compact: true },
			h( PanelHeader, {
				description: sprintf(
					__( 'Comments on your %s posts. Filter by post ID or view all recent comments.', 'extrachill-studio' ),
					platformLabel
				),
			} ),
			h(
				'div',
				{ className: 'ec-studio-composer' },
				h(
					FieldGroupView,
					{
						label: __( 'Filter by Post ID', 'extrachill-studio' ),
						htmlFor: `ec-studio-${ slug }-comments-filter`,
						help: __( 'Enter a post/media ID to filter, or leave empty to show all recent comments.', 'extrachill-studio' ),
					},
					createElement( 'input', {
						id: `ec-studio-${ slug }-comments-filter`,
						type: 'text',
						value: postFilter,
						onChange: ( event: ChangeEvent< HTMLInputElement > ) => setPostFilter( event.target.value ),
						placeholder: __( 'Post or media ID…', 'extrachill-studio' ),
					} )
				),
				h(
					ActionRowView,
					{ className: 'ec-studio-composer__actions' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium',
							onClick: handleFilter,
							disabled: isLoading,
						},
						isLoading
							? __( 'Loading…', 'extrachill-studio' )
							: ( postFilter.trim()
								? __( 'Filter Comments', 'extrachill-studio' )
								: __( 'Refresh All', 'extrachill-studio' ) )
					),
					activeFilter
						? createElement(
							'button',
							{
								type: 'button',
								className: 'button-2 button-medium',
								onClick: clearFilter,
								disabled: isLoading,
							},
							__( 'Clear Filter', 'extrachill-studio' )
						)
						: null
				)
			)
		),
		// Status / Error
		error ? h( InlineStatusView, { tone: 'error', className: 'ec-studio-message' }, error ) : null,
		! error && status ? h( InlineStatusView, { tone: 'success', className: 'ec-studio-message' }, status ) : null,
		// Comment list
		comments.length > 0
			? h(
				PanelView,
				{ className: 'ec-studio-panel', compact: true },
				h(
					'ul',
					{ className: 'ec-studio-comment-list' },
					...comments.map( ( comment ) => createElement(
						'li',
						{ key: comment.id, className: 'ec-studio-comment-list__item' },
						createElement(
							'div',
							{ className: 'ec-studio-comment-list__meta' },
							createElement( 'strong', null, `@${ comment.author_username || 'unknown' }` ),
							comment.timestamp ? createElement( 'span', null, comment.timestamp ) : null
						),
						createElement( 'p', { className: 'ec-studio-comment-list__text' }, comment.text || '' ),
						createElement(
							'div',
							{ className: 'ec-studio-composer' },
							h(
								FieldGroupView,
								{ label: __( 'Reply', 'extrachill-studio' ) },
								createElement( 'textarea', {
									rows: 2,
									value: replyDrafts[ comment.id ] || '',
									onChange: ( event: ChangeEvent< HTMLTextAreaElement > ) => setReplyDraft( comment.id, event.target.value ),
									placeholder: __( 'Write a reply…', 'extrachill-studio' ),
								} )
							),
							h(
								ActionRowView,
								{ className: 'ec-studio-composer__actions' },
								createElement(
									'button',
									{
										type: 'button',
										className: 'button-1 button-medium',
										onClick: () => replyToComment( comment.id ),
										disabled: replyingCommentId === comment.id,
									},
									replyingCommentId === comment.id
										? __( 'Replying…', 'extrachill-studio' )
										: __( 'Reply', 'extrachill-studio' )
								)
							)
						)
					) )
				)
			)
			: ( ! isLoading
				? h(
					PanelView,
					{ className: 'ec-studio-panel', compact: true },
					createElement( 'div', { className: 'ec-studio-preview' }, __( 'No comments to display.', 'extrachill-studio' ) )
				)
				: null
			)
	);
};

export default CommentsView;
