/**
 * Cosmetic only: hides local attachment controls in compose. The server refuses every
 * local upload path (picker, drag-and-drop, paste, direct requests) regardless of this script.
 */
(function () {
    function carriesFiles(transfer) {
        return !!transfer && (transfer.files && transfer.files.length > 0
            || Array.prototype.some.call(transfer.items || [], function (item) { return item.kind === 'file'; }));
    }

    function refuse(event) {
        if (carriesFiles(event.dataTransfer || event.clipboardData)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (window.rcmail) {
                rcmail.display_message(rcmail.get_label('zemzemeh_dlp.localuploadblocked'), 'warning');
            }
        }
    }

    // Capture phase runs before Roundcube's own drop and paste handlers.
    ['dragover', 'drop', 'paste'].forEach(function (type) {
        document.addEventListener(type, refuse, true);
    });

    if (window.rcmail) {
        rcmail.addEventListener('init', function () {
            ['add-attachment', 'upload-file', 'send-attachment'].forEach(function (command) {
                rcmail.enable_command(command, false);
            });
            document.querySelectorAll('#uploadform, #compose-attachments .upload, a.button.attach, a.attach')
                .forEach(function (node) { node.style.display = 'none'; });
        });
    }
})();
