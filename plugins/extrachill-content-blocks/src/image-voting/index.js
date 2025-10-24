const { registerBlockType } = wp.blocks;
const { TextControl, Button } = wp.components;
const { MediaUpload } = wp.blockEditor;
const { createElement, useEffect } = wp.element;

// Function to create a unique ID for each block
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

        // Set a unique ID if not already set
        useEffect(() => {
            if (attributes.uniqueBlockId === '') {
                const uniqueBlockId = createUniqueID('block-');
                setAttributes({ uniqueBlockId });
            }
        }, []);

        // Function to handle image selection
        const onSelectImage = (media) => {
            setAttributes({ mediaID: media.id, mediaURL: media.url });
        };

        return createElement(
            'div',
            { className: 'extrachill-blocks-image-voting-editor' },
            createElement(TextControl, {
                label: 'Block Title',
                value: attributes.blockTitle,
                onChange: (newTitle) => setAttributes({ blockTitle: newTitle }),
            }),
            attributes.mediaURL ?
                createElement('img', {
                    src: attributes.mediaURL,
                    style: { maxWidth: '250px', height: 'auto', margin: '10px 0' },
                    alt: 'Selected image for voting'
                })
                : null,
            createElement('p', {}, // Wrap the "Select Image" button in a <p>
                createElement(MediaUpload, {
                    onSelect: onSelectImage,
                    type: 'image',
                    value: attributes.mediaID,
                    render: ({ open }) => createElement(Button, { isPrimary: true, onClick: open }, 'Select Image')
                })
            ),
            createElement('p', {}, `Vote Count: ${attributes.voteCount}`)
        );
    },
    save: () => null // Dynamic block rendered via render.php
});