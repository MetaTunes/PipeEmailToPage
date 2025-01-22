$(document).ready(function () {
    if ($('#PipeEmailToPage').length) {
        $('#PipeEmailToPage').WireTabs({
            items: $('.WireTab')
        });
    }

    $('iframe.seamless').hover(
        function () {
            var $iframe = $(this);
            var contentHeight = $iframe.contents().find('body').height();
            var maxHeight = Math.min(contentHeight, $(window).height() * 0.5);
            var minHeight = $iframe.height(); // Set the minimum height to the current height
            $iframe.css('height', Math.max(maxHeight, minHeight) + 'px');
        },
        function () {
            $(this).css('height', 'auto');
        }
    );

    var currentHref = null;
    var currentButtonType = null; // Store the button type (delete, reprocess or unspam)

    // Show the modal in the active tab's content
    function showModal(button, message, href) {
        // console.log('showModal');
        // Identify the active content based on the active tab header
        var activeContentId = $("form#PipeEmailToPage .WireTabs .uk-active a").attr("href"); // Get href, e.g., #processed
        var activeContent = $(activeContentId); // Use href to find the corresponding content div

        // Construct the modal and message IDs based on the button type (delete, reprocess, unspam)
        var modalId = "#confirmation-modal-" + button;
        var messageId = "#modal-message-" + button;
        // console.log('showing modal: ' + modalId);

        // Ensure the modal and message show only in the active content
        activeContent.find(messageId).text(message); // Update the correct modal message
        activeContent.find(modalId).fadeIn(); // Show the modal in the active content

        // console.log(activeContent.find(messageId), 'modal message');
        currentHref = href;
        currentButtonType = button; // Store the current button type
    }


    // Close the modal in the active tab's content
    function closeModal(button) {
        // Identify the active content based on the active tab header
        var activeContentId = $("form#PipeEmailToPage .WireTabs .uk-active a").attr("href"); // Get href, e.g., #processed
        var activeContent = $(activeContentId); // Use href to find the corresponding content div

        // Construct the modal ID based on the button type (delete, reprocess or unspam)
        var modalId = "#confirmation-modal-" + button;

        activeContent.find(modalId).fadeOut(); // Hide the modal in the active content
        currentHref = null; // Reset the href after closing
        currentButtonType = null; // Reset the button type
    }

    // Handle "Yes" button in the modal
    $(document).on("click", "#modal-confirm", function () {
        if (currentHref) {
            window.location.href = currentHref; // Redirect only if confirmed
        }
        closeModal(currentButtonType); // Close the modal for the current button type
    });

    // Handle "No" button in the modal
    $(document).on("click", "#modal-cancel", function () {
        closeModal(currentButtonType); // Close the modal for the current button type
    });

    // Use event delegation to capture button clicks for delete, reprocess and unspam
    $(document).on("click", ".delete-email, .unspam-email , .reprocess-email", function (event) {
        event.preventDefault(); // Prevent default link behavior
        event.stopPropagation(); // Prevent the event from bubbling up
        // console.log('button clicked');

        var buttonType;
        switch (true) {
            case $(this).hasClass("delete-email"):
                buttonType = "delete";
                break;
            case $(this).hasClass("reprocess-email"):
                buttonType = "reprocess";
                break;
            default:
                buttonType = "unspam";
                break;
        }

        // console.log('buttonType: ' + buttonType);

        var href = $(this).data("href");
        var message;
        switch (buttonType) {
            case "delete":
                message = ProcessWire.config.PipeEmailToPage.confirmDelete;
                break;
            case "unspam":
                message = ProcessWire.config.PipeEmailToPage.confirmUnspam;
                break;
            case "reprocess":
                message = ProcessWire.config.PipeEmailToPage.confirmReprocess;
                break;
        }
        // console.log('message: ' + message);

        showModal(buttonType, message, href); // Show the appropriate modal
    });

    // Intercept form submission entirely to prevent any unintended POST request
    $("form#PipeEmailToPage").on("submit", function (event) {
        event.preventDefault(); // Always stop the form from submitting
    });



});