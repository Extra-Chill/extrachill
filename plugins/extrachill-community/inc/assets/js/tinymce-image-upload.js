( function () {
	const { tinymce } = window;
	if ( ! tinymce ) {
		return;
	}

	tinymce.PluginManager.add(
		'local_upload_plugin',
		function ( editorInstance ) {
			editorInstance.addButton( 'image', {
				title: 'Upload Image',
				icon: 'image',
				onclick() {
					triggerFileInput();
				},
				onPostRender() {
					const btn = this.getEl();
					btn.ontouchend = function () {
						triggerFileInput();
					};
				},
			} );

			function triggerFileInput() {
				const input = document.createElement( 'input' );
				input.setAttribute( 'type', 'file' );
				input.setAttribute( 'accept', 'image/*' );
				input.style.display = 'none';

				document.body.appendChild( input );

				input.onchange = function ( e ) {
					const file = e.target.files[ 0 ];
					if ( file ) {
						uploadImage( file, editorInstance );
					}
					document.body.removeChild( input );
				};

				input.click();
			}

			function removeNode( node ) {
				if ( node && node.parentNode ) {
					node.parentNode.removeChild( node );
				}
			}

			function uploadImage( file, editorInstanceForUpload ) {
				const editorContext = window.extrachillCommunityEditor || {};
				if ( ! editorContext.restNonce || ! editorContext.restUrl ) {
					return;
				}

				const formData = new FormData();
				formData.append( 'file', file );
				formData.append( 'context', 'content_embed' );

				const loader = document.createElement( 'div' );
				loader.className = 'extrachill-editor-upload-notice';
				loader.textContent = 'Image loading, please wait...';
				editorInstanceForUpload.getContainer().appendChild( loader );

				fetch(
					new URL(
						'extrachill/v1/media',
						editorContext.restUrl
					).toString(),
					{
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'X-WP-Nonce': editorContext.restNonce,
						},
						body: formData,
					}
				)
					.then( function ( response ) {
						if ( response.ok ) {
							return response.json();
						}

						return response.json().then( function ( err ) {
							return Promise.reject( err );
						} );
					} )
					.then( function ( data ) {
						if ( data?.url ) {
							const content = `<p><img src="${ data.url }" class="uploaded-image" /></p><p><br /></p>`;
							editorInstanceForUpload.insertContent( content );
							editorInstanceForUpload.focus();
							editorInstanceForUpload.selection.collapse( false );
						}

						removeNode( loader );
					} )
					.catch( function () {
						removeNode( loader );
					} );
			}

			function removeOverlay() {
				const container = editorInstance.getContainer();
				container.classList.remove( 'mce-drag-over' );
				const overlay = container.querySelector( '.mce-drag-overlay' );
				removeNode( overlay );
			}

			editorInstance.on( 'dragover', function ( e ) {
				e.preventDefault();
				const container = editorInstance.getContainer();
				container.classList.add( 'mce-drag-over' );
				if ( ! container.querySelector( '.mce-drag-overlay' ) ) {
					const overlay = document.createElement( 'div' );
					overlay.className = 'mce-drag-overlay';
					overlay.innerText = 'Drop image to upload';
					container.appendChild( overlay );
				}

				document.addEventListener( 'click', removeOverlay, {
					once: true,
				} );
			} );

			editorInstance.on( 'dragleave dragend drop', function () {
				removeOverlay();
			} );

			editorInstance.on( 'drop', function ( e ) {
				e.preventDefault();
				const dataTransfer = e.dataTransfer;
				if ( ! dataTransfer?.files?.length ) {
					return;
				}

				const file = dataTransfer.files[ 0 ];
				if ( file?.type?.startsWith( 'image/' ) ) {
					uploadImage( file, editorInstance );
				}
			} );

			editorInstance.on( 'paste', function ( e ) {
				const clipboardData = e.clipboardData || window.clipboardData;
				if ( ! clipboardData?.items ) {
					return;
				}

				const items = clipboardData.items;
				for ( let i = 0; i < items.length; i++ ) {
					const item = items[ i ];
					if ( item.type.indexOf( 'image' ) !== -1 ) {
						const file = item.getAsFile();
						if ( file ) {
							e.preventDefault();
							uploadImage( file, editorInstance );
						}
					}
				}
			} );

			editorInstance.on( 'focus', function () {
				const { navigator } = window;
				if (
					! navigator ||
					! /Mobi|Android/i.test( navigator.userAgent )
				) {
					return;
				}

				document.addEventListener(
					'paste',
					function ( e ) {
						const clipboardData =
							e.clipboardData || window.clipboardData;
						if ( ! clipboardData?.items ) {
							return;
						}

						const items = clipboardData.items;
						for ( let i = 0; i < items.length; i++ ) {
							const item = items[ i ];
							if ( item.type.indexOf( 'image' ) !== -1 ) {
								const file = item.getAsFile();
								if ( file ) {
									e.preventDefault();
									uploadImage( file, editorInstance );
								}
							}
						}
					},
					{ once: true }
				);
			} );
		}
	);
} )();
