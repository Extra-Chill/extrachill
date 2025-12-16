/**
 * Enhanced user mention autocomplete for TinyMCE editor
 * Provides real-time search and dropdown selection for @mentions.
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

if ( typeof window.extrachillReplyHandlerLoaded === 'undefined' ) {
	window.extrachillReplyHandlerLoaded = true;

	function initReplyHandler() {
		document.addEventListener( 'click', function ( e ) {
			const replyLink = e.target.closest( '.bbp-reply-to-link' );
			if ( ! replyLink ) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();

			const replySlug = replyLink.dataset.replySlug;
			const replyForm = document.getElementById( 'new-post' );
			const editorApi = window.tinyMCE;
			const editor = editorApi
				? editorApi.get( 'bbp_reply_content' )
				: null;

			if ( replyForm ) {
				replyForm.scrollIntoView( {
					behavior: 'smooth',
					block: 'start',
				} );
			}

			if ( replySlug && editor && ! editor.isHidden() ) {
				editor.focus();
				editor.selection.select( editor.getBody(), true );
				editor.selection.collapse( false );
				editor.execCommand(
					'mceInsertContent',
					false,
					`@${ replySlug } `
				);
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initReplyHandler );
	} else {
		initReplyHandler();
	}
}
