/**
 * TinyMCE-specific bbPress functionality
 *
 * Only loaded when Blocks Everywhere plugin is inactive.
 * Contains @mentions autocomplete and draft autosave for TinyMCE editor.
 */

/**
 * @mentions autocomplete plugin for TinyMCE
 */
if ( typeof window.extrachillMentionsPluginLoaded === 'undefined' ) {
	window.extrachillMentionsPluginLoaded = true;

	( function () {
		const { tinymce } = window;
		const TEXT_NODE_TYPE = 3;

		if ( ! tinymce ) {
			return;
		}

		const mentionsState = {
			dropdown: null,
			isActive: false,
			currentSearch: '',
			selectedIndex: -1,
			searchResults: [],
			debounceTimeout: null,
			currentRange: null,
			mentionStart: 0,
			editor: null,
		};

		function createDropdown() {
			if ( mentionsState.dropdown ) {
				return mentionsState.dropdown;
			}

			const dropdown = document.createElement( 'div' );
			dropdown.className = 'mentions-dropdown';
			document.body.appendChild( dropdown );
			mentionsState.dropdown = dropdown;
			return dropdown;
		}

		function positionDropdown( editor ) {
			if ( ! mentionsState.dropdown || ! mentionsState.currentRange ) {
				return;
			}

			try {
				const selection = editor.selection;
				const range = selection.getRng();
				const rect = range.getBoundingClientRect();

				mentionsState.dropdown.style.left = `${ rect.left }px`;
				mentionsState.dropdown.style.top = `${ rect.bottom + 5 }px`;
			} catch {
				const editorRect = editor
					.getContainer()
					.getBoundingClientRect();
				mentionsState.dropdown.style.left = `${
					editorRect.left + 20
				}px`;
				mentionsState.dropdown.style.top = `${ editorRect.top + 60 }px`;
			}
		}

		function renderResults( results ) {
			if ( ! mentionsState.dropdown ) {
				return;
			}

			mentionsState.searchResults = results;
			mentionsState.selectedIndex = -1;

			if ( results.length === 0 ) {
				mentionsState.dropdown.innerHTML =
					'<div class="mentions-item mentions-no-results">No users found</div>';
				return;
			}

			const html = results
				.map(
					( user, index ) =>
						`<div class="mentions-item" data-index="${ index }" data-username="${ user.slug }">
						<div class="mentions-avatar"><div class="avatar-placeholder"></div></div>
						<div class="mentions-user-info">
							<div class="mentions-username">@${ user.slug }</div>
							<div class="mentions-login">${ user.username }</div>
						</div>
					</div>`
				)
				.join( '' );

			mentionsState.dropdown.innerHTML = html;

			mentionsState.dropdown
				.querySelectorAll( '.mentions-item' )
				.forEach( ( item ) => {
					item.addEventListener( 'click', function ( e ) {
						const index = parseInt(
							e.currentTarget.dataset.index,
							10
						);
						if ( Number.isFinite( index ) ) {
							selectMention( index );
						}
					} );
				} );
		}

		function updateSelection() {
			if ( ! mentionsState.dropdown ) {
				return;
			}

			const items = mentionsState.dropdown.querySelectorAll(
				'.mentions-item:not(.mentions-no-results)'
			);
			items.forEach( ( item, index ) => {
				item.classList.toggle(
					'selected',
					index === mentionsState.selectedIndex
				);
			} );
		}

		function selectMention( index ) {
			if (
				! mentionsState.editor ||
				! mentionsState.searchResults[ index ]
			) {
				return;
			}

			const user = mentionsState.searchResults[ index ];
			const mention = `@${ user.slug }`;

			const editor = mentionsState.editor;
			const selection = editor.selection;
			selection.setRng( mentionsState.currentRange );

			const range = selection.getRng();
			const textNode = range.startContainer;

			if ( textNode.nodeType !== TEXT_NODE_TYPE ) {
				hideDropdown();
				return;
			}

			const beforeText = textNode.textContent.substring(
				0,
				mentionsState.mentionStart
			);
			const afterText = textNode.textContent.substring(
				range.startOffset
			);
			textNode.textContent = `${ beforeText }${ mention } ${ afterText }`;

			const newRange = editor.dom.createRng();
			newRange.setStart(
				textNode,
				beforeText.length + mention.length + 1
			);
			newRange.setEnd( textNode, beforeText.length + mention.length + 1 );
			selection.setRng( newRange );

			hideDropdown();
		}

		function showDropdown( editor ) {
			const dropdown = createDropdown();
			dropdown.style.display = 'block';
			positionDropdown( editor );
			mentionsState.isActive = true;
		}

		function hideDropdown() {
			if ( mentionsState.dropdown ) {
				mentionsState.dropdown.style.display = 'none';
			}
			mentionsState.isActive = false;
			mentionsState.currentSearch = '';
			mentionsState.selectedIndex = -1;
			mentionsState.searchResults = [];
		}

		function getRestRoot() {
			return (
				( window.extrachillCommunity &&
					window.extrachillCommunity.restUrl ) ||
				( window.extrachillCommunityEditor &&
					window.extrachillCommunityEditor.restUrl ) ||
				( window.wpApiSettings && window.wpApiSettings.root )
			);
		}

		function searchUsers( term ) {
			if ( ! term || term.length < 1 ) {
				hideDropdown();
				return;
			}

			if ( mentionsState.dropdown ) {
				mentionsState.dropdown.innerHTML =
					'<div class="mentions-item mentions-loading">Searching...</div>';
			}

			const restRoot = getRestRoot();
			if ( ! restRoot ) {
				renderResults( [] );
				return;
			}

			const apiUrl = new URL( 'extrachill/v1/users/search', restRoot );
			apiUrl.searchParams.set( 'term', term );

			fetch( apiUrl.toString() )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					renderResults( Array.isArray( data ) ? data : [] );
				} )
				.catch( function () {
					renderResults( [] );
				} );
		}

		function debouncedSearch( term ) {
			clearTimeout( mentionsState.debounceTimeout );
			mentionsState.debounceTimeout = setTimeout( function () {
				searchUsers( term );
			}, 200 );
		}

		function checkForMention( editor ) {
			const selection = editor.selection;
			const range = selection.getRng();

			if (
				! range ||
				! range.startContainer ||
				range.startContainer.nodeType !== TEXT_NODE_TYPE
			) {
				if ( mentionsState.isActive ) {
					hideDropdown();
				}
				return false;
			}

			const textNode = range.startContainer;
			const cursorPos = range.startOffset;
			const text = textNode.textContent;

			let mentionStart = -1;
			for ( let index = cursorPos - 1; index >= 0; index-- ) {
				if ( text[ index ] === '@' ) {
					if ( index === 0 || /\s/.test( text[ index - 1 ] ) ) {
						mentionStart = index;
						break;
					}
				}
				if ( /\s/.test( text[ index ] ) ) {
					break;
				}
			}

			if ( mentionStart === -1 ) {
				if ( mentionsState.isActive ) {
					hideDropdown();
				}
				return false;
			}

			const searchTerm = text.substring( mentionStart + 1, cursorPos );
			if ( ! /^[a-zA-Z0-9_-]*$/.test( searchTerm ) ) {
				if ( mentionsState.isActive ) {
					hideDropdown();
				}
				return false;
			}

			mentionsState.currentRange = range.cloneRange();
			mentionsState.mentionStart = mentionStart;
			mentionsState.currentSearch = searchTerm;
			mentionsState.editor = editor;

			showDropdown( editor );
			debouncedSearch( searchTerm );

			return true;
		}

		function handleKeyDown( editor, e ) {
			if ( ! mentionsState.isActive ) {
				return;
			}

			switch ( e.keyCode ) {
				case 27:
					e.preventDefault();
					hideDropdown();
					break;
				case 38:
					e.preventDefault();
					if ( mentionsState.searchResults.length > 0 ) {
						if ( mentionsState.selectedIndex > 0 ) {
							mentionsState.selectedIndex--;
						} else {
							mentionsState.selectedIndex =
								mentionsState.searchResults.length - 1;
						}
						updateSelection();
					}
					break;
				case 40:
					e.preventDefault();
					if ( mentionsState.searchResults.length > 0 ) {
						if (
							mentionsState.selectedIndex <
							mentionsState.searchResults.length - 1
						) {
							mentionsState.selectedIndex++;
						} else {
							mentionsState.selectedIndex = 0;
						}
						updateSelection();
					}
					break;
				case 13:
					e.preventDefault();
					if ( mentionsState.selectedIndex >= 0 ) {
						selectMention( mentionsState.selectedIndex );
					}
					break;
			}
		}

		function setupMentionsPlugin( editor ) {
			editor.on( 'keydown', function ( e ) {
				handleKeyDown( editor, e );
			} );

			editor.on( 'keyup', function ( e ) {
				const navKeys = [ 37, 38, 39, 40, 27, 13 ];
				if ( navKeys.includes( e.keyCode ) ) {
					return;
				}

				checkForMention( editor );
			} );

			editor.on( 'click', function () {
				setTimeout( function () {
					if (
						mentionsState.isActive &&
						! checkForMention( editor )
					) {
						hideDropdown();
					}
				}, 10 );
			} );

			editor.on( 'blur', function () {
				setTimeout( function () {
					hideDropdown();
				}, 150 );
			} );
		}

		tinymce.PluginManager.add(
			'extrachillmentionssocial',
			function ( editor ) {
				editor.on( 'init', function () {
					setupMentionsPlugin( editor );
				} );
			}
		);
	} )();
}

/**
 * TinyMCE draft autosave setup
 */
window.extrachillTinymceSetup = function ( editor ) {
	let debounceTimer;
	const saveDelay = 800;
	let isSubmitting = false;
	const draftRequestControllers = new Set();
	const form = editor.getElement()
		? editor.getElement().closest( 'form' )
		: null;
	const editorContext = window.extrachillCommunityEditor || {};
	const restNonce =
		editorContext.restNonce ||
		( window.wpApiSettings ? window.wpApiSettings.nonce : null );

	function getForumIdField() {
		return (
			document.querySelector( '#bbp_forum_id' ) ||
			document.querySelector( '[name="bbp_forum_id"]' )
		);
	}

	function getCurrentForumId() {
		const forumField = getForumIdField();
		if ( ! forumField ) {
			return null;
		}

		const forumId = parseInt( forumField.value, 10 );
		return Number.isFinite( forumId ) && forumId >= 0 ? forumId : 0;
	}

	function getTopicId() {
		if ( ! form ) {
			return null;
		}

		const topicField = form.querySelector( 'input[name="bbp_topic_id"]' );
		if ( ! topicField ) {
			return null;
		}

		const topicId = parseInt( topicField.value, 10 );
		return Number.isFinite( topicId ) && topicId > 0 ? topicId : null;
	}

	function getDraftType() {
		const element = editor.getElement();
		const fieldName = element && element.name ? element.name : '';
		if ( fieldName === 'bbp_topic_content' ) {
			return 'topic';
		}
		if ( fieldName === 'bbp_reply_content' ) {
			return 'reply';
		}
		return null;
	}

	function buildDraftUrl( params ) {
		if ( ! editorContext.restUrl ) {
			throw new Error( 'TinyMCE drafts missing REST root.' );
		}

		const url = new URL(
			'extrachill/v1/community/drafts',
			editorContext.restUrl
		);
		Object.keys( params ).forEach( function ( key ) {
			if ( params[ key ] === undefined || params[ key ] === null ) {
				return;
			}
			url.searchParams.set( key, String( params[ key ] ) );
		} );
		return url.toString();
	}

	function draftFetch( path, options ) {
		if ( ! restNonce ) {
			return Promise.resolve( null );
		}

		const controller = new AbortController();
		draftRequestControllers.add( controller );

		const mergedOptions = Object.assign(
			{
				credentials: 'same-origin',
				signal: controller.signal,
				headers: Object.assign(
					{
						'X-WP-Nonce': restNonce,
					},
					options && options.headers ? options.headers : {}
				),
			},
			options || {}
		);

		return fetch( path, mergedOptions ).finally( function () {
			draftRequestControllers.delete( controller );
		} );
	}

	function getTopicDraftPayload( forumId ) {
		const titleField = document.getElementById( 'bbp_topic_title' );
		const title = titleField ? titleField.value : '';
		const content = editor.getContent( { format: 'raw' } );

		if ( title.trim() === '' && content.trim() === '' ) {
			return null;
		}

		return {
			type: 'topic',
			forum_id: forumId,
			title,
			content,
		};
	}

	function getReplyTo() {
		if ( ! form ) {
			return 0;
		}

		const field = form.querySelector( 'input[name="bbp_reply_to"]' );
		if ( ! field ) {
			return 0;
		}

		const value = parseInt( field.value, 10 );
		return Number.isFinite( value ) && value >= 0 ? value : 0;
	}

	function getReplyDraftPayload( topicId ) {
		const content = editor.getContent( { format: 'raw' } );
		if ( content.trim() === '' ) {
			return null;
		}

		return {
			type: 'reply',
			topic_id: topicId,
			reply_to: getReplyTo(),
			content,
		};
	}

	function saveDraft() {
		if ( isSubmitting ) {
			return;
		}
		const type = getDraftType();
		if ( ! type ) {
			return;
		}

		if ( type === 'topic' ) {
			const forumId = getCurrentForumId();
			if ( forumId === null ) {
				return;
			}

			const payload = getTopicDraftPayload( forumId );
			if ( ! payload ) {
				return;
			}

			draftFetch( buildDraftUrl( {} ), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( payload ),
			} ).catch( function () {} );
			return;
		}

		const topicId = getTopicId();
		if ( ! topicId ) {
			return;
		}

		const replyPayload = getReplyDraftPayload( topicId );
		if ( ! replyPayload ) {
			return;
		}

		draftFetch( buildDraftUrl( {} ), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify( replyPayload ),
		} ).catch( function () {} );
	}

	function deleteTopicDraft( forumId, options ) {
		return draftFetch(
			buildDraftUrl( {
				type: 'topic',
				forum_id: forumId,
			} ),
			Object.assign(
				{
					method: 'DELETE',
				},
				options || {}
			)
		);
	}

	function shouldAutorestoreDraft( type ) {
		if ( ! type ) {
			return false;
		}

		const content = editor.getContent( { format: 'raw' } );
		if ( String( content || '' ).trim() !== '' ) {
			return false;
		}

		if ( type === 'topic' ) {
			const titleField = document.getElementById( 'bbp_topic_title' );
			const titleValue = titleField ? titleField.value : '';
			return String( titleValue || '' ).trim() === '';
		}

		return type === 'reply';
	}

	function maybeRestoreDraft() {
		const type = getDraftType();
		if ( ! shouldAutorestoreDraft( type ) ) {
			return;
		}

		if ( type === 'topic' ) {
			const forumId = getCurrentForumId();
			if ( forumId === null ) {
				return;
			}

			draftFetch(
				buildDraftUrl( {
					type: 'topic',
					forum_id: forumId,
					prefer_unassigned: true,
				} ),
				{
					method: 'GET',
				}
			)
				.then( function ( response ) {
					if ( ! response || ! response.ok ) {
						return null;
					}
					return response.json();
				} )
				.then( function ( payload ) {
					const draft =
						payload && payload.draft ? payload.draft : null;
					if ( ! draft ) {
						return;
					}

					const titleField =
						document.getElementById( 'bbp_topic_title' );
					if (
						titleField &&
						String( titleField.value || '' ).trim() === ''
					) {
						titleField.value = String( draft.title || '' );
					}

					const currentContent = editor.getContent( {
						format: 'raw',
					} );
					if ( String( currentContent || '' ).trim() === '' ) {
						editor.setContent( String( draft.content || '' ), {
							format: 'raw',
						} );
					}
				} )
				.catch( function () {} );

			return;
		}

		const topicId = getTopicId();
		if ( ! topicId ) {
			return;
		}

		draftFetch(
			buildDraftUrl( {
				type: 'reply',
				topic_id: topicId,
				reply_to: getReplyTo(),
			} ),
			{
				method: 'GET',
			}
		)
			.then( function ( response ) {
				if ( ! response || ! response.ok ) {
					return null;
				}
				return response.json();
			} )
			.then( function ( payload ) {
				const draft = payload && payload.draft ? payload.draft : null;
				if ( ! draft ) {
					return;
				}

				const currentContent = editor.getContent( { format: 'raw' } );
				if ( String( currentContent || '' ).trim() === '' ) {
					editor.setContent( String( draft.content || '' ), {
						format: 'raw',
					} );
				}
			} )
			.catch( function () {} );
	}

	function setupForumMoveHandler() {
		if ( getDraftType() !== 'topic' ) {
			return;
		}

		const forumField = getForumIdField();
		if ( ! forumField ) {
			return;
		}

		let lastForumId = getCurrentForumId();

		forumField.addEventListener( 'change', function () {
			if ( isSubmitting ) {
				return;
			}
			const newForumId = getCurrentForumId();
			if ( newForumId === null || lastForumId === null ) {
				return;
			}

			if ( lastForumId === 0 && newForumId > 0 ) {
				const payload = getTopicDraftPayload( newForumId );
				if ( ! payload ) {
					lastForumId = newForumId;
					return;
				}

				draftFetch( buildDraftUrl( {} ), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( payload ),
				} )
					.then( function () {
						return deleteTopicDraft( 0 );
					} )
					.catch( function () {} );
			}

			lastForumId = newForumId;
		} );
	}

	editor.on( 'input keyup', function ( e ) {
		const nonTriggerKeys = [ 33, 34, 35, 36, 37, 38, 39, 40 ];
		if ( e && e.keyCode && nonTriggerKeys.includes( e.keyCode ) ) {
			return;
		}

		clearTimeout( debounceTimer );
		debounceTimer = setTimeout( function () {
			if ( ! editor.removed ) {
				saveDraft();
			}
		}, saveDelay );
	} );

	const titleField = document.getElementById( 'bbp_topic_title' );
	if ( titleField && getDraftType() === 'topic' ) {
		titleField.addEventListener( 'input', function () {
			clearTimeout( debounceTimer );
			debounceTimer = setTimeout( function () {
				if ( ! editor.removed ) {
					saveDraft();
				}
			}, saveDelay );
		} );
	}

	editor.on( 'init', function () {
		if ( ! editor.removed ) {
			maybeRestoreDraft();
		}
	} );

	setupForumMoveHandler();

	if ( form ) {
		form.addEventListener(
			'submit',
			function ( event ) {
				if ( editor.removed ) {
					return;
				}

				if (
					event &&
					event.submitter &&
					event.submitter.closest &&
					event.submitter.closest(
						'.tox-tbtn, .tox-button, .mce, .mce-container'
					)
				) {
					return;
				}

				isSubmitting = true;
				clearTimeout( debounceTimer );

				draftRequestControllers.forEach( function ( controller ) {
					controller.abort();
				} );
			},
			false
		);
	}
};
