jQuery(document).ready(function($) {
    const $form = $('#mdp-download-form');
    const $urlInput = $('#mdp-file-url');
    const $directorySelect = $('#mdp-directory');
    const $customDirectory = $('#mdp-custom-directory');
    const $button = $('#mdp-download-button');
    const $progress = $('#mdp-progress');
    const $progressBar = $('.mdp-progress-bar-fill');
    const $progressText = $('.mdp-progress-text');
    const $result = $('#mdp-result');
    const $debug = $('#mdp-debug');
    const $debugOutput = $('.mdp-debug-output');

    // Handle directory selection
    $directorySelect.on('change', function() {
        if ($(this).val() === 'custom') {
            $customDirectory.show();
        } else {
            $customDirectory.hide();
        }
    });

    // Handle form submission
    $form.on('submit', function(e) {
        e.preventDefault();

        const url = $urlInput.val().trim();
        if (!url) {
            showError('Please enter a valid URL');
            return;
        }

        // Get selected directory
        let directory = $directorySelect.val();
        if (directory === 'custom') {
            directory = $customDirectory.val().trim();
            if (!directory) {
                showError('Please enter a custom directory path');
                return;
            }
        }

        // Reset UI
        $result.hide();
        $debug.hide();
        $progress.show();
        $button.prop('disabled', true);
        $progressBar.css('width', '0%');
        $progressText.text(mdpAjax.progressText);

        // Start progress animation
        let progress = 0;
        const progressInterval = setInterval(function() {
            progress += Math.random() * 10;
            if (progress > 90) {
                clearInterval(progressInterval);
            }
            $progressBar.css('width', progress + '%');
        }, 500);

        // Send AJAX request
        $.ajax({
            url: mdpAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'mdp_download_file',
                nonce: mdpAjax.nonce,
                url: url,
                directory: directory
            },
            success: function(response) {
                clearInterval(progressInterval);
                $progressBar.css('width', '100%');
                
                if (response.success) {
                    showSuccess(response.data.message);
                } else {
                    showError(response.data.message);
                }

                // Show debug information
                $debugOutput.text(JSON.stringify(response.data.debug, null, 2));
                $debug.show();
            },
            error: function(xhr, status, error) {
                clearInterval(progressInterval);
                showError(mdpAjax.errorText);
                
                // Show debug information
                $debugOutput.text(`Status: ${status}\nError: ${error}\nResponse: ${xhr.responseText}`);
                $debug.show();
            },
            complete: function() {
                $button.prop('disabled', false);
                setTimeout(function() {
                    $progress.hide();
                    $progressBar.css('width', '0%');
                }, 1000);
            }
        });
    });

    // Helper functions
    function showSuccess(message) {
        $result
            .removeClass('error')
            .addClass('success')
            .html('<p>' + message + '</p>')
            .show();
    }

    function showError(message) {
        $result
            .removeClass('success')
            .addClass('error')
            .html('<p>' + message + '</p>')
            .show();
    }
}); 