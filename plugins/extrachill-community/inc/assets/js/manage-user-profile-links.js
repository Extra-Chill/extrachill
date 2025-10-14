jQuery(document).ready(function($) {
    // Data passed from PHP via wp_localize_script (object name: userProfileLinksData)
    if (typeof userProfileLinksData === 'undefined') {
        return;
    }
    const existingLinks = userProfileLinksData.existingLinks || [];
    const linkTypes = userProfileLinksData.linkTypes || {};
    const transRemoveLink = userProfileLinksData.text.removeLink || 'Remove Link';
    const transCustomLabel = userProfileLinksData.text.customLinkLabel || 'Custom Link Label';

    const linksListContainer = $('#user-links-list');
    const addLinkButton = $('#user-add-link-button');
    let linkIndex = 0;

    function renderLinkItem(index, linkData = {}) {
        const typeKey = linkData.type_key || 'website';
        const url = linkData.url || '';
        const customLabel = linkData.custom_label || '';
        let typeOptionsHtml = '';
        for (const key in linkTypes) {
            typeOptionsHtml += `<option value="${key}" ${key === typeKey ? 'selected' : ''}>${linkTypes[key].label}</option>`;
        }
        const showCustomLabel = linkTypes[typeKey]?.has_custom_label || false;
        const itemHtml = `
            <div class="user-dynamic-link-item" data-index="${index}" style="display: flex; flex-wrap: wrap; gap: 10px; padding: 10px; border: 1px solid #eee; margin-bottom: 10px; border-radius: 4px;">
                <div style="flex: 1 1 150px;">
                    <label for="user_links_${index}_type_key" class="screen-reader-text">Link Type</label>
                    <select name="user_links[${index}][type_key]" id="user_links_${index}_type_key" class="user-link-type-select">
                        ${typeOptionsHtml}
                    </select>
                </div>
                <div class="user-link-custom-label-wrapper" style="flex: 1 1 150px; ${showCustomLabel ? '' : 'display: none;'}">
                    <label for="user_links_${index}_custom_label" class="screen-reader-text">${transCustomLabel}</label>
                    <input type="text" name="user_links[${index}][custom_label]" id="user_links_${index}_custom_label" value="${customLabel}" placeholder="${transCustomLabel}">
                </div>
                <div style="flex: 2 1 300px;">
                    <label for="user_links_${index}_url" class="screen-reader-text">URL</label>
                    <input type="url" name="user_links[${index}][url]" id="user_links_${index}_url" value="${url}" placeholder="https://..." required style="width: 100%;">
                </div>
                <div style="flex: 0 0 auto;">
                    <button type="button" class="button-1 button-small user-remove-link-button" title="${transRemoveLink}">&times;</button>
                </div>
            </div>
        `;
        return itemHtml;
    }

    // Initial rendering
    if (existingLinks.length > 0) {
        existingLinks.forEach(link => {
            linksListContainer.append(renderLinkItem(linkIndex, link));
            linkIndex++;
        });
    }

    // Add Link Button
    addLinkButton.on('click', function() {
        linksListContainer.append(renderLinkItem(linkIndex));
        linkIndex++;
    });

    // Remove Link Button
    linksListContainer.on('click', '.user-remove-link-button', function() {
        $(this).closest('.user-dynamic-link-item').remove();
    });

    // Link Type Dropdown Change Handler
    linksListContainer.on('change', '.user-link-type-select', function() {
        const selectedType = $(this).val();
        const $item = $(this).closest('.user-dynamic-link-item');
        const $customLabelWrapper = $item.find('.user-link-custom-label-wrapper');
        const $customLabelInput = $customLabelWrapper.find('input');
        if (linkTypes[selectedType]?.has_custom_label) {
            $customLabelWrapper.show();
        } else {
            $customLabelWrapper.hide();
            $customLabelInput.val('');
        }
    });
}); 