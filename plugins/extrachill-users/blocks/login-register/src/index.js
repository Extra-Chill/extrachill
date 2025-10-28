import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import './editor.css';

registerBlockType( 'extrachill/login-register', {
	edit: function( { attributes, setAttributes } ) {
		const blockProps = useBlockProps();
		const { redirectUrl } = attributes;

		return (
			<div { ...blockProps }>
				<div className="login-register-block-preview">
					<p>Login/Register Form</p>
					<p className="block-description">This block displays a tabbed login and registration form on the frontend.</p>

					<div className="redirect-field-wrapper">
						<label htmlFor="redirect-url-input">Redirect URL (Optional)</label>
						<input
							id="redirect-url-input"
							type="text"
							value={redirectUrl}
							onChange={(e) => setAttributes({ redirectUrl: e.target.value })}
							placeholder="https://example.com/"
						/>
						<span className="help-text">Leave empty to stay on current page after login. Set to homepage URL on /login page.</span>
					</div>
				</div>
			</div>
		);
	}
} );
