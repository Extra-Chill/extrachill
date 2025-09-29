const { registerBlockType } = wp.blocks;
const { TextControl, Button } = wp.components;
const { MediaUpload } = wp.blockEditor;
const { createElement, useEffect, useState } = wp.element;
const { apiFetch } = wp;

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
        const [currentVoteCount, setCurrentVoteCount] = useState(attributes.voteCount);

        // Set a unique ID if not already set
        useEffect(() => {
            if (attributes.uniqueBlockId === '') {
                const uniqueBlockId = createUniqueID('block-');
                setAttributes({ uniqueBlockId });
            }
        }, []);

        // Fetch the current vote count from the server when uniqueBlockId is available
        useEffect(() => {
            if (attributes.uniqueBlockId) {
                apiFetch({
                    path: `/wp/v2/vote_count/${attributes.uniqueBlockId}`,
                }).then((response) => {
                    setCurrentVoteCount(response.vote_count);
                    setAttributes({ voteCount: response.vote_count });
                }).catch((error) => {
                    console.error("Error fetching vote count:", error);
                });
            }
        }, [attributes.uniqueBlockId]);

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
            createElement('p', {}, `Vote Count: ${currentVoteCount}`) // Display live vote count
        );
    },
    save: function () {
        return null; // Dynamic block, rendered server-side
    },
});