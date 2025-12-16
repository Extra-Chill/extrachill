/**
 * bbPress UI Handlers
 *
 * Jump-to-latest button, sort select auto-submit, and TinyMCE autosave setup.
 */

document.addEventListener( 'DOMContentLoaded', function () {
	const jumpButton = document.querySelector( '#jump-to-latest' );
	if ( jumpButton ) {
		jumpButton.addEventListener( 'click', function () {
			const latestReplyUrl = this.getAttribute( 'data-latest-reply-url' );
			if ( latestReplyUrl ) {
				window.location.href = latestReplyUrl;
			}
		} );
	}

	const sortSelect = document.getElementById( 'sortSelect' );
	const sortingForm = document.getElementById( 'sortingForm' );
	if ( sortSelect && sortingForm ) {
		sortSelect.addEventListener( 'change', function () {
			sortingForm.submit();
		} );
	}
} );

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

	function getReplyDraftPayload( topicId ) {
		const content = editor.getContent( { format: 'raw' } );
		if ( content.trim() === '' ) {
			return null;
		}

		return {
			type: 'reply',
			topic_id: topicId,
			reply_to: 0,
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
				reply_to: 0,
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
