import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, RichText, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

registerBlockType('extrachill-blocks/rapper-name-generator', {
    edit: ({ attributes, setAttributes }) => {
        const { title, buttonText } = attributes;
        const blockProps = useBlockProps();

        return (
            <>
                <InspectorControls>
                    <PanelBody title={__('Generator Settings', 'extrachill-blocks')}>
                        <TextControl
                            label={__('Title', 'extrachill-blocks')}
                            value={title}
                            onChange={(value) => setAttributes({ title: value })}
                        />
                        <TextControl
                            label={__('Button Text', 'extrachill-blocks')}
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
                            placeholder={__('Enter title...', 'extrachill-blocks')}
                        />
                        <button className="extrachill-blocks-generator-button" disabled>
                            {buttonText}
                        </button>
                        <div className="extrachill-blocks-generator-result">
                            <em>{__('Generated name will appear here', 'extrachill-blocks')}</em>
                        </div>
                    </div>
                </div>
            </>
        );
    },
    save: () => null // Dynamic block, rendered server-side
});