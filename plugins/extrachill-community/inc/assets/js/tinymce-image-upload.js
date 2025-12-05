(function waitForTinyMCE() {
    var maxRetries = 5; // Maximum number of retries
    var retryCount = 0; // Current retry attempt

    function initPlugin() {
        if (window.tinymce && typeof customTinymcePlugin !== 'undefined') {
            tinymce.PluginManager.add('local_upload_plugin', function(editor) {
                editor.addButton('image', {
                    title: 'Upload Image',
                    icon: 'image',
                    onclick: function() {
                        triggerFileInput();
                    },
                    onPostRender: function() {
                        var btn = this.getEl();
                        btn.ontouchend = function() {
                            triggerFileInput(); // Call the same function for touchend event
                        };
                    }
                });

                function triggerFileInput() {
                    let input = document.createElement('input');
                    input.setAttribute('type', 'file');
                    input.setAttribute('accept', 'image/*');
                    input.style.display = 'none';

                    document.body.appendChild(input);

                    input.onchange = function(e) {
                        var file = e.target.files[0];
                        if (file) {
                            uploadImage(file, editor);
                        }
                        document.body.removeChild(input);
                    };

                    input.click();
                }

                function uploadImage(file, editor) {
                    var formData = new FormData();
                    formData.append('image', file);

                    // Add a loading indicator here
                    var loader = document.createElement('div');
                    loader.innerHTML = '<div style="text-align:center;color: #53940b;"><i class="fa fa-spinner fa-spin fa-2x"></i> Image loading, please wait...</div>';
                    editor.getContainer().appendChild(loader);

                    fetch('/wp-json/extrachill/v1/community/upload-image', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-WP-Nonce': customTinymcePlugin.restNonce
                        },
                        body: formData
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => Promise.reject(err));
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.url) {
                            var content = '<p><img src="' + data.url + '" style="max-width:50%;" class="uploaded-image" /></p><p><br /></p>';
                            editor.insertContent(content);
                            editor.focus();
                            editor.selection.collapse(false);
                        }

                        if (loader.parentNode) {
                            loader.parentNode.removeChild(loader);
                        }
                    })
                    .catch(error => {
                        console.error("Upload error:", error.message || error);
                        if (loader.parentNode) {
                            loader.parentNode.removeChild(loader);
                        }
                    });
                }

                function removeOverlay() {
                    var container = editor.getContainer();
                    container.classList.remove('mce-drag-over');
                    var overlay = container.querySelector('.mce-drag-overlay');
                    if (overlay) {
                        container.removeChild(overlay);
                    }
                }

                // Add drag-and-drop functionality with visual indicator
                editor.on('dragover', function(e) {
                    e.preventDefault();
                    var container = editor.getContainer();
                    container.classList.add('mce-drag-over');
                    if (!container.querySelector('.mce-drag-overlay')) {
                        var overlay = document.createElement('div');
                        overlay.className = 'mce-drag-overlay';
                        overlay.innerText = 'Drop image to upload';
                        container.appendChild(overlay);
                    }

                    // Remove the overlay when the user clicks anywhere
                    document.addEventListener('click', removeOverlay, { once: true });
                });

                editor.on('dragleave dragend drop', function(e) {
                    removeOverlay();
                });

                editor.on('drop', function(e) {
                    e.preventDefault();
                    var dataTransfer = e.dataTransfer;
                    if (dataTransfer && dataTransfer.files && dataTransfer.files.length) {
                        var file = dataTransfer.files[0];
                        if (file.type.startsWith('image/')) {
                            uploadImage(file, editor);
                        } else {
                            console.error("Only image files are supported");
                        }
                    }
                });

                // Add paste functionality
                editor.on('paste', function(e) {
                    var clipboardData = e.clipboardData || window.clipboardData;
                    if (clipboardData && clipboardData.items) {
                        var items = clipboardData.items;
                        for (var i = 0; i < items.length; i++) {
                            var item = items[i];
                            if (item.type.indexOf("image") !== -1) {
                                var file = item.getAsFile();
                                if (file) {
                                    e.preventDefault(); // Prevent default paste behavior
                                    uploadImage(file, editor);
                                }
                            }
                        }
                    }
                });

                // Add fallback for mobile devices
                editor.on('focus', function() {
                    if (/Mobi|Android/i.test(navigator.userAgent)) {
                        document.addEventListener('paste', function(e) {
                            var clipboardData = e.clipboardData || window.clipboardData;
                            if (clipboardData && clipboardData.items) {
                                var items = clipboardData.items;
                                for (var i = 0; i < items.length; i++) {
                                    var item = items[i];
                                    if (item.type.indexOf("image") !== -1) {
                                        var file = item.getAsFile();
                                        if (file) {
                                            e.preventDefault(); // Prevent default paste behavior
                                            uploadImage(file, editor);
                                        }
                                    }
                                }
                            }
                        }, { once: true });
                    }
                });
            });

            tinymce.init({
                selector: 'textarea',
            });
        } else {
            console.log("TinyMCE or customTinymcePlugin not detected, retrying...");
            retryCount++;
            if (retryCount < maxRetries) {
                setTimeout(initPlugin, 1000); // Retry with a delay
            } else {
                console.error("Failed to initialize TinyMCE after " + maxRetries + " retries.");
            }
        }
    }

    initPlugin();
})();
