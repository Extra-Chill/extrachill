import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';

registerBlockType( 'extrachill/onboarding', {
	edit: function() {
		const blockProps = useBlockProps();

		return (
			<div { ...blockProps }>
				<div className="onboarding-block-preview">
					<h3>User Onboarding Form</h3>
					<p>This block displays the onboarding form for new users.</p>
					<p className="onboarding-block-note">Users will set their username and profile options here.</p>
				</div>
			</div>
		);
	}
} );
