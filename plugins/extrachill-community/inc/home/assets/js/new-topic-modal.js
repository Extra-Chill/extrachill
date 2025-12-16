/**
 * New Topic Modal
 *
 * Handles modal open/close, TinyMCE reinitialization, and accessibility.
 */
( function () {
	'use strict';

	const modal = document.getElementById( 'new-topic-modal' );
	const overlay = document.getElementById( 'new-topic-modal-overlay' );
	if ( ! modal || ! overlay ) {
		return;
	}

	const discussionTrigger = document.getElementById(
		'new-topic-modal-trigger'
	);
	const shareMusicTrigger = document.getElementById(
		'share-music-modal-trigger'
	);
	const closeButton = modal.querySelector( '.new-topic-modal-close' );

	const modalTitle = document.getElementById( 'new-topic-modal-title' );
	const modalDescription = document.getElementById(
		'new-topic-modal-description'
	);

	const { wp, tinymce } = window;

	let editorInitialized = false;
	let activeTrigger = null;
	let originalForumId = null;

	function getForumSelectWrapper() {
		const forumSelect = document.getElementById( 'bbp_forum_id' );
		if ( ! forumSelect ) {
			return null;
		}

		return forumSelect.closest( 'p' );
	}

	function setForumId( forumId ) {
		const forumSelect = document.getElementById( 'bbp_forum_id' );
		if ( ! forumSelect ) {
			return;
		}

		if ( originalForumId === null ) {
			originalForumId = forumSelect.value;
		}

		forumSelect.value = String( forumId );
		forumSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function showForumDropdown() {
		const wrapper = getForumSelectWrapper();
		if ( ! wrapper ) {
			return;
		}

		wrapper.style.display = '';
	}

	function hideForumDropdown() {
		const wrapper = getForumSelectWrapper();
		if ( ! wrapper ) {
			return;
		}

		wrapper.style.display = 'none';
	}

	function setTopicTitlePlaceholder( placeholder ) {
		const titleInput = document.getElementById( 'bbp_topic_title' );
		if ( ! titleInput ) {
			return;
		}

		titleInput.setAttribute( 'placeholder', placeholder );
	}

	function setTopicContentPlaceholder( placeholder ) {
		const textarea = document.getElementById( 'bbp_topic_content' );
		if ( ! textarea ) {
			return;
		}

		textarea.setAttribute( 'placeholder', placeholder );
	}

	function setModalText( title, description ) {
		if ( modalTitle ) {
			modalTitle.textContent = title;
		}

		if ( modalDescription ) {
			modalDescription.textContent = description || '';
		}
	}

	function applyMode( triggerEl ) {
		const mode =
			triggerEl && triggerEl.dataset
				? triggerEl.dataset.modalMode
				: 'discussion';

		if ( mode === 'share_music' ) {
			setModalText(
				'Share Music',
				'Drop a link to share music with the community.'
			);
			setTopicTitlePlaceholder( 'What are you sharing?' );
			setTopicContentPlaceholder(
				'Paste a link and give us the scoop...'
			);

			const forumId = triggerEl.dataset.forumId;
			if ( forumId ) {
				setForumId( forumId );
				hideForumDropdown();
			}

			return;
		}

		setModalText(
			'Create Discussion',
			'Start a new topic in the community.'
		);
		setTopicTitlePlaceholder( 'Title' );
		setTopicContentPlaceholder( '' );

		showForumDropdown();

		if ( originalForumId !== null ) {
			setForumId( originalForumId );
		}
	}

	function openModal( e ) {
		e.preventDefault();
		activeTrigger = e.currentTarget;

		modal.classList.add( 'is-open' );
		overlay.classList.add( 'is-open' );
		document.body.classList.add( 'new-topic-modal-open' );

		if ( ! editorInitialized && wp?.editor ) {
			initializeEditor();
		}

		applyMode( activeTrigger );

		const firstInput = modal.querySelector(
			'input[type="text"], textarea'
		);
		if ( firstInput ) {
			firstInput.focus();
		}

		document.addEventListener( 'keydown', trapFocus );
	}

	function closeModal() {
		modal.classList.remove( 'is-open' );
		overlay.classList.remove( 'is-open' );
		document.body.classList.remove( 'new-topic-modal-open' );
		document.removeEventListener( 'keydown', trapFocus );

		if ( activeTrigger ) {
			activeTrigger.focus();
		}

		activeTrigger = null;
	}

	function initializeEditor() {
		const editorId = 'bbp_topic_content';
		const editorElement = document.getElementById( editorId );

		if ( ! editorElement ) {
			editorInitialized = true;
			return;
		}

		if ( editorElement.closest( '.blocks-everywhere' ) ) {
			editorInitialized = true;
			return;
		}

		if ( tinymce?.get( editorId ) ) {
			editorInitialized = true;
			return;
		}

		if ( wp?.editor?.initialize ) {
			wp.editor.initialize( editorId, {
				tinymce: {
					wpautop: true,
					plugins:
						'charmap,colorpicker,hr,lists,paste,tabfocus,textcolor,wordpress,wpautoresize,wpeditimage,wpemoji,wpgallery,wplink,wptextpattern',
					toolbar1:
						'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,wp_more,spellchecker,fullscreen,wp_adv',
					toolbar2:
						'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help',
				},
				quicktags: true,
				mediaButtons: false,
			} );
		}

		editorInitialized = true;
	}

	function trapFocus( e ) {
		if ( e.key !== 'Tab' ) {
			if ( e.key === 'Escape' ) {
				closeModal();
			}
			return;
		}

		const focusableElements = modal.querySelectorAll(
			'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
		);
		const firstElement = focusableElements[ 0 ];
		const lastElement = focusableElements[ focusableElements.length - 1 ];

		const { activeElement } = modal.ownerDocument;

		if ( e.shiftKey && activeElement === firstElement ) {
			lastElement.focus();
			e.preventDefault();
			return;
		}

		if ( ! e.shiftKey && activeElement === lastElement ) {
			firstElement.focus();
			e.preventDefault();
		}
	}

	if ( discussionTrigger ) {
		discussionTrigger.addEventListener( 'click', openModal );
	}

	if ( shareMusicTrigger ) {
		shareMusicTrigger.addEventListener( 'click', openModal );
	}

	if ( closeButton ) {
		closeButton.addEventListener( 'click', closeModal );
	}

	overlay.addEventListener( 'click', closeModal );
} )();
