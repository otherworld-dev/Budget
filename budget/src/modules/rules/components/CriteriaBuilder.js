import './CriteriaBuilder.css';

/**
 * CriteriaBuilder - Visual query builder for complex boolean expression trees
 *
 * Supports:
 * - Nested groups with AND/OR operators
 * - Individual conditions with field, matchType, pattern
 * - NOT operator for negation
 * - Dynamic match types based on field type
 * - Path-based node addressing
 */
export class CriteriaBuilder {
	constructor(containerEl, initialCriteria = null) {
		this.container = containerEl;
		this.criteria = this.normalizeCriteria(initialCriteria);
		this.render();
	}

	/**
	 * Normalize criteria to ensure root is always a group
	 * Fixes legacy migrations where root was a single condition
	 */
	normalizeCriteria(criteria) {
		if (!criteria) {
			return this.createEmptyRoot();
		}

		// If root is a single condition (legacy migration bug), wrap it in a group
		if (criteria.root && criteria.root.type === 'condition') {
			return {
				version: 2,
				root: {
					operator: 'AND',
					conditions: [criteria.root]
				}
			};
		}

		// Already normalized
		return criteria;
	}

	createEmptyRoot() {
		return {
			version: 2,
			root: {
				operator: 'AND',
				conditions: [
					{
						type: 'condition',
						field: 'description',
						matchType: 'contains',
						pattern: '',
						negate: false
					}
				]
			}
		};
	}

	render() {
		this.container.innerHTML = this.renderNode(this.criteria.root, []);
		this.attachEventListeners();
	}

	renderNode(node, path) {
		if (node.operator) {
			return this.renderGroup(node, path);
		} else {
			return this.renderCondition(node, path);
		}
	}

	renderGroup(node, path) {
		const pathStr = path.join('.');
		const isRoot = path.length === 0;

		const html = `
			<div class="criteria-group" data-path="${pathStr}">
				<div class="group-header">
					<select class="group-operator" data-path="${pathStr}">
						<option value="AND" ${node.operator === 'AND' ? 'selected' : ''}>All conditions must match (AND)</option>
						<option value="OR" ${node.operator === 'OR' ? 'selected' : ''}>Any condition can match (OR)</option>
					</select>
					${!isRoot ? '<button class="btn-remove-group" type="button" title="Remove this group">✕</button>' : ''}
				</div>
				<div class="group-conditions">
					${node.conditions.map((cond, idx) => {
						const childPath = [...path, 'conditions', idx];
						return this.renderNode(cond, childPath);
					}).join('')}
				</div>
				<div class="group-actions">
					<button class="btn-add-condition" type="button" data-path="${pathStr}">+ Add Condition</button>
					<button class="btn-add-group" type="button" data-path="${pathStr}">+ Add Group</button>
				</div>
			</div>
		`;

		return html;
	}

	renderCondition(node, path) {
		const pathStr = path.join('.');

		return `
			<div class="criteria-condition" data-path="${pathStr}">
				<div class="condition-row">
					<label class="negate-checkbox">
						<input type="checkbox" class="condition-negate" data-path="${pathStr}" ${node.negate ? 'checked' : ''}>
						<span class="negate-label">NOT</span>
					</label>
					<select class="condition-field" data-path="${pathStr}">
						<option value="description" ${node.field === 'description' ? 'selected' : ''}>Description</option>
						<option value="vendor" ${node.field === 'vendor' ? 'selected' : ''}>Vendor</option>
						<option value="amount" ${node.field === 'amount' ? 'selected' : ''}>Amount</option>
						<option value="reference" ${node.field === 'reference' ? 'selected' : ''}>Reference</option>
						<option value="notes" ${node.field === 'notes' ? 'selected' : ''}>Notes</option>
						<option value="date" ${node.field === 'date' ? 'selected' : ''}>Date</option>
					</select>
					<select class="condition-match-type" data-path="${pathStr}">
						${this.renderMatchTypeOptions(node.field, node.matchType)}
					</select>
					<input type="text" class="condition-pattern" data-path="${pathStr}"
						value="${this.escapeHtml(node.pattern || '')}"
						placeholder="${this.getPatternPlaceholder(node.field, node.matchType)}">
					<button class="btn-remove-condition" type="button" data-path="${pathStr}" title="Remove this condition">✕</button>
				</div>
			</div>
		`;
	}

	renderMatchTypeOptions(field, currentMatchType) {
		const stringTypes = {
			'contains': 'contains',
			'starts_with': 'starts with',
			'ends_with': 'ends with',
			'equals': 'equals (exact match)',
			'regex': 'matches regex'
		};

		const numericTypes = {
			'equals': 'equals',
			'greater_than': 'greater than',
			'less_than': 'less than',
			'between': 'between'
		};

		const dateTypes = {
			'equals': 'on date',
			'before': 'before',
			'after': 'after',
			'between': 'between dates'
		};

		let types = stringTypes;
		if (field === 'amount') {
			types = numericTypes;
		} else if (field === 'date') {
			types = dateTypes;
		}

		return Object.entries(types).map(([value, label]) =>
			`<option value="${value}" ${currentMatchType === value ? 'selected' : ''}>${label}</option>`
		).join('');
	}

	getPatternPlaceholder(field, matchType) {
		if (field === 'amount') {
			if (matchType === 'between') {
				return 'e.g., {"min": 10, "max": 100}';
			}
			return 'e.g., 50.00';
		}

		if (field === 'date') {
			if (matchType === 'between') {
				return 'e.g., {"min": "2024-01-01", "max": "2024-12-31"}';
			}
			return 'e.g., 2024-01-15';
		}

		if (matchType === 'regex') {
			return 'e.g., ^ORDER-\\d+';
		}

		return 'e.g., amazon';
	}

	attachEventListeners() {
		// Add condition button
		this.container.querySelectorAll('.btn-add-condition').forEach(btn => {
			btn.addEventListener('click', (e) => this.addCondition(e.target.dataset.path));
		});

		// Add group button
		this.container.querySelectorAll('.btn-add-group').forEach(btn => {
			btn.addEventListener('click', (e) => this.addGroup(e.target.dataset.path));
		});

		// Remove condition button
		this.container.querySelectorAll('.btn-remove-condition').forEach(btn => {
			btn.addEventListener('click', (e) => this.removeCondition(e.target.dataset.path));
		});

		// Remove group button
		this.container.querySelectorAll('.btn-remove-group').forEach(btn => {
			btn.addEventListener('click', (e) => this.removeGroup(e.target.dataset.path));
		});

		// Group operator change
		this.container.querySelectorAll('.group-operator').forEach(select => {
			select.addEventListener('change', (e) => this.updateGroupOperator(e.target.dataset.path, e.target.value));
		});

		// Field change (re-render match types)
		this.container.querySelectorAll('.condition-field').forEach(select => {
			select.addEventListener('change', (e) => this.updateConditionField(e.target.dataset.path, e.target.value));
		});

		// Match type change
		this.container.querySelectorAll('.condition-match-type').forEach(select => {
			select.addEventListener('change', (e) => this.updateConditionMatchType(e.target.dataset.path, e.target.value));
		});

		// Pattern change
		this.container.querySelectorAll('.condition-pattern').forEach(input => {
			input.addEventListener('input', (e) => this.updateConditionPattern(e.target.dataset.path, e.target.value));
		});

		// Negate checkbox change
		this.container.querySelectorAll('.condition-negate').forEach(checkbox => {
			checkbox.addEventListener('change', (e) => this.updateConditionNegate(e.target.dataset.path, e.target.checked));
		});
	}

	addCondition(pathStr) {
		const node = this.getNodeAtPath(pathStr);
		if (!node || !node.conditions) return;

		node.conditions.push({
			type: 'condition',
			field: 'description',
			matchType: 'contains',
			pattern: '',
			negate: false
		});

		this.render();
	}

	addGroup(pathStr) {
		const node = this.getNodeAtPath(pathStr);
		if (!node || !node.conditions) return;

		node.conditions.push({
			operator: 'AND',
			conditions: [
				{
					type: 'condition',
					field: 'description',
					matchType: 'contains',
					pattern: '',
					negate: false
				}
			]
		});

		this.render();
	}

	removeCondition(pathStr) {
		const pathParts = pathStr.split('.');
		if (pathParts.length < 2) return; // Can't remove from root

		const parentPath = pathParts.slice(0, -1).join('.');
		const index = parseInt(pathParts[pathParts.length - 1]);

		const parent = this.getNodeAtPath(parentPath);
		if (parent && parent.conditions && parent.conditions.length > 1) {
			parent.conditions.splice(index, 1);
			this.render();
		} else {
			alert('Cannot remove the last condition from a group. Remove the group instead.');
		}
	}

	removeGroup(pathStr) {
		const pathParts = pathStr.split('.');
		if (pathParts.length < 2) return; // Can't remove root

		const parentPath = pathParts.slice(0, -1).join('.');
		const index = parseInt(pathParts[pathParts.length - 1]);

		const parent = this.getNodeAtPath(parentPath);
		if (parent && parent.conditions) {
			parent.conditions.splice(index, 1);
			this.render();
		}
	}

	updateGroupOperator(pathStr, value) {
		const node = this.getNodeAtPath(pathStr);
		if (node) {
			node.operator = value;
		}
	}

	updateConditionField(pathStr, value) {
		const node = this.getNodeAtPath(pathStr);
		if (node) {
			node.field = value;
			// Reset match type to default for new field
			if (value === 'amount') {
				node.matchType = 'equals';
			} else if (value === 'date') {
				node.matchType = 'equals';
			} else {
				node.matchType = 'contains';
			}
			this.render();
		}
	}

	updateConditionMatchType(pathStr, value) {
		const node = this.getNodeAtPath(pathStr);
		if (node) {
			node.matchType = value;
		}
	}

	updateConditionPattern(pathStr, value) {
		const node = this.getNodeAtPath(pathStr);
		if (node) {
			node.pattern = value;
		}
	}

	updateConditionNegate(pathStr, value) {
		const node = this.getNodeAtPath(pathStr);
		if (node) {
			node.negate = value;
		}
	}

	getNodeAtPath(pathStr) {
		if (!pathStr) return this.criteria.root;

		const parts = pathStr.split('.');
		let node = this.criteria.root;

		for (const part of parts) {
			if (part === '') continue;
			if (part === 'conditions') continue; // Skip 'conditions' key

			// It's an index
			const index = parseInt(part);
			if (!isNaN(index)) {
				node = node.conditions[index];
			}
		}

		return node;
	}

	getCriteria() {
		return this.criteria;
	}

	validate() {
		const errors = [];
		this.validateNode(this.criteria.root, '', errors);
		return {
			valid: errors.length === 0,
			errors: errors
		};
	}

	validateNode(node, path, errors) {
		if (node.operator) {
			// Group node
			if (!node.conditions || node.conditions.length === 0) {
				errors.push(`Group at ${path || 'root'} has no conditions`);
			} else {
				node.conditions.forEach((child, idx) => {
					const childPath = path ? `${path} > condition ${idx + 1}` : `condition ${idx + 1}`;
					this.validateNode(child, childPath, errors);
				});
			}
		} else {
			// Condition node
			if (!node.field) {
				errors.push(`Condition at ${path} has no field selected`);
			}
			if (!node.matchType) {
				errors.push(`Condition at ${path} has no match type selected`);
			}
			if (!node.pattern || node.pattern.trim() === '') {
				errors.push(`Condition at ${path} has no pattern value`);
			}

			// Validate regex if match type is regex
			if (node.matchType === 'regex' && node.pattern) {
				try {
					new RegExp(node.pattern);
				} catch (e) {
					errors.push(`Condition at ${path} has invalid regex pattern: ${e.message}`);
				}
			}

			// Validate JSON for 'between' match types
			if (node.matchType === 'between' && node.pattern) {
				try {
					const parsed = JSON.parse(node.pattern);
					if (!parsed.min || !parsed.max) {
						errors.push(`Condition at ${path} 'between' pattern must have 'min' and 'max' properties`);
					}
				} catch (e) {
					errors.push(`Condition at ${path} 'between' pattern must be valid JSON with min/max`);
				}
			}
		}
	}

	escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}
}
