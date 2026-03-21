import { __, sprintf } from '@wordpress/i18n';
import { createElement, useEffect, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Compose Pane — Block editor for drafting posts.
 *
 * Mounts the Blocks Everywhere isolated block editor on a hidden textarea.
 * Content is serialized as block markup and saved to wp/v2/posts with
 * status: pending for admin review.
 */
const ComposePane = () => {
	const editorContainerRef = useRef( null );
	const textareaRef = useRef( null );
	const editorMountedRef = useRef( false );

	const [ title, setTitle ] = useState( '' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ status, setStatus ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ editorReady, setEditorReady ] = useState( false );

	// Mount the block editor on first render.
	useEffect( () => {
		if ( editorMountedRef.current ) {
			return;
		}

		if ( ! textareaRef.current ) {
			return;
		}

		// Blocks Everywhere exposes this globally when loaded.
		if ( typeof window.blocksEverywhereCreateEditor === 'function' ) {
			window.blocksEverywhereCreateEditor( textareaRef.current );
			editorMountedRef.current = true;
			setEditorReady( true );
		} else {
			setError( __( 'Block editor not available. Ensure Blocks Everywhere plugin is active.', 'extrachill-studio' ) );
		}
	}, [] );

	/**
	 * Get the content from the hidden textarea.
	 * Blocks Everywhere syncs the editor content back to the textarea
	 * on every change via its onSaveContent callback.
	 */
	const getContent = () => {
		if ( textareaRef.current ) {
			return textareaRef.current.value || '';
		}
		return '';
	};

	const submitForReview = async () => {
		const content = getContent();

		if ( ! title.trim() ) {
			setError( __( 'Add a title before submitting.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		if ( ! content.trim() ) {
			setError( __( 'Write some content before submitting.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		setIsSubmitting( true );
		setError( '' );
		setStatus( __( 'Submitting for review…', 'extrachill-studio' ) );

		try {
			const post = await apiFetch( {
				path: '/wp/v2/posts',
				method: 'POST',
				data: {
					title: title.trim(),
					content,
					status: 'pending',
				},
			} );

			setStatus(
				sprintf(
					__( 'Post #%d submitted for review. An admin will review it before publishing.', 'extrachill-studio' ),
					post.id
				)
			);
			setTitle( '' );

			// Clear the editor content.
			if ( textareaRef.current ) {
				textareaRef.current.value = '';

				// Trigger an input event so Blocks Everywhere picks up the change.
				textareaRef.current.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		} catch ( submitError ) {
			setStatus( '' );
			setError( submitError?.message || __( 'Failed to submit post.', 'extrachill-studio' ) );
		} finally {
			setIsSubmitting( false );
		}
	};

	const saveDraft = async () => {
		const content = getContent();

		if ( ! title.trim() && ! content.trim() ) {
			setError( __( 'Add a title or content before saving.', 'extrachill-studio' ) );
			setStatus( '' );
			return;
		}

		setIsSubmitting( true );
		setError( '' );
		setStatus( __( 'Saving draft…', 'extrachill-studio' ) );

		try {
			const post = await apiFetch( {
				path: '/wp/v2/posts',
				method: 'POST',
				data: {
					title: title.trim(),
					content,
					status: 'draft',
				},
			} );

			setStatus(
				sprintf(
					__( 'Draft #%d saved. You can find it in your drafts.', 'extrachill-studio' ),
					post.id
				)
			);
		} catch ( saveError ) {
			setStatus( '' );
			setError( saveError?.message || __( 'Failed to save draft.', 'extrachill-studio' ) );
		} finally {
			setIsSubmitting( false );
		}
	};

	return createElement(
		'div',
		{ className: 'ec-studio-pane ec-studio-pane--compose' },
		createElement(
			'div',
			{ className: 'ec-studio-pane__grid ec-studio-pane__grid--compose' },

			// Left: Editor
			createElement(
				'div',
				{ className: 'ec-studio-panel ec-studio-panel--editor' },
				createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'Compose', 'extrachill-studio' ) ),

				// Title input
				createElement( 'input', {
					type: 'text',
					className: 'ec-studio-compose-title',
					placeholder: __( 'Post title…', 'extrachill-studio' ),
					value: title,
					onChange: ( e ) => {
						setTitle( e.target.value );
						setError( '' );
						setStatus( '' );
					},
				} ),

				// Block editor container
				createElement(
					'div',
					{
						className: 'ec-studio-compose-editor',
						ref: editorContainerRef,
					},
					createElement( 'textarea', {
						id: 'ec-studio-compose-content',
						ref: textareaRef,
						style: { display: 'none' },
						defaultValue: '',
					} )
				),

				// Status messages
				error
					? createElement( 'p', { className: 'ec-studio-message ec-studio-message--error' }, error )
					: null,
				! error && status
					? createElement( 'p', { className: 'ec-studio-message ec-studio-message--success' }, status )
					: null,

				// Actions
				createElement(
					'div',
					{ className: 'ec-studio-composer__actions' },
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium',
							onClick: submitForReview,
							disabled: isSubmitting || ! editorReady,
						},
						isSubmitting ? __( 'Submitting…', 'extrachill-studio' ) : __( 'Submit for Review', 'extrachill-studio' )
					),
					createElement(
						'button',
						{
							type: 'button',
							className: 'button-1 button-medium button-secondary',
							onClick: saveDraft,
							disabled: isSubmitting || ! editorReady,
						},
						isSubmitting ? __( 'Saving…', 'extrachill-studio' ) : __( 'Save Draft', 'extrachill-studio' )
					)
				)
			),

			// Right: Info panel
			createElement(
				'div',
				{ className: 'ec-studio-panel' },
				createElement( 'span', { className: 'ec-studio-panel__eyebrow' }, __( 'How it works', 'extrachill-studio' ) ),
				createElement( 'h3', null, __( 'Frontend publishing', 'extrachill-studio' ) ),
				createElement( 'p', null, __( 'Write posts using the full block editor right here in Studio. No wp-admin required.', 'extrachill-studio' ) ),
				createElement(
					'ul',
					null,
					createElement( 'li', null, __( 'Use the block editor to compose rich content with images, embeds, and formatting.', 'extrachill-studio' ) ),
					createElement( 'li', null, __( 'Save Draft keeps your work-in-progress without submitting.', 'extrachill-studio' ) ),
					createElement( 'li', null, __( 'Submit for Review sends the post for admin approval.', 'extrachill-studio' ) ),
					createElement( 'li', null, __( 'Once approved, the post goes live.', 'extrachill-studio' ) )
				)
			)
		)
	);
};

export default ComposePane;
