/**
 * JavaScript for handling the Band Members meta box in the WP Admin.
 */
jQuery(document).ready(function($) {
    // Get necessary data passed from PHP (like AJAX URL and nonces)
    // We expect an object like `bpMemberArgs` created via wp_localize_script
    const ajaxUrl = window.bpMemberArgs?.ajaxUrl || ajaxurl; // Fallback to global ajaxurl if needed
    const searchNonce = window.bpMemberArgs?.searchNonce || $('#bp_member_search_security').val();
    const bandProfileId = window.bpMemberArgs?.postId || $('#post_ID').val(); // Get post ID

    // Store members to add/remove (User IDs)
    let membersToAdd = [];
    let membersToRemove = [];

    // --- User Search Functionality --- 
    let searchTimeout;
    const searchInput = $('#bp-search-artist');
    const searchResultsContainer = $('#bp-artist-search-results');

    searchInput.on('keyup', function() {
        clearTimeout(searchTimeout);
        const searchTerm = $(this).val().trim();

        if (searchTerm.length < 3) { // Only search if term is long enough
            searchResultsContainer.empty().hide();
            return;
        }

        searchTimeout = setTimeout(function() {
            console.log(`Searching for: ${searchTerm}`);
            searchResultsContainer.html('<p>Searching...</p>').show();

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'bp_search_artists', // Matches the PHP AJAX hook
                    security: searchNonce,      // Pass the nonce
                    search_term: searchTerm,
                    band_profile_id: bandProfileId
                },
                success: function(response) {
                    searchResultsContainer.empty();
                    if (response.success && response.data.length > 0) {
                        const resultList = $('<ul></ul>');
                        response.data.forEach(function(user) {
                            // Prevent adding already listed members or those pending removal/addition
                            if ($(`#bp-current-members-list li[data-user-id="${user.ID}"]`).length === 0 &&
                                !membersToAdd.includes(user.ID) &&
                                !membersToRemove.includes(user.ID)) {
                                resultList.append(
                                    $('<li>')
                                        .text(`${user.display_name} (${user.user_login})`)
                                        .append(' ')
                                        .append(
                                            $('<button type="button" class="button button-small bp-select-artist-button">Select</button>')
                                                .data('user-id', user.ID)
                                                .data('user-name', `${user.display_name} (${user.user_login})`)
                                        )
                                );
                            }
                        });
                        if(resultList.children().length > 0) {
                             searchResultsContainer.append(resultList);
                        } else {
                            searchResultsContainer.html('<p>No new artists/admins found.</p>');
                        }
                       
                    } else {
                        searchResultsContainer.html('<p>' + (response.data || 'No results found or error.') + '</p>');
                    }
                    searchResultsContainer.show();
                },
                error: function(xhr, status, error) {
                    console.error("Search AJAX error:", status, error);
                    searchResultsContainer.html('<p>Error performing search.</p>').show();
                }
            });
        }, 500); // Debounce search requests
    });

    // --- Selecting a User from Search Results --- 
    searchResultsContainer.on('click', '.bp-select-artist-button', function() {
        const userId = $(this).data('user-id');
        const userName = $(this).data('user-name');
        
        // Visually add to the main list (or a pending list)
        addMemberToList(userId, userName);
        
        // Clear search input and results
        searchInput.val('');
        searchResultsContainer.empty().hide();
    });

    // --- Add Member Button (If we manually type an ID - maybe disable this) ---
    // Let's rely on selection from search results for now.
    // $('#bp-add-member-button').on('click', function() { ... }); 

    // --- Removing a Member --- 
    $('#bp-current-members-list').on('click', '.bp-remove-member-link', function(e) {
        e.preventDefault();
        const listItem = $(this).closest('li');
        const userId = listItem.data('user-id');

        if (!userId) return;

        // Update state arrays
        if (membersToAdd.includes(userId)) {
            // If removing someone added in this session, just remove from add list
            membersToAdd = membersToAdd.filter(id => id !== userId);
        } else {
            // If removing someone already saved, add to remove list
            if (!membersToRemove.includes(userId)) {
                 membersToRemove.push(userId);
            }
        }
        
        // Visually remove from list
        listItem.remove();
        updateHiddenFields();

        // Check if list is now empty
        if ($('#bp-current-members-list ul li').length === 0) {
             $('#bp-current-members-list').html('<p>' + (window.bpMemberArgs?.noMembersText || 'No members linked yet.') + '</p>');
        }
    });

    // --- Helper Function to Add Member to List --- 
    function addMemberToList(userId, userName) {
         if (!userId || !userName) return;

         // Check if already in the list visually
         if ($(`#bp-current-members-list li[data-user-id="${userId}"]`).length > 0) {
             console.log("User already in list.");
             return;
         }

         // Update state arrays
         if (membersToRemove.includes(userId)) {
             // If re-adding someone marked for removal in this session, just remove from remove list
             membersToRemove = membersToRemove.filter(id => id !== userId);
         } else {
             // If adding a new member, add to add list
             if (!membersToAdd.includes(userId)) {
                 membersToAdd.push(userId);
             }
         }

        // Ensure the UL exists, create if it doesn't (was showing "No members")
        let list = $('#bp-current-members-list ul');
        if (list.length === 0) {
            $('#bp-current-members-list').html('<ul></ul>');
            list = $('#bp-current-members-list ul');
        }

        // Add to list visually
        list.append(
            $('<li data-user-id="' + userId + '">')
                .text(userName)
                .append(' ')
                .append(
                    $('<a href="#" class="bp-remove-member-link" style="color: red; text-decoration: none;" title="Remove this member">[x]</a>')
                )
        );
        updateHiddenFields();
    }

    // --- Update Hidden Fields Before Save --- 
    function updateHiddenFields() {
        $('#bp-members-to-add').val(membersToAdd.join(','));
        $('#bp-members-to-remove').val(membersToRemove.join(','));
        console.log("To Add:", membersToAdd); // Debugging
        console.log("To Remove:", membersToRemove); // Debugging
    }

    // Initial population of remove list if needed (e.g., if JS loads after initial render)
    // No, the remove clicks handle adding to the remove list.

     // Clear search results if clicking outside
     $(document).on('click', function(event) {
         if (!$(event.target).closest('#bp-artist-search-results, #bp-search-artist').length) {
             searchResultsContainer.hide();
         }
     });
}); 