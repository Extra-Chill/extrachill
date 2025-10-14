import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';

registerBlockType( 'extrachill/password-reset', {
	edit: () => {
		const blockProps = useBlockProps();
		return (
			<div { ...blockProps }>
				<div style={{ padding: '20px', background: '#f0f0f0', border: '1px solid #ddd', borderRadius: '4px', textAlign: 'center' }}>
					<p style={{ margin: '0', fontWeight: 'bold' }}>Password Reset Form</p>
					<p style={{ margin: '10px 0 0', fontSize: '12px', color: '#666' }}>This block displays a password reset form on the frontend.</p>
				</div>
			</div>
		);
	}
} );
