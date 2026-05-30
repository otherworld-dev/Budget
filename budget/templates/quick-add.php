<?php
style('budget', 'style');
$accounts = json_decode($_['accounts'], true);
$categories = json_decode($_['categories'], true);
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
            <label for="qa-date"><?php p($l->t('Date')); ?></label>
            <input type="date" id="qa-date" required value="<?php echo date('Y-m-d'); ?>">
        </div>

        <div class="qa-form-group">
            <label for="qa-account"><?php p($l->t('Account')); ?></label>
            <select id="qa-account" required>
                <option value=""><?php p($l->t('Select account...')); ?></option>
                <?php foreach ($accounts as $account): ?>
                    <option value="<?php p($account['id']); ?>"><?php p($account['name']); ?></option>
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
                    <option value="<?php p($cat['id']); ?>" data-type="<?php p($cat['type']); ?>"><?php p($cat['name']); ?></option>
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

    var form = document.getElementById('quick-add-form');
    var statusEl = document.getElementById('qa-status');
    var typeSelect = document.getElementById('qa-type');
    var categorySelect = document.getElementById('qa-category');

    // Filter categories by type
    function filterCategories() {
        var type = typeSelect.value;
        var catType = type === 'credit' ? 'income' : 'expense';
        var options = categorySelect.querySelectorAll('option[data-type]');
        options.forEach(function(opt) {
            opt.style.display = opt.dataset.type === catType ? '' : 'none';
        });
        // Reset selection if current is hidden
        var selected = categorySelect.options[categorySelect.selectedIndex];
        if (selected && selected.dataset.type && selected.dataset.type !== catType) {
            categorySelect.value = '';
        }
    }

    typeSelect.addEventListener('change', filterCategories);
    filterCategories();

    function showStatus(message, isError) {
        statusEl.textContent = message;
        statusEl.className = 'qa-status ' + (isError ? 'qa-error' : 'qa-success');
        statusEl.style.display = 'block';
        if (!isError) {
            setTimeout(function() { statusEl.style.display = 'none'; }, 3000);
        }
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();

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

        var submitBtn = form.querySelector('.qa-submit-btn');
        submitBtn.disabled = true;
        submitBtn.textContent = '<?php p($l->t('Saving...')); ?>';

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
                return response.json().then(function(err) {
                    throw new Error(err.error || 'Failed to save');
                });
            }
            return response.json();
        })
        .then(function() {
            showStatus('<?php p($l->t('Transaction saved!')); ?>', false);
            form.reset();
            document.getElementById('qa-date').value = new Date().toISOString().split('T')[0];
            filterCategories();
        })
        .catch(function(err) {
            showStatus(err.message || '<?php p($l->t('Failed to save transaction')); ?>', true);
        })
        .finally(function() {
            submitBtn.disabled = false;
            submitBtn.textContent = '<?php p($l->t('Save Transaction')); ?>';
        });
    });
})();
</script>
