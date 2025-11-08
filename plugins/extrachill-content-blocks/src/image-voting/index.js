import './style.scss';
import './editor.scss';

const { registerBlockType } = wp.blocks;
const { TextControl, Button } = wp.components;
const { MediaUpload } = wp.blockEditor;
const { createElement, useEffect } = wp.element;

function createUniqueID(prefix = '') {
    return `${prefix}-${Date.now()}-${Math.round(Math.random() * 1000000)}`;
}

registerBlockType('extrachill-blocks/image-voting', {
    title: 'Image Voting Block',
    icon: 'thumbs-up',
    category: 'widgets',
    attributes: {
        blockTitle: {
            type: 'string',
            default: 'Vote for this image',
        },
        mediaID: {
            type: 'number',
            default: 0,
        },
        voteCount: {
            type: 'number',
            default: 0,
        },
        uniqueBlockId: {
            type: 'string',
            default: '',
        },
        mediaURL: {
            type: 'string',
            default: '',
        },
    },
    edit: function (props) {
        const { attributes, setAttributes } = props;

        useEffect(() => {
            if (attributes.uniqueBlockId === '') {
                const uniqueBlockId = createUniqueID('block-');
                setAttributes({ uniqueBlockId });
            }
        }, []);

        const onSelectImage = (media) => {
            setAttributes({ mediaID: media.id, mediaURL: media.url });
        };

        return createElement(
            'div',
            {
                className: 'extrachill-blocks-image-voting-editor',
                'data-type': 'extrachill-blocks/image-voting'
            },
            attributes.mediaURL ?
                createElement('div', { className: 'extrachill-blocks-image-wrapper-editor' },
                    createElement('img', {
                        src: attributes.mediaURL,
                        alt: 'Selected image for voting'
                    }),
                    createElement('div', { className: 'extrachill-blocks-overlay-badges-editor' },
                        createElement('span', { className: 'extrachill-blocks-vote-badge-editor' },
                            `Votes: ${attributes.voteCount}`
                        ),
                        createElement('h2', { className: 'extrachill-blocks-title-badge-editor' },
                            attributes.blockTitle
                        )
                    )
                )
                : null,
            createElement('div', { className: 'extrachill-blocks-image-voting-editor-controls' },
                createElement(TextControl, {
                    label: 'Block Title',
                    value: attributes.blockTitle,
                    onChange: (newTitle) => setAttributes({ blockTitle: newTitle }),
                }),
                createElement('p', {},
                    createElement(MediaUpload, {
                        onSelect: onSelectImage,
                        type: 'image',
                        value: attributes.mediaID,
                        render: ({ open }) => createElement(Button, {
                            isPrimary: true,
                            onClick: open
                        }, attributes.mediaURL ? 'Change Image' : 'Select Image')
                    })
                ),
                createElement('p', {}, `Vote Count: ${attributes.voteCount}`)
            )
        );
    },
    save: () => null
});