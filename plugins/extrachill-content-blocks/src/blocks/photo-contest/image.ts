/**
 * Shrink a phone photo before upload: long edge capped at `maxEdge`, JPEG.
 * Full-size phone photos (4-8MB) are slow on venue cell data and slow for the
 * server to resize. Any failure returns the original file untouched.
 * @param file    Selected image.
 * @param maxEdge Longest edge in pixels.
 * @param quality JPEG quality 0-1.
 */
export async function shrinkPhoto(
	file: File,
	maxEdge = 2048,
	quality = 0.85
): Promise< File > {
	if (
		! file.type.startsWith( 'image/' ) ||
		file.type === 'image/gif' ||
		typeof createImageBitmap !== 'function'
	) {
		return file;
	}
	try {
		const bitmap = await createImageBitmap( file, {
			imageOrientation: 'from-image',
		} );
		const scale = Math.min(
			1,
			maxEdge / Math.max( bitmap.width, bitmap.height )
		);
		if ( scale === 1 && file.size < 1.5 * 1024 * 1024 ) {
			bitmap.close?.();
			return file;
		}
		const canvas = document.createElement( 'canvas' );
		canvas.width = Math.round( bitmap.width * scale );
		canvas.height = Math.round( bitmap.height * scale );
		const context = canvas.getContext( '2d' );
		if ( ! context ) {
			return file;
		}
		context.drawImage( bitmap, 0, 0, canvas.width, canvas.height );
		bitmap.close?.();
		const blob = await new Promise< Blob | null >( ( resolve ) =>
			canvas.toBlob( resolve, 'image/jpeg', quality )
		);
		if ( ! blob || blob.size >= file.size ) {
			return file;
		}
		const name = file.name.replace( /\.[^.]+$/, '' ) || 'photo';
		return new File( [ blob ], `${ name }.jpg`, { type: 'image/jpeg' } );
	} catch {
		return file;
	}
}
