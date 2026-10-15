import './CriteriaBuilder.css';
import { translate as t } from '@nextcloud/l10n';
import { alertDialog } from '../../../utils/dialogs.js';
import { formatAccountType } from '../../../utils/formatters';
import { escapeHtml } from '../../../utils/dom.js';

/**
 * A condition's pattern as the text shown in (and typed into) its input.
 * Patterns are usually strings, but criteria saved through the JSON editor or
 * the API can hold a number (an amount, an account id), and 'between' ranges
 * are stored as a {min, max} object - shown here as the JSON the user types.
 * @param {*} pattern
 * @returns {string}
 */
export function patternText(pattern) {
	if (pattern === null || pattern === undefined) {
		return '';
	}
	if (typeof pattern === 'object') {
		return JSON.stringify(pattern);
	}
	return String(pattern);
}

/**
 * Whether one bound of a 'between' range holds a value. 0 is a real bound,
 * so this can't be a truthiness check.
 * @param {*} bound
 * @param {boolean} numeric Require a number (amount ranges)
 * @returns {boolean}
 */
function isRangeBound(bound, numeric) {
	if (typeof bound === 'number') {
		return Number.isFinite(bound);
	}
	if (typeof bound !== 'string' || bound.trim() === '') {
		return false;
	}
	return !numeric || Number.isFinite(Number(bound));
}

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
	constructor(containerEl, initialCriteria = null, options = {}) {
		this.container = containerEl;
		// Accounts power the Account / Account Type condition pickers; empty is fine
		this.accounts = options.accounts || [];
		this.criteria = this.normalizeCriteria(initialCriteria);
		this.render();
	}

	/**
	 * Distinct account types among the user's accounts, for the Account Type
	 * condition picker. Derived from real accounts so users only see types they
	 * actually have.
	 * @returns {string[]}
	 */
	getAccountTypeOptions() {
		const seen = [];
		this.accounts.forEach(acc => {
			const type = acc.type;
			if (type && !seen.includes(type)) {
				seen.push(type);
			}
		});
		return seen;
	}

	/**
	 * Normalize criteria to ensure root is always a group
	 * Fixes legacy migrations where root was a single condition
	 */
	normalizeCriteria(criteria) {
		if (!criteria || typeof criteria !== 'object') {
			return this.createEmptyRoot();
		}

		const root = criteria.root;

		// Guard against a null/missing/non-object root (e.g. hand-edited JSON
		// like {"criteria": {}} or {"root": null}); render() dereferences
		// root.operator and would otherwise throw (#318).
		if (!root || typeof root !== 'object') {
			return this.createEmptyRoot();
		}

		// If root is a single condition (legacy migration bug), wrap it in a group
		if (root.type === 'condition') {
			return {
				version: 2,
				root: {
					operator: 'AND',
					conditions: [root]
				}
			};
		}

		// A root that is neither a group (has an operator) nor a condition is
		// malformed — fall back to an empty builder rather than crashing.
		if (!root.operator) {
			return this.createEmptyRoot();
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
					<select class="group-operator" data-path="${pathStr}" aria-label="${t('budget', 'How conditions combine')}">
						<option value="AND" ${node.operator === 'AND' ? 'selected' : ''}>${t('budget', 'All conditions must match (AND)')}</option>
						<option value="OR" ${node.operator === 'OR' ? 'selected' : ''}>${t('budget', 'Any condition can match (OR)')}</option>
					</select>
					${!isRoot ? `<button class="btn-remove-group" type="button" data-path="${pathStr}" title="${t('budget', 'Remove this group')}" aria-label="${t('budget', 'Remove this group')}">✕</button>` : ''}
				</div>
				<div class="group-conditions">
					${node.conditions.map((cond, idx) => {
						const childPath = [...path, 'conditions', idx];
						return this.renderNode(cond, childPath);
					}).join('')}
				</div>
				<div class="group-actions">
					<button class="btn-add-condition" type="button" data-path="${pathStr}">${t('budget', '+ Add Condition')}</button>
					<button class="btn-add-group" type="button" data-path="${pathStr}">${t('budget', '+ Add Group')}</button>
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
						<span class="negate-label">${t('budget', 'NOT')}</span>
					</label>
					<select class="condition-field" data-path="${pathStr}" aria-label="${t('budget', 'Field')}">
						<option value="description" ${node.field === 'description' ? 'selected' : ''}>${t('budget', 'Description')}</option>
						<option value="vendor" ${node.field === 'vendor' ? 'selected' : ''}>${t('budget', 'Vendor')}</option>
						<option value="amount" ${node.field === 'amount' ? 'selected' : ''}>${t('budget', 'Amount')}</option>
						<option value="type" ${node.field === 'type' ? 'selected' : ''}>${t('budget', 'Transaction Type')}</option>
						<option value="reference" ${node.field === 'reference' ? 'selected' : ''}>${t('budget', 'Reference')}</option>
						<option value="notes" ${node.field === 'notes' ? 'selected' : ''}>${t('budget', 'Notes')}</option>
						<option value="date" ${node.field === 'date' ? 'selected' : ''}>${t('budget', 'Date')}</option>
						<option value="account" ${node.field === 'account' ? 'selected' : ''}>${t('budget', 'Account')}</option>
						<option value="account_type" ${node.field === 'account_type' ? 'selected' : ''}>${t('budget', 'Account Type')}</option>
						<option value="source" ${node.field === 'source' ? 'selected' : ''}>${t('budget', 'Import Source')}</option>
					</select>
					<select class="condition-match-type" data-path="${pathStr}" aria-label="${t('budget', 'Match Type')}">
						${this.renderMatchTypeOptions(node.field, node.matchType)}
					</select>
					${this.renderPatternWidget(node, pathStr)}
					<button class="btn-remove-condition" type="button" data-path="${pathStr}" title="${t('budget', 'Remove this condition')}" aria-label="${t('budget', 'Remove this condition')}">✕</button>
				</div>
			</div>
		`;
	}

	/**
	 * Render the value widget for a condition. Most fields use a free-text
	 * input, but entity/enum fields (Transaction Type, Account, Account Type)
	 * use a dropdown so the stored pattern is always a valid value.
	 */
	renderPatternWidget(node, pathStr) {
		if (node.field === 'type') {
			return `<select class="condition-pattern" data-path="${pathStr}" aria-label="${t('budget', 'Value')}">
				<option value="debit" ${node.pattern === 'debit' ? 'selected' : ''}>${t('budget', 'Expense')}</option>
				<option value="credit" ${node.pattern === 'credit' ? 'selected' : ''}>${t('budget', 'Income')}</option>
			</select>`;
		}

		if (node.field === 'account') {
			if (this.accounts.length === 0) {
				return `<span class="condition-pattern-empty">${t('budget', 'No accounts available')}</span>`;
			}
			return `<select class="condition-pattern" data-path="${pathStr}" aria-label="${t('budget', 'Value')}">
				${this.accounts.map(acc =>
					`<option value="${escapeHtml(String(acc.id))}" ${String(node.pattern) === String(acc.id) ? 'selected' : ''}>${escapeHtml(String(acc.name ?? ''))}</option>`
				).join('')}
			</select>`;
		}

		if (node.field === 'account_type') {
			const types = this.getAccountTypeOptions();
			if (types.length === 0) {
				return `<span class="condition-pattern-empty">${t('budget', 'No accounts available')}</span>`;
			}
			return `<select class="condition-pattern" data-path="${pathStr}" aria-label="${t('budget', 'Value')}">
				${types.map(type =>
					`<option value="${escapeHtml(type)}" ${node.pattern === type ? 'selected' : ''}>${escapeHtml(formatAccountType(type))}</option>`
				).join('')}
			</select>`;
		}

		return `<input type="text" class="condition-pattern" data-path="${pathStr}"
			aria-label="${t('budget', 'Value')}"
			value="${escapeHtml(patternText(node.pattern))}"
			placeholder="${escapeHtml(this.getPatternPlaceholder(node.field, node.matchType))}">`;
	}

	renderMatchTypeOptions(field, currentMatchType) {
		const stringTypes = {
			'contains': t('budget', 'contains'),
			'starts_with': t('budget', 'starts with'),
			'ends_with': t('budget', 'ends with'),
			'equals': t('budget', 'equals (exact match)'),
			'regex': t('budget', 'matches regex')
		};

		const numericTypes = {
			'equals': t('budget', 'equals'),
			'greater_than': t('budget', 'greater than'),
			'less_than': t('budget', 'less than'),
			'between': t('budget', 'between')
		};

		const dateTypes = {
			'equals': t('budget', 'on date'),
			'before': t('budget', 'before'),
			'after': t('budget', 'after'),
			'between': t('budget', 'between dates')
		};

		let types = stringTypes;
		if (field === 'amount') {
			types = numericTypes;
		} else if (field === 'date') {
			types = dateTypes;
		} else if (field === 'type' || field === 'account' || field === 'account_type') {
			// Entity/enum fields match by exact value only ("is"); negate covers "is not"
			types = { 'equals': t('budget', 'is') };
		}

		return Object.entries(types).map(([value, label]) =>
			`<option value="${value}" ${currentMatchType === value ? 'selected' : ''}>${label}</option>`
		).join('');
	}

	getPatternPlaceholder(field, matchType) {
		if (field === 'amount') {
			if (matchType === 'between') {
				return t('budget', 'e.g., {"min": 10, "max": 100}');
			}
			return t('budget', 'e.g., 50.00');
		}

		if (field === 'date') {
			if (matchType === 'between') {
				return t('budget', 'e.g., {"min": "2024-01-01", "max": "2024-12-31"}');
			}
			return t('budget', 'e.g., 2024-01-15');
		}

		if (matchType === 'regex') {
			return t('budget', 'e.g., ^ORDER-\\d+');
		}

		// The source values are fixed literals the importer writes, so the
		// placeholder has to name one or there is no way to guess them
		if (field === 'source') {
			return t('budget', 'e.g., OFX Import');
		}

		return t('budget', 'e.g., amazon');
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

		// Pattern change (input for text, change for select)
		this.container.querySelectorAll('.condition-pattern').forEach(el => {
			el.addEventListener('input', (e) => this.updateConditionPattern(e.target.dataset.path, e.target.value));
			el.addEventListener('change', (e) => this.updateConditionPattern(e.target.dataset.path, e.target.value));
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
			alertDialog(t('budget', 'Cannot remove the last condition from a group. Remove the group instead.'));
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
			} else if (value === 'type') {
				node.matchType = 'equals';
				node.pattern = 'debit';
			} else if (value === 'account') {
				node.matchType = 'equals';
				node.pattern = this.accounts.length ? String(this.accounts[0].id) : '';
			} else if (value === 'account_type') {
				node.matchType = 'equals';
				const types = this.getAccountTypeOptions();
				node.pattern = types.length ? types[0] : '';
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
				errors.push(t('budget', 'Group at {path} has no conditions', { path: path || t('budget', 'root') }));
			} else {
				node.conditions.forEach((child, idx) => {
					const childPath = path ? `${path} > ${t('budget', 'condition {number}', { number: idx + 1 })}` : t('budget', 'condition {number}', { number: idx + 1 });
					this.validateNode(child, childPath, errors);
				});
			}
		} else {
			// Condition node
			if (!node.field) {
				errors.push(t('budget', 'Condition at {path} has no field selected', { path }));
			}
			if (!node.matchType) {
				errors.push(t('budget', 'Condition at {path} has no match type selected', { path }));
			}
			const pattern = patternText(node.pattern);
			if (pattern.trim() === '') {
				errors.push(t('budget', 'Condition at {path} has no pattern value', { path }));
			}

			// Regex validation is intentionally left to the server-side save path so
			// we accept both bare patterns and full /pattern/flags literals without
			// JavaScript-specific incompatibilities such as PCRE-only inline flags.
			// Validate JSON for 'between' match types
			if (node.matchType === 'between' && pattern.trim() !== '') {
				try {
					const parsed = typeof node.pattern === 'object' ? node.pattern : JSON.parse(pattern);
					const numeric = node.field === 'amount';
					if (!parsed || typeof parsed !== 'object'
						|| !isRangeBound(parsed.min, numeric) || !isRangeBound(parsed.max, numeric)) {
						errors.push(t('budget', "Condition at {path} 'between' pattern must have 'min' and 'max' properties", { path }));
					}
				} catch (e) {
					errors.push(t('budget', "Condition at {path} 'between' pattern must be valid JSON with min/max", { path }));
				}
			}
		}
	}
}
