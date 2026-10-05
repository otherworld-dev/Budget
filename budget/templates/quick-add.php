<?php
style('budget', 'style');
$accounts = json_decode($_['accounts'], true);
$categories = json_decode($_['categories'], true);
// A translation as a JavaScript string literal. p() escapes for HTML, which
// inside the script below shows an apostrophe as a literal &#039; (French
// "Enregistrer l'opération").
$js = static fn (string $text): string => json_encode($text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
?>

<div id="quick-add-page">
    <div class="quick-add-header">
        <h2><?php p($l->t('Quick Add Transaction')); ?></h2>
        <a href="<?php echo \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('budget.page.index'); ?>" class="quick-add-back">
            <?php p($l->t('Open Budget')); ?> &rarr;
        </a>
    </div>

    <form id="quick-add-form" class="quick-add-standalone-form">
        <div class="qa-form-group">
            <span class="qa-label"><?php p($l->t('Receipt')); ?></span>
            <button type="button" id="qa-receipt-btn" class="qa-receipt-btn"><?php p($l->t('Upload receipt')); ?></button>
            <input type="file" id="qa-receipt" accept="image/*,application/pdf" hidden>
            <div id="qa-receipt-chosen" class="qa-receipt-chosen" hidden>
                <img id="qa-receipt-thumb" class="qa-receipt-thumb" alt="" hidden>
                <span id="qa-receipt-name" class="qa-receipt-name"></span>
                <button type="button" id="qa-receipt-remove" class="qa-receipt-remove" title="<?php p($l->t('Remove attachment')); ?>" aria-label="<?php p($l->t('Remove attachment')); ?>">✕</button>
            </div>
            <small id="qa-receipt-note" class="qa-receipt-note" aria-live="polite" hidden></small>
        </div>

        <div class="qa-form-group">
            <label for="qa-date"><?php p($l->t('Date')); ?></label>
            <input type="date" id="qa-date" required value="<?php echo date('Y-m-d'); ?>">
        </div>

        <div class="qa-form-group">
            <label for="qa-account"><?php p($l->t('Account')); ?></label>
            <select id="qa-account" required>
                <option value=""><?php p($l->t('Select account...')); ?></option>
                <?php foreach ($accounts as $account): ?>
                    <option value="<?php p($account['id']); ?>" data-owner="<?php p($account['owner'] ?? ''); ?>"><?php p($account['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="qa-form-group">
            <label for="qa-type"><?php p($l->t('Type')); ?></label>
            <select id="qa-type" required>
                <option value="debit"><?php p($l->t('Expense')); ?></option>
                <option value="credit"><?php p($l->t('Income')); ?></option>
            </select>
        </div>

        <div class="qa-form-group">
            <label for="qa-amount"><?php p($l->t('Amount')); ?></label>
            <input type="number" id="qa-amount" step="0.01" min="0" required placeholder="0.00">
        </div>

        <div class="qa-form-group">
            <label for="qa-description"><?php p($l->t('Description')); ?></label>
            <input type="text" id="qa-description" required maxlength="255" placeholder="<?php p($l->t('What was this for?')); ?>" autocomplete="off">
        </div>

        <div class="qa-form-group">
            <label for="qa-vendor"><?php p($l->t('Vendor')); ?></label>
            <input type="text" id="qa-vendor" maxlength="255" placeholder="<?php p($l->t('Shop or person (optional)')); ?>" autocomplete="off">
        </div>

        <div class="qa-form-group">
            <label for="qa-category"><?php p($l->t('Category')); ?></label>
            <select id="qa-category">
                <option value=""><?php p($l->t('Uncategorized')); ?></option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php p($cat['id']); ?>" data-type="<?php p($cat['type']); ?>" data-owner="<?php p($cat['owner'] ?? ''); ?>"><?php p(str_repeat("\u{00A0}\u{00A0}", $cat['level']) . $cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="qa-form-group">
            <label for="qa-notes"><?php p($l->t('Notes')); ?></label>
            <textarea id="qa-notes" maxlength="500" rows="2" placeholder="<?php p($l->t('Optional notes')); ?>"></textarea>
        </div>

        <div class="qa-buttons">
            <button type="submit" class="primary qa-submit-btn"><?php p($l->t('Save Transaction')); ?></button>
            <button type="reset" class="secondary qa-reset-btn"><?php p($l->t('Clear')); ?></button>
        </div>

        <div id="qa-status" class="qa-status" style="display: none;"></div>
    </form>
</div>

<script nonce="<?php p(\OCP\Server::get(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)->getNonce()); ?>">
(function() {
    'use strict';

    var strings = {
        quickAdd: <?php echo $js($l->t('Quick Add')); ?>,
        saving: <?php echo $js($l->t('Saving...')); ?>,
        save: <?php echo $js($l->t('Save Transaction')); ?>,
        saved: <?php echo $js($l->t('Transaction saved!')); ?>,
        saveFailed: <?php echo $js($l->t('Failed to save transaction')); ?>,
        scanHint: <?php echo $js($l->t('The photo is read on your server and fills in the form for you to check. It is attached to the transaction when you save.')); ?>,
        reading: <?php echo $js($l->t('Reading the receipt…')); ?>,
        readFailed: <?php echo $js($l->t('The receipt could not be read')); ?>,
        filledIn: <?php echo $js($l->t('Filled in: {fields}. Check them before saving.')); ?>,
        attachedOnSave: <?php echo $js($l->t('The photo will be attached when you save.')); ?>,
        ownAccountsOnly: <?php echo $js($l->t('Receipts can only be attached to transactions in your own accounts.')); ?>,
        receiptNotAttached: <?php echo $js($l->t('The transaction was saved, but the receipt could not be attached. Open it in Budget to add the receipt.')); ?>,
        date: <?php echo $js($l->t('date')); ?>,
        amount: <?php echo $js($l->t('amount')); ?>,
        description: <?php echo $js($l->t('description')); ?>,
        vendor: <?php echo $js($l->t('vendor')); ?>,
        category: <?php echo $js($l->t('category')); ?>,
    };

    // Make "Add to Home Screen" / "Install" open this page rather than
    // Budget's main page (#530). The layout's head already carries
    // Nextcloud's manifest and icons for the app, and those come first, so
    // tags added through addHeader would be ignored; repoint them instead.
    // The manifest URL is built from this page's own path so its relative
    // start URL matches however the page was reached (with or without
    // index.php).
    var manifestLink = document.querySelector('link[rel="manifest"]');
    if (manifestLink) {
        manifestLink.href = window.location.pathname.replace(/\/+$/, '') + '/manifest';
    }
    document.querySelectorAll('link[rel^="apple-touch-icon"]').forEach(function(link) {
        link.href = '<?php p($_['touchIcon']); ?>';
    });
    var appTitle = document.querySelector('meta[name="apple-mobile-web-app-title"]');
    if (appTitle) {
        appTitle.content = strings.quickAdd;
    }

    // Ensure the Nextcloud content wrapper is scrollable
    var content = document.getElementById('content');
    if (content) {
        content.style.overflowY = 'auto';
    }

    var form = document.getElementById('quick-add-form');
    var statusEl = document.getElementById('qa-status');
    var typeSelect = document.getElementById('qa-type');
    var categorySelect = document.getElementById('qa-category');

    var accountSelect = document.getElementById('qa-account');

    // Filter categories by type, and by the account's owner: an account
    // someone shared with you takes only their categories (the server
    // refuses any other there)
    function offered(opt) {
        var catType = typeSelect.value === 'credit' ? 'income' : 'expense';
        var account = accountSelect.options[accountSelect.selectedIndex];
        var owner = account ? (account.dataset.owner || '') : '';
        return opt.dataset.type === catType && (owner === '' || opt.dataset.owner === owner);
    }

    function filterCategories() {
        var options = categorySelect.querySelectorAll('option[data-type]');
        options.forEach(function(opt) {
            var show = offered(opt);
            opt.style.display = show ? '' : 'none';
            opt.disabled = !show;
        });
        // Reset selection if current is hidden
        var selected = categorySelect.options[categorySelect.selectedIndex];
        if (selected && selected.dataset.type && !offered(selected)) {
            categorySelect.value = '';
        }
    }

    typeSelect.addEventListener('change', filterCategories);
    accountSelect.addEventListener('change', filterCategories);
    filterCategories();

    function showStatus(message, isError) {
        statusEl.textContent = message;
        statusEl.className = 'qa-status ' + (isError ? 'qa-error' : 'qa-success');
        statusEl.style.display = 'block';
        if (!isError) {
            setTimeout(function() { statusEl.style.display = 'none'; }, 3000);
        }
    }

    // Receipts (#419). One button for a photo or a file. When the server can
    // read receipts, a photo fills in the form the way the main app's scan
    // does; either way the file is attached once the transaction is saved,
    // as the upload needs its id.
    var submitBtn = form.querySelector('.qa-submit-btn');
    var receiptButton = document.getElementById('qa-receipt-btn');
    var receiptInput = document.getElementById('qa-receipt');
    var receiptChosen = document.getElementById('qa-receipt-chosen');
    var receiptThumb = document.getElementById('qa-receipt-thumb');
    var receiptName = document.getElementById('qa-receipt-name');
    var receiptNote = document.getElementById('qa-receipt-note');

    var receipt = null;
    var receiptMessage = '';
    var receiptMessageIsError = false;
    var thumbUrl = null;
    var reading = false;
    var saving = false;
    // Bumped whenever the receipt changes, so a reading that comes back after
    // the photo was removed or the form cleared fills nothing in.
    var readingRun = 0;

    // The file types this server can read, none when no OCR is set up.
    var readableTypes = [];
    var readableTypesLoaded = null;
    function loadReadableTypes() {
        if (!readableTypesLoaded) {
            readableTypesLoaded = fetch(OC.generateUrl('/apps/budget/api/receipts/ocr-status'), {
                headers: { 'requesttoken': OC.requestToken },
            })
                .then(function(response) { return response.ok ? response.json() : null; })
                .then(function(status) {
                    readableTypes = status && status.available ? (status.mimeTypes || []) : [];
                    renderReceipt();
                })
                .catch(function() {});
        }
        return readableTypesLoaded;
    }
    // Nextcloud's own scripts are modules, which run after this inline one,
    // so OC only exists once the document has loaded.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadReadableTypes);
    } else {
        loadReadableTypes();
    }

    // Receipts are filed in the account owner's Files, so the upload is
    // refused for a transaction in an account someone shared with you.
    function sharedAccountSelected() {
        var account = accountSelect.options[accountSelect.selectedIndex];
        return !!(account && account.dataset.owner);
    }

    function renderReceipt() {
        submitBtn.disabled = saving || reading;
        receiptButton.disabled = saving;
        receiptChosen.hidden = !receipt;
        receiptName.textContent = receipt ? receipt.name : '';

        var note = '';
        var isError = false;
        if (receipt && sharedAccountSelected()) {
            note = strings.ownAccountsOnly;
            isError = true;
        } else if (reading) {
            note = strings.reading;
        } else if (receipt) {
            note = receiptMessage;
            isError = receiptMessageIsError;
        } else if (readableTypes.length) {
            note = strings.scanHint;
        }
        receiptNote.textContent = note;
        receiptNote.classList.toggle('qa-receipt-note-error', isError);
        receiptNote.hidden = note === '';
    }

    function clearReceipt() {
        readingRun++;
        reading = false;
        receipt = null;
        receiptMessage = '';
        receiptMessageIsError = false;
        if (thumbUrl) {
            URL.revokeObjectURL(thumbUrl);
            thumbUrl = null;
        }
        receiptThumb.hidden = true;
        receiptThumb.removeAttribute('src');
        renderReceipt();
    }

    function chooseReceipt(file) {
        clearReceipt();
        receipt = file;
        receiptMessage = strings.attachedOnSave;
        if (file.type.indexOf('image/') === 0 && window.URL && URL.createObjectURL) {
            thumbUrl = URL.createObjectURL(file);
            receiptThumb.src = thumbUrl;
            receiptThumb.hidden = false;
        }
        renderReceipt();

        var run = readingRun;
        loadReadableTypes().then(function() {
            if (run !== readingRun || readableTypes.indexOf(file.type) === -1) {
                return;
            }
            readReceipt(file, run);
        });
    }

    function readReceipt(file, run) {
        reading = true;
        renderReceipt();

        var body = new FormData();
        body.append('image', file);
        fetch(OC.generateUrl('/apps/budget/api/receipts/extract'), {
            method: 'POST',
            headers: { 'requesttoken': OC.requestToken },
            body: body,
        })
            .then(function(response) {
                return response.json().catch(function() { return {}; }).then(function(draft) {
                    if (!response.ok) {
                        throw new Error(draft.error || strings.readFailed);
                    }
                    return draft;
                });
            })
            .then(function(draft) {
                if (run !== readingRun) {
                    return;
                }
                var filled = applyDraft(draft);
                // A line each (the note keeps line breaks), as the server's
                // messages don't all end in a full stop.
                receiptMessage = (filled.length ? strings.filledIn.replace('{fields}', filled.join(', ')) + '\n' : '')
                    + strings.attachedOnSave;
            })
            .catch(function(err) {
                if (run !== readingRun) {
                    return;
                }
                // The photo is still the receipt, so it stays attached.
                receiptMessage = (err.message || strings.readFailed) + '\n' + strings.attachedOnSave;
                receiptMessageIsError = true;
            })
            .finally(function() {
                if (run !== readingRun) {
                    return;
                }
                reading = false;
                renderReceipt();
            });
    }

    // The form is new, so everything read is filled in, as on the main
    // app's Add Transaction dialog. Values are only ever set, never parsed as
    // HTML: a receipt is whatever the photo says.
    function applyDraft(draft) {
        var filled = [];
        function fill(id, value, label) {
            if (value === null || value === undefined || value === '') {
                return;
            }
            document.getElementById(id).value = value;
            filled.push(label);
        }
        fill('qa-date', draft.date, strings.date);
        fill('qa-amount', draft.total, strings.amount);
        fill('qa-description', draft.merchant, strings.description);
        fill('qa-vendor', draft.merchant, strings.vendor);
        if (draft.suggestedCategoryId) {
            // Only a category the form offers for this type and account.
            var option = categorySelect.querySelector('option[value="' + Number(draft.suggestedCategoryId) + '"]');
            if (option && !option.disabled) {
                categorySelect.value = option.value;
                filled.push(strings.category);
            }
        }
        return filled;
    }

    // Resolves true once attached. Never rejects: the transaction is saved
    // by now, and failing the save would have it entered twice.
    function uploadReceipt(transactionId, file) {
        var body = new FormData();
        body.append('file', file);
        return fetch(OC.generateUrl('/apps/budget/api/transactions/' + transactionId + '/attachments/upload'), {
            method: 'POST',
            headers: { 'requesttoken': OC.requestToken },
            body: body,
        })
            .then(function(response) { return response.ok; })
            .catch(function() { return false; });
    }

    receiptButton.addEventListener('click', function() { receiptInput.click(); });
    receiptInput.addEventListener('change', function() {
        var file = receiptInput.files && receiptInput.files[0];
        // Cleared so choosing the same file again still counts as a change.
        receiptInput.value = '';
        if (file) {
            chooseReceipt(file);
        }
    });
    receiptThumb.addEventListener('error', function() { receiptThumb.hidden = true; });
    document.getElementById('qa-receipt-remove').addEventListener('click', clearReceipt);
    accountSelect.addEventListener('change', renderReceipt);
    form.addEventListener('reset', clearReceipt);

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (saving || reading) {
            return;
        }

        var data = {
            date: document.getElementById('qa-date').value,
            accountId: parseInt(document.getElementById('qa-account').value),
            type: typeSelect.value,
            amount: parseFloat(document.getElementById('qa-amount').value),
            description: document.getElementById('qa-description').value.trim(),
            vendor: document.getElementById('qa-vendor').value.trim() || null,
            categoryId: categorySelect.value ? parseInt(categorySelect.value) : null,
            notes: document.getElementById('qa-notes').value.trim() || null,
        };

        if (!data.accountId || !data.amount || !data.description) {
            showStatus('Please fill in all required fields', true);
            return;
        }

        var file = receipt;
        var canAttach = !sharedAccountSelected();
        saving = true;
        renderReceipt();
        submitBtn.textContent = strings.saving;

        fetch(OC.generateUrl('/apps/budget/api/transactions'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken,
            },
            body: JSON.stringify(data),
        })
        .then(function(response) {
            if (!response.ok) {
                return response.json().catch(function() { return {}; }).then(function(err) {
                    throw new Error(err.error || strings.saveFailed);
                });
            }
            return response.json();
        })
        .then(function(created) {
            if (!file) {
                return true;
            }
            return canAttach && created && created.id ? uploadReceipt(created.id, file) : false;
        })
        .then(function(receiptAttached) {
            // Saved either way, so the form is cleared either way: a second
            // Save would enter the transaction twice.
            if (receiptAttached) {
                showStatus(strings.saved, false);
            } else {
                showStatus(strings.receiptNotAttached, true);
            }
            form.reset();
            document.getElementById('qa-date').value = new Date().toISOString().split('T')[0];
            filterCategories();
        })
        .catch(function(err) {
            showStatus(err.message || strings.saveFailed, true);
        })
        .finally(function() {
            saving = false;
            renderReceipt();
            submitBtn.textContent = strings.save;
        });
    });
})();
</script>
