( function( blocks, element, blockEditor ) {
	const el = element.createElement;
	const useBlockProps = blockEditor.useBlockProps;

	function ContactFormEdit() {
		const blockProps = useBlockProps( {
			style: {
				padding: '2rem',
				backgroundColor: '#f8f9fa',
				border: '1px dashed #ccc',
				borderRadius: '4px',
				textAlign: 'center',
			},
		} );

		return el(
			'div',
			blockProps,
			el( 'p', { style: { fontSize: '1.25rem', margin: '0 0 0.5rem' } }, 'Contact Form' ),
			el( 'small', { style: { color: '#666' } }, 'Form displays on the frontend' )
		);
	}

	blocks.registerBlockType( 'extrachill/contact-form', {
		edit: ContactFormEdit,
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor );
