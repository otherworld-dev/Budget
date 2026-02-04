import './ActionBuilder.css';

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
					<select id="add-action-type" class="add-action-select">
						<option value="">+ Add Action</option>
						<option value="set_category">Set Category</option>
						<option value="set_vendor">Set Vendor</option>
						<option value="set_notes">Set Notes</option>
						<option value="add_tags">Add Tags</option>
						<option value="set_account">Set Account</option>
						<option value="set_type">Set Transaction Type</option>
						<option value="set_reference">Set Reference</option>
					</select>
					<label class="stop-processing-label">
						<input type="checkbox" id="stop-processing-check" ${this.stopProcessing ? 'checked' : ''}>
						<span>Stop processing after this rule</span>
						<small class="help-text">If checked, no rules with lower priority will run if this rule matches</small>
					</label>
				</div>
			</div>
		`;

		this.attachEventListeners();
	}

	renderActions() {
		if (this.actions.length === 0) {
			return '<p class="no-actions-message">No actions configured. Add actions using the dropdown below.</p>';
		}

		return this.actions.map((action, index) => this.renderAction(action, index)).join('');
	}

	renderAction(action, index) {
		const actionTypeLabels = {
			'set_category': 'Set Category',
			'set_vendor': 'Set Vendor',
			'set_notes': 'Set Notes',
			'add_tags': 'Add Tags',
			'set_account': 'Set Account',
			'set_type': 'Set Transaction Type',
			'set_reference': 'Set Reference'
		};

		const canMoveUp = index > 0;
		const canMoveDown = index < this.actions.length - 1;

		return `
			<div class="action-item" data-index="${index}">
				<div class="action-header">
					<span class="action-type-label">${actionTypeLabels[action.type] || action.type}</span>
					<div class="action-controls">
						<button class="btn-move-up" data-index="${index}" ${!canMoveUp ? 'disabled' : ''} title="Move up">↑</button>
						<button class="btn-move-down" data-index="${index}" ${!canMoveDown ? 'disabled' : ''} title="Move down">↓</button>
						<button class="btn-remove-action" data-index="${index}" title="Remove action">✕</button>
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
			default:
				return '<p class="error">Unknown action type</p>';
		}
	}

	renderCategoryAction(action, index) {
		const categories = this.options.categories || [];
		return `
			<div class="form-row">
				<label>Category:</label>
				<select class="action-value" data-index="${index}" data-field="value">
					<option value="">-- Select Category --</option>
					${categories.map(cat => `
						<option value="${cat.id}" ${action.value == cat.id ? 'selected' : ''}>${this.escapeHtml(cat.name)}</option>
					`).join('')}
				</select>
			</div>
			<div class="form-row">
				<label>Behavior:</label>
				<select class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="always" ${action.behavior === 'always' ? 'selected' : ''}>Always set</option>
					<option value="if_empty" ${action.behavior === 'if_empty' ? 'selected' : ''}>Only if empty</option>
				</select>
			</div>
		`;
	}

	renderVendorAction(action, index) {
		return `
			<div class="form-row">
				<label>Vendor Name:</label>
				<input type="text" class="action-value" data-index="${index}" data-field="value"
					value="${this.escapeHtml(action.value || '')}" placeholder="e.g., Amazon, Starbucks">
			</div>
			<div class="form-row">
				<label>Behavior:</label>
				<select class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="always" ${action.behavior === 'always' ? 'selected' : ''}>Always set</option>
					<option value="if_empty" ${action.behavior === 'if_empty' ? 'selected' : ''}>Only if empty</option>
				</select>
			</div>
		`;
	}

	renderNotesAction(action, index) {
		return `
			<div class="form-row">
				<label>Notes Text:</label>
				<textarea class="action-value" data-index="${index}" data-field="value" rows="2"
					placeholder="Text to add to transaction notes">${this.escapeHtml(action.value || '')}</textarea>
			</div>
			<div class="form-row">
				<label>Behavior:</label>
				<select class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="replace" ${action.behavior === 'replace' ? 'selected' : ''}>Replace notes</option>
					<option value="append" ${action.behavior === 'append' ? 'selected' : ''}>Append to notes</option>
				</select>
			</div>
			${action.behavior === 'append' ? `
			<div class="form-row">
				<label>Separator:</label>
				<input type="text" class="action-separator" data-index="${index}" data-field="separator"
					value="${this.escapeHtml(action.separator || ' | ')}" placeholder="e.g., | or -">
			</div>
			` : ''}
		`;
	}

	renderTagsAction(action, index) {
		const tagSets = this.options.tagSets || [];
		const selectedTagIds = Array.isArray(action.value) ? action.value : [];

		return `
			<div class="form-row">
				<label>Tags to Add:</label>
				<div class="tags-selection">
					${tagSets.length === 0 ? '<p class="no-tags-message">No tag sets available</p>' : ''}
					${tagSets.map(tagSet => `
						<fieldset class="tag-set-group">
							<legend>${this.escapeHtml(tagSet.name)}</legend>
							${(tagSet.tags || []).map(tag => `
								<label class="tag-checkbox">
									<input type="checkbox" class="tag-select" data-index="${index}"
										data-tag-id="${tag.id}" ${selectedTagIds.includes(tag.id) ? 'checked' : ''}>
									<span>${this.escapeHtml(tag.name)}</span>
								</label>
							`).join('')}
						</fieldset>
					`).join('')}
				</div>
			</div>
			<div class="form-row">
				<label>Behavior:</label>
				<select class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="merge" ${action.behavior === 'merge' ? 'selected' : ''}>Merge with existing tags</option>
					<option value="replace" ${action.behavior === 'replace' ? 'selected' : ''}>Replace all tags</option>
				</select>
			</div>
		`;
	}

	renderAccountAction(action, index) {
		const accounts = this.options.accounts || [];
		return `
			<div class="form-row">
				<label>Account:</label>
				<select class="action-value" data-index="${index}" data-field="value">
					<option value="">-- Select Account --</option>
					${accounts.map(account => `
						<option value="${account.id}" ${action.value == account.id ? 'selected' : ''}>${this.escapeHtml(account.name)}</option>
					`).join('')}
				</select>
			</div>
			<div class="form-row">
				<small class="help-text">This will reassign the transaction to a different account</small>
			</div>
		`;
	}

	renderTypeAction(action, index) {
		return `
			<div class="form-row">
				<label>Transaction Type:</label>
				<div class="type-radios">
					<label class="type-radio">
						<input type="radio" name="action-type-${index}" class="action-value" data-index="${index}"
							data-field="value" value="expense" ${action.value === 'expense' ? 'checked' : ''}>
						<span>Expense</span>
					</label>
					<label class="type-radio">
						<input type="radio" name="action-type-${index}" class="action-value" data-index="${index}"
							data-field="value" value="income" ${action.value === 'income' ? 'checked' : ''}>
						<span>Income</span>
					</label>
				</div>
			</div>
		`;
	}

	renderReferenceAction(action, index) {
		return `
			<div class="form-row">
				<label>Reference Value:</label>
				<input type="text" class="action-value" data-index="${index}" data-field="value"
					value="${this.escapeHtml(action.value || '')}" placeholder="e.g., CHECK-1234, AUTO">
			</div>
			<div class="form-row">
				<label>Behavior:</label>
				<select class="action-behavior" data-index="${index}" data-field="behavior">
					<option value="always" ${action.behavior === 'always' ? 'selected' : ''}>Always set</option>
					<option value="if_empty" ${action.behavior === 'if_empty' ? 'selected' : ''}>Only if empty</option>
				</select>
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
				e.target.classList.contains('action-separator')) {
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
				e.target.classList.contains('action-separator')) {
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
			default:
				return '';
		}
	}

	getDefaultBehaviorForType(type) {
		switch (type) {
			case 'set_notes':
				return 'replace';
			case 'add_tags':
				return 'merge';
			case 'set_category':
			case 'set_vendor':
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
				errors.push(`Action ${index + 1}: Missing type`);
			}

			// Validate value based on type
			switch (action.type) {
				case 'set_category':
					if (!action.value) {
						errors.push(`Action ${index + 1}: Category not selected`);
					}
					break;
				case 'set_vendor':
					if (!action.value || action.value.trim() === '') {
						errors.push(`Action ${index + 1}: Vendor name is empty`);
					}
					break;
				case 'set_notes':
					if (!action.value || action.value.trim() === '') {
						errors.push(`Action ${index + 1}: Notes text is empty`);
					}
					break;
				case 'add_tags':
					if (!Array.isArray(action.value) || action.value.length === 0) {
						errors.push(`Action ${index + 1}: No tags selected`);
					}
					break;
				case 'set_account':
					if (!action.value) {
						errors.push(`Action ${index + 1}: Account not selected`);
					}
					break;
				case 'set_type':
					if (!action.value || !['income', 'expense'].includes(action.value)) {
						errors.push(`Action ${index + 1}: Invalid transaction type`);
					}
					break;
				case 'set_reference':
					if (!action.value || action.value.trim() === '') {
						errors.push(`Action ${index + 1}: Reference value is empty`);
					}
					break;
			}
		});

		return {
			valid: errors.length === 0,
			errors: errors
		};
	}

	escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}
}
