( function ( blocks, element, blockEditor ) {
	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;

	blocks.registerBlockType( 'extrachill/onboarding', {
		edit: function () {
			var blockProps = useBlockProps();

			return el(
				'div',
				blockProps,
				el( 'div', { className: 'onboarding-block-preview' },
					el( 'h3', {}, 'User Onboarding Form' ),
					el( 'p', {}, 'This block displays the onboarding form for new users.' ),
					el( 'p', { className: 'onboarding-block-note' }, 'Users will set their username and profile options here.' )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor );
