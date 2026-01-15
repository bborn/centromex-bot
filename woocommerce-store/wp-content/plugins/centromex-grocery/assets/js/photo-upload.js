/**
 * Photo Upload & Progress Tracking
 * Handles drag & drop, AJAX uploads, and real-time progress updates
 */

(function($) {
    'use strict';

    let selectedFiles = [];
    let currentBatchId = null;
    let progressInterval = null;

    const dropZone = $('#centromex-drop-zone');
    const fileInput = $('#centromex-file-input');
    const filePreview = $('#centromex-file-preview');
    const startButton = $('#centromex-start-import');
    const progressSection = $('#centromex-progress-section');
    const uploadSection = $('.centromex-upload-section');

    // Initialize
    $(document).ready(function() {
        initDragAndDrop();
        initFileInput();
        initStartButton();
        initRestartButton();
    });

    /**
     * Initialize drag and drop
     */
    function initDragAndDrop() {
        dropZone.on('click', function(e) {
            if (e.target === this || $(e.target).closest('.centromex-drop-zone-content').length) {
                fileInput.click();
            }
        });

        dropZone.on('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragover');
        });

        dropZone.on('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');
        });

        dropZone.on('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');

            const files = e.originalEvent.dataTransfer.files;
            handleFiles(files);
        });
    }

    /**
     * Initialize file input
     */
    function initFileInput() {
        fileInput.on('change', function() {
            handleFiles(this.files);
        });
    }

    /**
     * Initialize start button
     */
    function initStartButton() {
        startButton.on('click', function() {
            if (selectedFiles.length === 0) {
                return;
            }

            uploadFiles();
        });
    }

    /**
     * Initialize restart button
     */
    function initRestartButton() {
        $('#centromex-import-another').on('click', function() {
            resetUploadInterface();
        });
    }

    /**
     * Handle selected files
     */
    function handleFiles(files) {
        const validFiles = [];
        const errors = [];

        Array.from(files).forEach(file => {
            // Validate file type
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
                errors.push(file.name + ': ' + centromexPhotoImport.strings.invalidFileType);
                return;
            }

            // Validate file size
            if (file.size > centromexPhotoImport.maxFileSize) {
                errors.push(file.name + ': ' + centromexPhotoImport.strings.fileTooLarge);
                return;
            }

            validFiles.push(file);
        });

        // Check total count
        if (selectedFiles.length + validFiles.length > centromexPhotoImport.maxFiles) {
            alert(centromexPhotoImport.strings.tooManyFiles);
            return;
        }

        // Show errors
        if (errors.length > 0) {
            alert(errors.join('\n'));
        }

        // Add valid files
        selectedFiles = selectedFiles.concat(validFiles);
        updateFilePreview();
        startButton.prop('disabled', selectedFiles.length === 0);
    }

    /**
     * Update file preview
     */
    function updateFilePreview() {
        filePreview.empty();

        selectedFiles.forEach((file, index) => {
            const preview = $('<div class="file-preview-item"></div>');

            // Create thumbnail
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.html(`
                    <img src="${e.target.result}" alt="${file.name}">
                    <span class="filename">${file.name}</span>
                    <button class="remove-file" data-index="${index}">&times;</button>
                `);
            };
            reader.readAsDataURL(file);

            filePreview.append(preview);
        });

        // Bind remove buttons
        $('.remove-file').on('click', function() {
            const index = $(this).data('index');
            selectedFiles.splice(index, 1);
            updateFilePreview();
            startButton.prop('disabled', selectedFiles.length === 0);
        });
    }

    /**
     * Upload files via AJAX
     */
    function uploadFiles() {
        const formData = new FormData();

        selectedFiles.forEach((file, index) => {
            formData.append('photos[]', file);
        });

        formData.append('action', 'centromex_upload_photos');
        formData.append('nonce', centromexPhotoImport.nonce);

        // Disable button
        startButton.prop('disabled', true).text('Uploading...');

        $.ajax({
            url: centromexPhotoImport.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    currentBatchId = response.data.batch_id;
                    showProgressSection();
                    startProgressTracking();
                } else {
                    alert(response.data.message || centromexPhotoImport.strings.uploadError);
                    startButton.prop('disabled', false).text('Start Import');
                }
            },
            error: function() {
                alert(centromexPhotoImport.strings.uploadError);
                startButton.prop('disabled', false).text('Start Import');
            }
        });
    }

    /**
     * Show progress section
     */
    function showProgressSection() {
        uploadSection.hide();
        progressSection.show();
    }

    /**
     * Start progress tracking
     */
    function startProgressTracking() {
        progressInterval = setInterval(checkProgress, 2000); // Poll every 2 seconds
        checkProgress(); // Check immediately
    }

    /**
     * Check import progress
     */
    function checkProgress() {
        if (!currentBatchId) {
            return;
        }

        $.ajax({
            url: centromexPhotoImport.ajaxUrl,
            type: 'GET',
            data: {
                action: 'centromex_get_progress',
                nonce: centromexPhotoImport.nonce,
                batch_id: currentBatchId
            },
            success: function(response) {
                if (response.success) {
                    updateProgress(response.data);
                }
            }
        });
    }

    /**
     * Update progress UI
     */
    function updateProgress(data) {
        const processed = data.processed_images || 0;
        const total = data.total_images || 0;
        const products = data.total_products || 0;
        const verified = data.verified_products || 0;
        const review = data.review_products || 0;
        const status = data.status || 'processing';

        // Update progress bar
        const percentage = total > 0 ? (processed / total) * 100 : 0;
        $('#centromex-progress-bar').css('width', percentage + '%');

        // Update stats
        $('#stat-images').text(`${processed} / ${total}`);
        $('#stat-products').text(products);
        $('#stat-verified').text(verified);
        $('#stat-review').text(review);

        // Update status message
        if (status === 'completed') {
            $('#centromex-status-message').text('Processing complete!');
            $('#centromex-completion-message').show();
            clearInterval(progressInterval);
        } else if (data.current_image) {
            $('#centromex-status-message').text(`Processing: ${data.current_image}...`);
        } else {
            $('#centromex-status-message').text('Processing images...');
        }
    }

    /**
     * Reset upload interface
     */
    function resetUploadInterface() {
        selectedFiles = [];
        currentBatchId = null;
        if (progressInterval) {
            clearInterval(progressInterval);
        }

        filePreview.empty();
        startButton.prop('disabled', true).text('Start Import');
        uploadSection.show();
        progressSection.hide();
        $('#centromex-completion-message').hide();
        $('#centromex-progress-bar').css('width', '0%');
        $('#stat-images, #stat-products, #stat-verified, #stat-review').text('0');
        $('#centromex-status-message').text('');
    }

})(jQuery);
