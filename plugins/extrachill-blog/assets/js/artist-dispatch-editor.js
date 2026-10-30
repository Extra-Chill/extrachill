( function () {
	'use strict';

	if ( ! window.blocksEverywhere || ! window.wp || ! window.wp.editor || ! window.ecArtistDispatchEditor ) {
		return;
	}

	const el = window.wp.element.createElement;
	const { useState } = window.wp.element;
	const { useDispatch, useSelect } = window.wp.data;
	const { Button, Spinner } = window.wp.components;
	const editorStore = window.wp.editor.store;

	function getSaveError() {
		const editor = window.wp.data.select( editorStore );
		if ( ! editor.didPostSaveRequestFail() || ! window.wp.coreData ) {
			return null;
		}
		return window.wp.data.select( window.wp.coreData.store ).getLastEntitySaveError( 'postType', 'post', editor.getCurrentPostId() );
	}

	window.blocksEverywhere.registerSlotFill( 'heading', () => el(
		'div',
		{ className: 'artist-dispatch-editor-title' },
		el( 'span', { className: 'artist-dispatch-label' }, 'Artist Dispatch' ),
		el( window.wp.editor.PostTitle )
	) );

	function EditorActions() {
		const [ message, setMessage ] = useState( '' );
		const [ submitting, setSubmitting ] = useState( false );
		const { editPost, savePost } = useDispatch( editorStore );
		const state = useSelect( ( select ) => {
			const editor = select( editorStore );
			return {
				saving: editor.isSavingPost(),
				autosaving: editor.isAutosavingPost(),
				dirty: editor.isEditedPostDirty(),
			};
		}, [] );

		const saveDraft = async () => {
			setMessage( 'Saving…' );
			try {
				await savePost();
				const error = getSaveError();
				if ( error ) {
					throw error;
				}
				setMessage( 'Draft saved.' );
			} catch ( error ) {
				setMessage( error && error.message ? error.message : 'Draft save failed.' );
			}
		};

		const submit = async () => {
			if ( submitting ) {
				return;
			}
			setSubmitting( true );
			setMessage( 'Submitting for editorial review…' );
			try {
				editPost( { status: 'pending' } );
				await savePost();
				const error = getSaveError();
				if ( error ) {
					throw error;
				}
				window.location.assign( window.ecArtistDispatchEditor.dashboardUrl );
			} catch ( error ) {
				editPost( { status: 'draft' } );
				setMessage( error && error.message ? error.message : 'Submission failed. Your draft remains private.' );
				setSubmitting( false );
			}
		};

		const busy = state.saving || state.autosaving || submitting;
		let status = message;
		if ( state.autosaving ) {
			status = 'Autosaving…';
		} else if ( ! message && state.dirty ) {
			status = 'Unsaved changes';
		} else if ( ! message ) {
			status = 'All changes saved';
		}

		return el(
			'div',
			{ className: 'artist-dispatch-editor-actions' },
			el( 'span', { className: 'artist-dispatch-save-state', role: 'status' }, busy && el( Spinner ), status ),
			el( Button, { variant: 'secondary', disabled: busy || ! state.dirty, onClick: saveDraft }, 'Save Draft' ),
			el( Button, { variant: 'primary', disabled: busy, onClick: submit }, 'Submit for Review' )
		);
	}

	window.blocksEverywhere.registerSlotFill( 'footer', () => el( EditorActions ) );
}() );
