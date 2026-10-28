/**
 * Compose and message-view integration. Hiding local upload and download controls is cosmetic:
 * the server refuses every local upload and download path regardless of this script.
 * The Zemzemeh picker sends only a compose id and a file id; Save to Zemzemeh sends only the
 * message coordinates. The server moves the bytes in both directions.
 */
(function () {
    function label(name) {
        return rcmail.get_label('zemzemeh_dlp.' + name);
    }

    function formatSize(bytes) {
        var units = ['B', 'KB', 'MB', 'GB'], i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return (i ? bytes.toFixed(1) : bytes) + ' ' + units[i];
    }

    function carriesFiles(transfer) {
        return !!transfer && (transfer.files && transfer.files.length > 0
            || Array.prototype.some.call(transfer.items || [], function (item) { return item.kind === 'file'; }));
    }

    function refuse(event) {
        if (carriesFiles(event.dataTransfer || event.clipboardData)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (window.rcmail) {
                rcmail.display_message(label('localuploadblocked'), 'warning');
            }
        }
    }

    // Capture phase runs before Roundcube's own drop and paste handlers.
    ['dragover', 'drop', 'paste'].forEach(function (type) {
        document.addEventListener(type, refuse, true);
    });

    function attach(fileId, dialog) {
        dialog.dialog('close');
        rcmail.http_post('plugin.zemzemeh_dlp.attach', { _id: rcmail.env.compose_id, _file_id: fileId },
            rcmail.set_busy(true, 'uploading'));
    }

    // File names are user data: render them as text nodes only.
    function showPicker(files) {
        var list = document.createElement('ul'), dialog;
        list.className = 'listing zemzemeh-dlp-files';
        if (!files.length) {
            var empty = document.createElement('li');
            empty.textContent = label('pickerempty');
            list.appendChild(empty);
        }
        files.forEach(function (file) {
            var item = document.createElement('li'), button = document.createElement('button');
            button.type = 'button';
            button.className = 'button';
            button.textContent = file.name + ' (' + formatSize(file.size) + ', ' + file.owner_username + ')';
            button.addEventListener('click', function () { attach(file.id, dialog); });
            item.appendChild(button);
            list.appendChild(item);
        });
        dialog = rcmail.show_popup_dialog(list, label('attachfromzemzemeh'), [{
            text: rcmail.get_label('cancel'),
            'class': 'cancel',
            click: function () { $(this).dialog('close'); },
        }]);
    }

    // Message view: each attachment row gets Save to Zemzemeh; the server copies the part
    // from IMAP to Zemzemeh, so the browser sends only the message coordinates.
    function addSaveButtons() {
        var list = rcmail.gui_objects.attachments;
        Object.keys(rcmail.env.attachments || {}).forEach(function (part) {
            var row = document.getElementById('attach' + part), button = document.createElement('button');
            if (!row) {
                return;
            }
            button.type = 'button';
            button.className = 'button zemzemeh-dlp-save';
            button.textContent = label('savetozemzemeh');
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                button.disabled = true;
                rcmail.http_post('plugin.zemzemeh_dlp.save',
                    { _mbox: rcmail.env.mailbox, _uid: rcmail.env.uid, _part: part }, rcmail.set_busy(true, 'loading'));
            });
            row.appendChild(button);
        });
        // Opening an attachment would download it, which the server refuses; say why instead.
        if (list) {
            list.addEventListener('click', function (event) {
                if (event.target.closest('a')) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    rcmail.display_message(label('downloadblocked'), 'warning');
                }
            }, true);
        }
        rcmail.addEventListener('responseafterplugin.zemzemeh_dlp.save', function () {
            document.querySelectorAll('.zemzemeh-dlp-save').forEach(function (button) { button.disabled = false; });
        });
    }

    if (window.rcmail) {
        rcmail.addEventListener('plugin.zemzemeh_dlp_files', showPicker);
        rcmail.addEventListener('init', function () {
            if (rcmail.env.action === 'show' || rcmail.env.action === 'preview') {
                addSaveButtons();
                return;
            }
            ['add-attachment', 'upload-file', 'send-attachment'].forEach(function (command) {
                rcmail.enable_command(command, false);
            });
            document.querySelectorAll('#uploadform, #compose-attachments .upload, #compose-attachments .upload-form, a.button.attach, a.attach')
                .forEach(function (node) { node.style.display = 'none'; });
            // Elastic draws the drop zone (dashed border and icon) from these classes.
            document.querySelectorAll('.droptarget, .file-upload')
                .forEach(function (node) { node.classList.remove('droptarget', 'file-upload'); });

            var list = rcmail.gui_objects.attachmentlist, button = document.createElement('button');
            button.type = 'button';
            button.className = 'button zemzemeh-dlp-attach';
            button.textContent = label('attachfromzemzemeh');
            button.addEventListener('click', function () {
                rcmail.http_get('plugin.zemzemeh_dlp.list', {}, rcmail.set_busy(true, 'loading'));
            });
            if (list && list.parentNode) {
                list.parentNode.insertBefore(button, list);
            }
        });
    }
})();
