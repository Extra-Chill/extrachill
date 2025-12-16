document.addEventListener( 'DOMContentLoaded', () => {
	const uploadInput = document.getElementById( 'custom-avatar-upload' );
	if ( ! uploadInput ) {
		return;
	}

	const avatarUpload = window.ecAvatarUpload;
	if ( ! avatarUpload ) {
		return;
	}

	const messageContainer = document.getElementById(
		'custom-avatar-upload-message'
	);
	const avatarThumbnail = document.getElementById( 'avatar-thumbnail' );
	if ( ! messageContainer || ! avatarThumbnail ) {
		return;
	}

	const spriteUrl = avatarUpload.spriteUrl || '';
	const avatarImage = avatarThumbnail.querySelector( 'img' );

	function setMessage( { text, loading = false } ) {
		const message = document.createElement( 'p' );

		if ( loading && spriteUrl ) {
			const svg = document.createElement( 'svg' );
			svg.className = 'ec-icon ec-icon-spin';

			const use = document.createElement( 'use' );
			use.setAttribute( 'href', `${ spriteUrl }#spinner` );
			svg.appendChild( use );

			message.appendChild( svg );
			message.appendChild( document.createTextNode( ' ' ) );
		}

		message.appendChild( document.createTextNode( text ) );
		messageContainer.replaceChildren( message );
	}

	uploadInput.addEventListener( 'change', () => {
		const file = uploadInput.files && uploadInput.files[ 0 ];
		if ( ! file ) {
			return;
		}

		if ( ! avatarUpload.restUrl ) {
			setMessage( { text: 'There was an error uploading the avatar.' } );
			return;
		}

		const formData = new FormData();
		formData.append( 'file', file );
		formData.append( 'context', 'user_avatar' );
		formData.append( 'target_id', avatarUpload.userId );

		uploadInput.disabled = true;
		setMessage( {
			text: 'Uploading avatar, please wait...',
			loading: true,
		} );

		fetch(
			new URL( 'extrachill/v1/media', avatarUpload.restUrl ).toString(),
			{
				method: 'POST',
				headers: {
					'X-WP-Nonce': avatarUpload.restNonce,
				},
				body: formData,
			}
		)
			.then( async ( response ) => {
				const data = await response.json().catch( () => null );

				if ( ! response.ok ) {
					throw new Error(
						data && data.message
							? data.message
							: 'There was an error uploading the avatar.'
					);
				}

				return data;
			} )
			.then( ( data ) => {
				if ( data && data.url ) {
					if ( avatarImage ) {
						avatarImage.src = data.url;
						avatarImage.removeAttribute( 'srcset' );
						avatarImage.removeAttribute( 'sizes' );
					} else {
						const img = document.createElement( 'img' );
						img.src = data.url;
						img.alt = 'Avatar';
						avatarThumbnail.appendChild( img );
					}

					setMessage( { text: 'Avatar uploaded successfully!' } );
					return;
				}

				throw new Error( 'There was an error uploading the avatar.' );
			} )
			.catch( ( error ) => {
				setMessage( {
					text:
						error && error.message
							? error.message
							: 'There was an error uploading the avatar.',
				} );
			} )
			.finally( () => {
				uploadInput.disabled = false;
			} );
	} );
} );
