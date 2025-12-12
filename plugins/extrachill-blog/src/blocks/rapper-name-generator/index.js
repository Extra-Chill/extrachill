import './style.scss';

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

registerBlockType('extrachill/rapper-name-generator', {
    edit: ({ attributes, setAttributes }) => {
        const { title, buttonText } = attributes;
        const blockProps = useBlockProps();

        return (
            <>
                <InspectorControls>
                    <PanelBody title={__('Generator Settings', 'extrachill-blog')}>
                        <TextControl
                            label={__('Title', 'extrachill-blog')}
                            value={title}
                            onChange={(value) => setAttributes({ title: value })}
                        />
                        <TextControl
                            label={__('Button Text', 'extrachill-blog')}
                            value={buttonText}
                            onChange={(value) => setAttributes({ buttonText: value })}
                        />
                    </PanelBody>
                </InspectorControls>
                <div {...blockProps}>
                    <div className="extrachill-blocks-generator-preview">
                        <RichText
                            tagName="h3"
                            value={title}
                            onChange={(value) => setAttributes({ title: value })}
                            placeholder={__('Enter title...', 'extrachill-blog')}
                        />
                        <button className="extrachill-blocks-generator-button" disabled>
                            {buttonText}
                        </button>
                        <div className="extrachill-blocks-generator-result">
                            <em>{__('Generated name will appear here', 'extrachill-blog')}</em>
                        </div>
                    </div>
                </div>
            </>
        );
    },
    save: () => null // Dynamic block rendered via render.php
});