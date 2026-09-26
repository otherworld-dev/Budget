import './ActionBuilder.css';
import { buildCategoryOptionsHtml, escapeHtml } from '../../../utils/dom.js';
import { offerableTagSets } from '../../../utils/tags.js';
import { pickableAccounts, accountOptionLabel } from '../../../utils/accounts.js';
import { translate as t } from '@nextcloud/l10n';

/**
 * ActionBuilder - Visual configuration for rule actions
 *
 * Supports:
 * - Multiple action types (category, vendor, notes, tags, account, type, reference)
 * - Behavior settings (always, if_empty, append, merge, replace)
 * - Priority ordering with up/down buttons
 * - Stop processing control
 */
export class ActionBuilder {
	constructor(containerEl, initialActions = null, options = {}) {
		this.container = containerEl;
		this.options = {
			categories: options.categories || [],
			accounts: options.accounts || [],
			tagSets: options.tagSets || [],
			...options
		};

		// Parse initial actions
		if (initialActions && typeof initialActions === 'object') {
			if (initialActions.version === 2) {
				// v2 format
				this.actions = initialActions.actions || [];
				this.stopProcessing = initialActions.stopProcessing !== false;
			} else if (Array.isArray(initialActions.actions)) {
				// v2 without version field
				this.actions = initialActions.actions;
				this.stopProcessing = initialActions.stopProcessing !== false;
			} else {
				// Legacy v1 format - convert
				this.actions = this.convertLegacyActions(initialActions);
				this.stopProcessing = true;
			}
		} else {
			this.actions = [];
			this.stopProcessing = true;
		}

		this.render();
	}

	convertLegacyActions(legacyActions) {
		const actions = [];

		if (legacyActions.categoryId) {
			actions.push({
				type: 'set_category',
				value: legacyActions.categoryId,
				behavior: 'always',
				priority: 100
			});
		}

		if (legacyActions.vendor) {
			actions.push({
				type: 'set_vendor',
				value: legacyActions.vendor,
				behavior: 'always',
				priority: 90
			});
		}

		if (legacyActions.notes) {
			actions.push({
				type: 'set_notes',
				value: legacyActions.notes,
				behavior: 'always',
				priority: 80
			});
		}

		return actions;
	}

	render() {
		this.container.innerHTML = `
			<div class="action-builder">
				<div class="actions-list" id="actions-list">
					${this.renderActions()}
				</div>
				<div class="actions-controls">
					<select id="add-action-type" class="add-action-select" aria-label="${t('budget', '+ Add Action')}">
						<option value="">${t('budget', '+ Add Action')}</option>
						<option value="set_category">${t('budget', 'Set Category')}</option>
						<option value="set_vendor">${t('budget', 'Set Vendor')}</option>
						<option value="set_description">${t('budget', 'Set Description')}</option>
						<option value="set_notes">${t('budget', 'Set Notes')}</option>
						<option value="add_tags">${t('budget', 'Add Tags')}</option>
						<option value="set_account">${t('budget', 'Set Account')}</option>
						<option value="set_type">${t('budget', 'Set Transaction Type')}</option>
						<option value="set_reference">${t('budget', 'Set Reference')}</option>
						<option value="regex_replace">${t('budget', 'Regex Replace')}</option>
						<option value="change_case">${t('budget', 'Change Case')}</option>
						<option value="replace_text">${t('budget', 'Replace Text')}</option>
						<option value="set_forecast_exclude">${t('budget', 'Exclude from Forecast')}</option>
						<option value="link_transfer">${t('budget', 'Auto-Link as Transfer')}</option>
					</select>
					<label class="stop-processing-label">
						<input type="checkbox" id="stop-processing-check" ${this.stopProcessing ? 'checked' : ''}>
						<span>${t('budget', 'Stop processing after this rule')}</span>
						<small class="help-text">${t('budget', 'If checked, no rules with lower priority will run if this rule matches')}</small>
					</label>
				</div>
			</div>
		`;

		this.attachEventListeners();
	}

	renderActions() {
		if (this.actions.length === 0) {
			return `<p class="no-actions-message">${t('budget', 'No actions yet. Use the dropdown below to add actions.')}</p>`;
		}

		return this.actions.map((action, index) => this.renderAction(action, index)).join('');
	}

	renderAction(action, index) {
		const actionTypeLabels = {
			'set_category': t('budget', 'Set Category'),
			'set_vendor': t('budget', 'Set Vendor'),
			'set_description': t('budget', 'Set Description'),
			'set_notes': t('budget', 'Set Notes'),
			'add_tags': t('budget', 'Add Tags'),
			'set_account': t('budget', 'Set Account'),
			'set_type': t('budget', 'Set Transaction Type'),
			'set_reference': t('budget', 'Set Reference'),
			'regex_replace': t('budget', 'Regex Replace'),
			'change_case': t('budget', 'Change Case'),
			'replace_text': t('budget', 'Replace Text'),
			'set_forecast_exclude': t('budget', 'Exclude from Forecast'),
			'link_transfer': t('budget', 'Auto-Link as Transfer')
		};

		const canMoveUp = index > 0;
		const canMoveDown = index < this.actions.length - 1;

		return `
			<div class="action-item" data-index="${index}">
				<div class="action-header">
					<span class="action-type-label">${actionTypeLabels[action.type] || action.type}</span>
					<div class="action-controls">
						<button class="btn-move-up" data-index="${index}" ${!canMoveUp ? 'disabled' : ''} title="${t('budget', 'Move up')}" aria-label="${t('budget', 'Move up')}">↑</button>
						<button class="btn-move-down" data-index="${index}" ${!canMoveDown ? 'disabled' : ''} title="${t('budget', 'Move down')}" aria-label="${t('budget', 'Move down')}">↓</button>
						<button class="btn-remove-action" data-index="${index}" title="${t('budget', 'Remove action')}" aria-label="${t('budget', 'Remove action')}">✕</button>
					</div>
				</div>
				<div class="action-config">
					${this.renderActionConfig(action, index)}
				</div>
			</div>
		`;
	}

	renderActionConfig(action, index) {
		switch (action.type) {
			case 'set_category':
				return this.renderCategoryAction(action, index);
			case 'set_vendor':
				return this.renderVendorAction(action, index);
			case 'set_description':
				return this.renderDescriptionAction(action, index);
			case 'set_notes':
				return this.renderNotesAction(action, index);
			case 'add_tags':
				return this.renderTagsAction(action, index);
			case 'set_account':
				return this.renderAccountAction(action, index);
			case 'set_type':
				return this.renderTypeAction(action, index);
			case 'set_reference':
				return this.renderReferenceAction(action, index);
			case 'regex_replace':
				return this.renderRegexReplaceAction(action, index);
			case 'change_case':
				return this.renderChangeCaseAction(action, index);
			case 'replace_text':
				return this.renderReplaceTextAction(action, index);
			case 'set_forecast_exclude':
				return this.renderForecastExcludeAction(action, index);
			case 'link_transfer':
				return this.renderLinkTransferAction(action, index);
			default:
				return `<p class="error">${t('budget', 'Unknown action type')}</p>`;
		}
	}

	renderRegexReplaceAction(action, index) {
		return `
			<div>
				<div class="form-row regex-row">
					<select aria-label="${t('budget', 'Field')}" class="action-field" data-index="${index}" data-field="field">
						<option value="description" ${action.field === 'description' ? 'selected' : ''}>${t('budget', 'Description')}</option>
						<option value="vendor" ${action.field === 'vendor' ? 'selected' : ''}>${t('budget', 'Vendor')}</option>
						<option value="reference" ${action.field === 'reference' ? 'selected' : ''}>${t('budget', 'Reference')}</option>
						<option value="notes" ${action.field === 'notes' ? 'selected' : ''}>${t('budget', 'Notes')}</option>
					</select>
					→
					<select aria-label="${t('budget', 'Target')}" class="action-target" data-index="${index}" data-field="target">
						<option value="description" ${action.target === 'description' ? 'selected' : ''}>${t('budget', 'Description')}</option>
						<option value="vendor" ${action.target === 'vendor' ? 'selected' : ''}>${t('budget', 'Vendor')}</option>
						<option value="amount" ${action.target === 'amount' ? 'selected' : ''}>${t('budget', 'Amount')}</option>
						<option value="reference" ${action.target === 'reference' ? 'selected' : ''}>${t('budget', 'Reference')}</option>
						<option value="notes" ${action.target === 'notes' ? 'selected' : ''}>${t('budget', 'Notes')}</option>
						<option value="date" ${action.target === 'date' ? 'selected' : ''}>${t('budget', 'Date')}</option>
					</select>
					<select aria-label="${t('budget', 'Behavior')}" class="action-behavior" data-index="${index}" data-field="behavior">
						<option value="always" ${action.behavior === 'always' ? 'selected' : ''}>${t('budget', 'Always set')}</option>
						<option value="if_empty" ${action.behavior === 'if_empty' ? 'selected' : ''}>${t('budget', 'Only if empty')}</option>
					</select>
				</div>
				<div class="form-row regex-row">
					<input aria-label="${t('budget', 'Pattern')}" type="text" class="action-pattern" data-index="${index}" data-field="pattern"
						value="${escapeHtml(action.pattern || '')}" placeholder="${t('budget', 'Pattern: /\\d+/')}">
					<input aria-label="${t('budget', 'Replacement')}" type="text" class="action-replacement" data-index="${index}" data-field="replacement"
						value="${escapeHtml(action.replacement || '')}" placeholder="${t('budget', 'Replacement: e.g., X or $1')}">
				</div>
			</div>
		`;
	}

	renderChangeCaseAction(action, index) {
		return `
			<div class="form-row">
				<label>${t('budget', 'Field:')}</label>
				<select aria-label="${t('budget', 'Field:')}" class="action-field" data-index="${index}" data-field="field">
					<option value="description" ${action.field === 'description' ? 'selected' : ''}>${t('budget', 'Description')}</option>
					<option value="vendor" ${action.field === 'vendor' ? 'selected' : ''}>${t('budget', 'Vendor')}</option>
					<option value="reference" ${action.field === 'reference' ? 'selected' : ''}>${t('budget', 'Reference')}</option>
					<option value="notes" ${action.field === 'notes' ? 'selected' : ''}>${t('budget', 'Notes')}</option>
				</select>
			</div>
			<div class="form-row">
				<label>${t('budget', 'Mode:')}</label>
				<select aria-label="${t('budget', 'Mode:')}" class="action-mode" data-index="${index}" data-field="mode">
					<option value="upper" ${action.mode === 'upper' ? 'selected' : ''}>${t('budget', 'Uppercase')}</option>
					<option value="lower" ${action.mode === 'lower' ? 'selected' : ''}>${t('budget', 'Lowercase')}</option>
					<option value="title" ${action.mode === 'title' ? 'selected' : ''}>${t('budget', 'Title Case')}</option>
					<option value="sentence" ${action.mode === 'sentence' ? 'selected' : ''}>${t('budget', 'Sentence case')}</option>
				</select>
			</div>
		`;
	}

	renderReplaceTextAction(action, index) {
		return `
			<div>
				<div class="transform-row form-row">
					<label>${t('budget', 'Field:')}</label>
					<select aria-label="${t('budget', 'Field:')}" class="action-field" data-index="${index}" data-field="field">
						<option value="description" ${action.field === 'description' ? 'selected' : ''}>${t('budget', 'Description')}</option>
						<option value="vendor" ${action.field === 'vendor' ? 'selected' : ''}>${t('budget', 'Vendor')}</option>
						<option value="reference" ${action.field === 'reference' ? 'selected' : ''}>${t('budget', 'Reference')}</option>
						<option value="notes" ${action.field === 'notes' ? 'selected' : ''}>${t('budget', 'Notes')}</option>
					</select>
					<label>${t('budget', 'Find:')}</label>
					<input aria-label="${t('budget', 'Find:')}" type="text" class="action-find" data-index="${index}" data-field="find"
						value="${escapeHtml(action.find || '')}" placeholder="${t('budget', 'e.g., ACME')}">
				</div>
				<div class="transform-row form-row">
					<label>${t('budget', 'Replace:')}</label>
					<input aria-label="${t('budget', 'Replace:')}" type="text" class="action-replace" data-index="${index}" data-field="replace"
						value="${escapeHtml(action.replace || '')}" placeholder="${t('budget', 'e.g., Amazon')}">
				</div>
			</div>
		`;
	}

	renderCategoryAction(action, index) {
		const categoryTree = this.options.categoryTree || this.options.categories || [];
		return `
			<div class="form-row">
				<label>${t('budget', 'Category:')}</label>
				<select aria-label="${t('budget', 'Category:')}" class="action-value" data-index="${index}" data-field="value">
					<option value="">${t('budget', '-- Select Category --')}</option>
					${buildCategoryOptionsHtml(categoryTree, { selectedId: action.value })}
				</select>
			</div>
			<div class="form-row">
				<label>${t('budget', 'Behavior:')}</label>
				<select aria-label="${t('budget', 'Behavior:')}" class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="always" ${action.behavior === 'always' ? 'selected' : ''}>${t('budget', 'Always set')}</option>
					<option value="if_empty" ${action.behavior === 'if_empty' ? 'selected' : ''}>${t('budget', 'Only if empty')}</option>
				</select>
			</div>
		`;
	}

	renderVendorAction(action, index) {
		return `
			<div class="form-row">
				<label>${t('budget', 'Vendor Name:')}</label>
				<input aria-label="${t('budget', 'Vendor Name:')}" type="text" class="action-value" data-index="${index}" data-field="value"
					value="${escapeHtml(action.value || '')}" placeholder="${t('budget', 'e.g., Amazon, Starbucks')}">
			</div>
			<div class="form-row">
				<label>${t('budget', 'Behavior:')}</label>
				<select aria-label="${t('budget', 'Behavior:')}" class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="always" ${action.behavior === 'always' ? 'selected' : ''}>${t('budget', 'Always set')}</option>
					<option value="if_empty" ${action.behavior === 'if_empty' ? 'selected' : ''}>${t('budget', 'Only if empty')}</option>
				</select>
			</div>
		`;
	}

	renderDescriptionAction(action, index) {
		return `
			<div class="form-row">
				<label>${t('budget', 'Description:')}</label>
				<input aria-label="${t('budget', 'Description:')}" type="text" class="action-value" data-index="${index}" data-field="value"
					value="${escapeHtml(action.value || '')}" placeholder="${t('budget', 'e.g., Grocery purchase, Salary')}">
			</div>
			<div class="form-row">
				<label>${t('budget', 'Behavior:')}</label>
				<select aria-label="${t('budget', 'Behavior:')}" class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="always" ${action.behavior === 'always' ? 'selected' : ''}>${t('budget', 'Always set')}</option>
					<option value="if_empty" ${action.behavior === 'if_empty' ? 'selected' : ''}>${t('budget', 'Only if empty')}</option>
				</select>
			</div>
		`;
	}

	renderNotesAction(action, index) {
		return `
			<div class="form-row">
				<label>${t('budget', 'Notes Text:')}</label>
				<textarea aria-label="${t('budget', 'Notes Text:')}" class="action-value" data-index="${index}" data-field="value" rows="2"
					placeholder="${t('budget', 'Text to add to transaction notes')}">${escapeHtml(action.value || '')}</textarea>
			</div>
			<div class="form-row">
				<label>${t('budget', 'Behavior:')}</label>
				<select aria-label="${t('budget', 'Behavior:')}" class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="replace" ${action.behavior === 'replace' ? 'selected' : ''}>${t('budget', 'Replace notes')}</option>
					<option value="append" ${action.behavior === 'append' ? 'selected' : ''}>${t('budget', 'Append to notes')}</option>
				</select>
			</div>
			${action.behavior === 'append' ? `
			<div class="form-row">
				<label>${t('budget', 'Separator:')}</label>
				<input aria-label="${t('budget', 'Separator:')}" type="text" class="action-separator" data-index="${index}" data-field="separator"
					value="${escapeHtml(action.separator || ' | ')}" placeholder="${t('budget', 'e.g., | or -')}">
			</div>
			` : ''}
		`;
	}

	renderTagsAction(action, index) {
		const selectedTagIds = Array.isArray(action.value) ? action.value : [];
		// Hidden tags are not offered, except ones this action already adds (#373)
		const tagSets = offerableTagSets(this.options.tagSets || [], selectedTagIds);

		return `
			<div class="form-row">
				<label>${t('budget', 'Tags to Add:')}</label>
				<div class="tags-selection">
					${tagSets.length === 0 ? `<p class="no-tags-message">${t('budget', 'No tag sets available')}</p>` : ''}
					${tagSets.map(tagSet => `
						<fieldset class="tag-set-group">
							<legend>${escapeHtml(tagSet.name)}</legend>
							${(tagSet.tags || []).map(tag => `
								<label class="tag-checkbox">
									<input type="checkbox" class="tag-select" data-index="${index}"
										data-tag-id="${tag.id}" ${selectedTagIds.includes(tag.id) ? 'checked' : ''}>
									<span>${escapeHtml(tag.name)}</span>
								</label>
							`).join('')}
						</fieldset>
					`).join('')}
				</div>
			</div>
			<div class="form-row">
				<label>${t('budget', 'Behavior:')}</label>
				<select aria-label="${t('budget', 'Behavior:')}" class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="merge" ${action.behavior === 'merge' ? 'selected' : ''}>${t('budget', 'Merge with existing tags')}</option>
					<option value="replace" ${action.behavior === 'replace' ? 'selected' : ''}>${t('budget', 'Replace all tags')}</option>
				</select>
			</div>
		`;
	}

	renderAccountAction(action, index) {
		// A rule routes NEW transactions, so closed accounts are not offered (#372)
		const accounts = pickableAccounts(this.options.accounts, action.value);
		return `
			<div class="form-row">
				<label>${t('budget', 'Account:')}</label>
				<select aria-label="${t('budget', 'Account:')}" class="action-value" data-index="${index}" data-field="value">
					<option value="">${t('budget', '-- Select Account --')}</option>
					${accounts.map(account => `
						<option value="${account.id}" ${action.value == account.id ? 'selected' : ''}>${escapeHtml(accountOptionLabel(account))}</option>
					`).join('')}
				</select>
			</div>
			<div class="form-row">
				<small class="help-text">${t('budget', 'This will reassign the transaction to a different account')}</small>
			</div>
		`;
	}

	renderTypeAction(action, index) {
		return `
			<div class="form-row">
				<label>${t('budget', 'Transaction Type:')}</label>
				<div class="type-radios">
					<label class="type-radio">
						<input type="radio" name="action-type-${index}" class="action-value" data-index="${index}"
							data-field="value" value="expense" ${action.value === 'expense' ? 'checked' : ''}>
						<span>${t('budget', 'Expense')}</span>
					</label>
					<label class="type-radio">
						<input type="radio" name="action-type-${index}" class="action-value" data-index="${index}"
							data-field="value" value="income" ${action.value === 'income' ? 'checked' : ''}>
						<span>${t('budget', 'Income')}</span>
					</label>
				</div>
			</div>
		`;
	}

	renderReferenceAction(action, index) {
		return `
			<div class="form-row">
				<label>${t('budget', 'Reference Value:')}</label>
				<input aria-label="${t('budget', 'Reference Value:')}" type="text" class="action-value" data-index="${index}" data-field="value"
					value="${escapeHtml(action.value || '')}" placeholder="${t('budget', 'e.g., CHECK-1234, AUTO')}">
			</div>
			<div class="form-row">
				<label>${t('budget', 'Behavior:')}</label>
				<select aria-label="${t('budget', 'Behavior:')}" class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="always" ${action.behavior === 'always' ? 'selected' : ''}>${t('budget', 'Always set')}</option>
					<option value="if_empty" ${action.behavior === 'if_empty' ? 'selected' : ''}>${t('budget', 'Only if empty')}</option>
				</select>
			</div>
		`;
	}

	renderForecastExcludeAction(action, index) {
		const excluded = !(action.value === false || action.value === 'false');
		return `
			<div class="form-row">
				<label>${t('budget', 'Forecast:')}</label>
				<select aria-label="${t('budget', 'Forecast:')}" class="action-value" data-index="${index}" data-field="value">
					<option value="true" ${excluded ? 'selected' : ''}>${t('budget', 'Exclude from forecast')}</option>
					<option value="false" ${!excluded ? 'selected' : ''}>${t('budget', 'Include in forecast')}</option>
				</select>
			</div>
			<div class="form-row">
				<small class="help-text">${t('budget', 'Extraordinary one-time amounts are left out of the forecast averages (they still affect your real balance).')}</small>
			</div>
		`;
	}

	renderLinkTransferAction() {
		return `
			<div class="form-row">
				<p class="action-description">${t('budget', 'After import, automatically find and link a matching transaction from another account as a transfer pair. Matches by amount, opposite type, and date proximity (within 3 days).')}</p>
			</div>
		`;
	}

	attachEventListeners() {
		// Add action dropdown
		const addSelect = document.getElementById('add-action-type');
		if (addSelect) {
			addSelect.addEventListener('change', (e) => {
				if (e.target.value) {
					this.addAction(e.target.value);
					e.target.value = '';
				}
			});
		}

		// Stop processing checkbox
		const stopCheck = document.getElementById('stop-processing-check');
		if (stopCheck) {
			stopCheck.addEventListener('change', (e) => {
				this.stopProcessing = e.target.checked;
			});
		}

		// Delegate events for action items
		this.container.addEventListener('click', (e) => {
			const removeBtn = e.target.closest('.btn-remove-action');
			const moveUpBtn = e.target.closest('.btn-move-up');
			const moveDownBtn = e.target.closest('.btn-move-down');

			if (removeBtn) {
				const index = parseInt(removeBtn.dataset.index);
				this.removeAction(index);
			} else if (moveUpBtn) {
				const index = parseInt(moveUpBtn.dataset.index);
				this.moveAction(index, -1);
			} else if (moveDownBtn) {
				const index = parseInt(moveDownBtn.dataset.index);
				this.moveAction(index, 1);
			}
		});

		// Delegate change events for inputs
		this.container.addEventListener('change', (e) => {
			if (e.target.classList.contains('action-value') ||
				e.target.classList.contains('action-behavior') ||
				e.target.classList.contains('action-separator') ||
				e.target.classList.contains('action-field') ||
				e.target.classList.contains('action-target') ||
				e.target.classList.contains('action-pattern') ||
				e.target.classList.contains('action-replacement') ||
				e.target.classList.contains('action-mode') ||
				e.target.classList.contains('action-find') ||
				e.target.classList.contains('action-replace')) {
				const index = parseInt(e.target.dataset.index);
				const field = e.target.dataset.field;
				this.updateActionField(index, field, e.target.value);
			} else if (e.target.classList.contains('tag-select')) {
				const index = parseInt(e.target.dataset.index);
				this.updateTagSelection(index);
			}
		});

		// Delegate input events for text fields
		this.container.addEventListener('input', (e) => {
			if (e.target.classList.contains('action-value') ||
				e.target.classList.contains('action-separator') ||
				e.target.classList.contains('action-pattern') ||
				e.target.classList.contains('action-replacement') ||
				e.target.classList.contains('action-find') ||
				e.target.classList.contains('action-replace')) {
				const index = parseInt(e.target.dataset.index);
				const field = e.target.dataset.field;
				this.updateActionField(index, field, e.target.value);
			}
		});
	}

	addAction(type) {
		const newAction = {
			type: type,
			value: this.getDefaultValueForType(type),
			behavior: this.getDefaultBehaviorForType(type),
			priority: 50
		};

		if (type === 'regex_replace') {
			newAction.field = 'description';
			newAction.target = 'description';
			newAction.pattern = '';
			newAction.replacement = '';
		} else if (type === 'change_case') {
			newAction.field = 'description';
			newAction.mode = 'upper';
		} else if (type === 'replace_text') {
			newAction.field = 'description';
			newAction.find = '';
			newAction.replace = '';
		}

		this.actions.push(newAction);
		this.render();
	}

	getDefaultValueForType(type) {
		switch (type) {
			case 'set_category':
			case 'set_account':
				return null;
			case 'add_tags':
				return [];
			case 'set_type':
				return 'expense';
			case 'set_forecast_exclude':
				return 'true';
			default:
				return '';
		}
	}

	getDefaultBehaviorForType(type) {
		switch (type) {
			case 'set_notes':
				return 'replace';
			case 'regex_replace':
			case 'change_case':
			case 'replace_text':
				return 'always';
			case 'add_tags':
				return 'merge';
			case 'set_category':
			case 'set_vendor':
			case 'set_description':
			case 'set_reference':
			case 'set_account':
			case 'set_type':
			default:
				return 'always';
		}
	}

	removeAction(index) {
		this.actions.splice(index, 1);
		this.render();
	}

	moveAction(index, direction) {
		const newIndex = index + direction;
		if (newIndex < 0 || newIndex >= this.actions.length) return;

		[this.actions[index], this.actions[newIndex]] = [this.actions[newIndex], this.actions[index]];
		this.render();
	}

	updateActionField(index, field, value) {
		if (!this.actions[index]) return;

		this.actions[index][field] = value;

		// Re-render to update dependent UI (e.g., separator field for append behavior)
		if (field === 'behavior') {
			this.render();
		}
	}

	updateTagSelection(index) {
		if (!this.actions[index]) return;

		const checkboxes = this.container.querySelectorAll(`.tag-select[data-index="${index}"]:checked`);
		const tagIds = Array.from(checkboxes).map(cb => parseInt(cb.dataset.tagId));

		this.actions[index].value = tagIds;
	}

	getActions() {
		return {
			version: 2,
			stopProcessing: this.stopProcessing,
			actions: this.actions
		};
	}

	validate() {
		const errors = [];

		this.actions.forEach((action, index) => {
			if (!action.type) {
				errors.push(t('budget', 'Action {number}: Missing type', { number: index + 1 }));
			}

			// Validate value based on type
			switch (action.type) {
				case 'set_category':
					if (!action.value) {
						errors.push(t('budget', 'Action {number}: Category not selected', { number: index + 1 }));
					}
					break;
				case 'set_vendor':
					if (!action.value || action.value.trim() === '') {
						errors.push(t('budget', 'Action {number}: Vendor name is empty', { number: index + 1 }));
					}
					break;
				case 'set_description':
					if (!action.value || action.value.trim() === '') {
						errors.push(t('budget', 'Action {number}: Description is empty', { number: index + 1 }));
					}
					break;
				case 'set_notes':
					if (!action.value || action.value.trim() === '') {
						errors.push(t('budget', 'Action {number}: Notes text is empty', { number: index + 1 }));
					}
					break;
				case 'add_tags':
					if (!Array.isArray(action.value) || action.value.length === 0) {
						errors.push(t('budget', 'Action {number}: No tags selected', { number: index + 1 }));
					}
					break;
				case 'set_account':
					if (!action.value) {
						errors.push(t('budget', 'Action {number}: Account not selected', { number: index + 1 }));
					}
					break;
				case 'set_type':
					if (!action.value || !['income', 'expense'].includes(action.value)) {
						errors.push(t('budget', 'Action {number}: Invalid transaction type', { number: index + 1 }));
					}
					break;
				case 'set_reference':
					if (!action.value || action.value.trim() === '') {
						errors.push(t('budget', 'Action {number}: Reference value is empty', { number: index + 1 }));
					}
					break;
				case 'regex_replace':
					if (!action.field || !['description', 'vendor', 'reference', 'notes'].includes(action.field)) {
						errors.push(t('budget', 'Action {number}: Regex field is invalid', { number: index + 1 }));
					}
					if (action.target && !['description', 'vendor', 'amount', 'reference', 'notes', 'date'].includes(action.target)) {
						errors.push(t('budget', 'Action {number}: Regex target field is invalid', { number: index + 1 }));
					}
					if (!action.pattern || action.pattern.trim() === '') {
						errors.push(t('budget', 'Action {number}: Regex pattern is empty', { number: index + 1 }));
					}
					break;
				case 'change_case':
					if (!action.field || !['description', 'vendor', 'reference', 'notes'].includes(action.field)) {
						errors.push(t('budget', 'Action {number}: Text field is invalid', { number: index + 1 }));
					}
					if (!action.mode || !['upper', 'lower', 'title', 'sentence'].includes(action.mode)) {
						errors.push(t('budget', 'Action {number}: Case mode is invalid', { number: index + 1 }));
					}
					break;
				case 'replace_text':
					if (!action.field || !['description', 'vendor', 'reference', 'notes'].includes(action.field)) {
						errors.push(t('budget', 'Action {number}: Text field is invalid', { number: index + 1 }));
					}
					if (!action.find || action.find.trim() === '') {
						errors.push(t('budget', 'Action {number}: Replace text target is empty', { number: index + 1 }));
					}
					break;
			}
		});

		return {
			valid: errors.length === 0,
			errors: errors
		};
	}

}
