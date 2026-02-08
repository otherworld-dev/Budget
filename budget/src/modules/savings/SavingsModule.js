/**
 * Savings Module - Savings goals tracking and management
 */
import * as formatters from '../../utils/formatters.js';
import * as dom from '../../utils/dom.js';

export default class SavingsModule {
    constructor(app) {
        this.app = app;
        this._eventsSetup = false;
        this._allTagSets = [];
    }

    // Getters for app state
    get savingsGoals() { return this.app.savingsGoals; }
    set savingsGoals(value) { this.app.savingsGoals = value; }
    get accounts() { return this.app.accounts; }
    get settings() { return this.app.settings; }

    async loadSavingsGoalsView() {
        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/savings-goals'), {
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this.savingsGoals = await response.json();
            this.updateGoalsSummary();
            this.renderGoals(this.savingsGoals);

            // Setup event listeners (only once)
            if (!this._eventsSetup) {
                this.setupGoalsEventListeners();
                this._eventsSetup = true;
            }

            // Populate dropdowns in modal
            this.populateGoalAccountDropdown();
            this.populateGoalTagDropdown();
        } catch (error) {
            console.error('Failed to load savings goals:', error);
            OC.Notification.showTemporary('Failed to load savings goals');
        }
    }

    updateGoalsSummary() {
        const goals = this.savingsGoals || [];
        const activeGoals = goals.filter(g => !g.completed);
        const completedGoals = goals.filter(g => g.completed);

        const totalSaved = goals.reduce((sum, g) => sum + (parseFloat(g.currentAmount || g.current_amount) || 0), 0);
        const totalTarget = goals.reduce((sum, g) => sum + (parseFloat(g.targetAmount || g.target_amount) || 0), 0);

        document.getElementById('goals-total-count').textContent = activeGoals.length;
        document.getElementById('goals-total-saved').textContent = formatters.formatCurrency(totalSaved, null, this.settings);
        document.getElementById('goals-total-target').textContent = formatters.formatCurrency(totalTarget, null, this.settings);
        document.getElementById('goals-completed-count').textContent = completedGoals.length;
    }

    renderGoals(goals) {
        const goalsList = document.getElementById('goals-list');
        const emptyGoals = document.getElementById('empty-goals');

        if (!goals || goals.length === 0) {
            goalsList.innerHTML = '';
            emptyGoals.style.display = 'block';
            return;
        }

        emptyGoals.style.display = 'none';

        goalsList.innerHTML = goals.map(goal => {
            const current = parseFloat(goal.currentAmount || goal.current_amount) || 0;
            const target = parseFloat(goal.targetAmount || goal.target_amount) || 0;
            const percentage = target > 0 ? Math.min((current / target) * 100, 100) : 0;
            const isCompleted = current >= target;
            const isTagLinked = goal.tagId != null;
            const color = goal.color || '#0082c9';
            const targetDate = goal.targetDate || goal.target_date;

            let targetDateText = '';
            if (targetDate) {
                const date = new Date(targetDate);
                const today = new Date();
                const daysLeft = Math.ceil((date - today) / (1000 * 60 * 60 * 24));

                if (daysLeft < 0) {
                    targetDateText = `Target date passed`;
                } else if (daysLeft === 0) {
                    targetDateText = 'Target date: Today';
                } else if (daysLeft <= 30) {
                    targetDateText = `${daysLeft} days left`;
                } else {
                    targetDateText = `Target: ${date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
                }
            }

            let footerAction;
            if (isCompleted) {
                footerAction = '<span class="goal-completed-badge"><span class="icon-checkmark"></span> Goal reached!</span>';
            } else if (isTagLinked) {
                footerAction = '<span class="goal-auto-tracked"><span class="icon-tag"></span> Auto-tracked</span>';
            } else {
                footerAction = `<button class="goal-add-money-btn" data-goal-id="${goal.id}">+ Add money</button>`;
            }

            return `
                <div class="goal-card ${isCompleted ? 'completed' : ''}" data-goal-id="${goal.id}">
                    <div class="goal-card-header">
                        <div class="goal-card-title">
                            <span class="goal-color-indicator" style="background: ${color}"></span>
                            <h3 class="goal-name">${dom.escapeHtml(goal.name)}</h3>
                        </div>
                        <div class="goal-card-actions">
                            <button class="edit-goal-btn" title="Edit" data-goal-id="${goal.id}">
                                <span class="icon-rename"></span>
                            </button>
                            <button class="delete-goal-btn delete-btn" title="Delete" data-goal-id="${goal.id}">
                                <span class="icon-delete"></span>
                            </button>
                        </div>
                    </div>

                    <div class="goal-progress-section">
                        <div class="goal-progress-bar">
                            <div class="goal-progress-fill" style="width: ${percentage}%; background: ${isCompleted ? 'linear-gradient(90deg, #2e7d32, #43a047)' : `linear-gradient(90deg, ${color}, ${color}dd)`}"></div>
                        </div>
                        <div class="goal-amounts">
                            <span class="goal-current-amount">${formatters.formatCurrency(current, null, this.settings)}</span>
                            <span class="goal-percentage">${percentage.toFixed(0)}%</span>
                            <span class="goal-target-amount">of ${formatters.formatCurrency(target, null, this.settings)}</span>
                        </div>
                    </div>

                    <div class="goal-footer">
                        ${targetDateText ? `<span class="goal-target-date"><span class="icon-calendar"></span> ${targetDateText}</span>` : '<span></span>'}
                        ${footerAction}
                    </div>
                </div>
            `;
        }).join('');
    }

    setupGoalsEventListeners() {
        // Add goal button
        document.getElementById('add-goal-btn')?.addEventListener('click', () => {
            this.showGoalModal();
        });

        // Empty state add button
        document.getElementById('empty-goals-add-btn')?.addEventListener('click', () => {
            this.showGoalModal();
        });

        // Goal form submission
        document.getElementById('goal-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.saveGoal();
        });

        // Add money form
        document.getElementById('add-to-goal-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.addMoneyToGoal();
        });

        // Event delegation for goal cards
        document.getElementById('goals-list')?.addEventListener('click', (e) => {
            const editBtn = e.target.closest('.edit-goal-btn');
            const deleteBtn = e.target.closest('.delete-goal-btn');
            const addMoneyBtn = e.target.closest('.goal-add-money-btn');

            if (editBtn) {
                const goalId = parseInt(editBtn.dataset.goalId);
                this.editGoal(goalId);
            } else if (deleteBtn) {
                const goalId = parseInt(deleteBtn.dataset.goalId);
                this.deleteGoal(goalId);
            } else if (addMoneyBtn) {
                const goalId = parseInt(addMoneyBtn.dataset.goalId);
                this.showAddMoneyModal(goalId);
            }
        });

        // Goal modal cancel buttons
        const goalModal = document.getElementById('goal-modal');
        if (goalModal) {
            goalModal.querySelectorAll('.cancel-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    goalModal.style.display = 'none';
                });
            });
        }

        // Add money modal cancel buttons
        const addToGoalModal = document.getElementById('add-to-goal-modal');
        if (addToGoalModal) {
            addToGoalModal.querySelectorAll('.cancel-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    addToGoalModal.style.display = 'none';
                });
            });
        }

        // Tag dropdown change - toggle current amount field
        document.getElementById('goal-tag')?.addEventListener('change', (e) => {
            this.updateCurrentAmountFieldState(e.target.value !== '');
        });

        // Color preview
        document.getElementById('goal-color')?.addEventListener('input', (e) => {
            const preview = document.getElementById('goal-color-preview');
            if (preview) {
                preview.style.backgroundColor = e.target.value;
            }
        });
    }

    updateCurrentAmountFieldState(tagLinked) {
        const currentAmountInput = document.getElementById('goal-current');
        const hint = document.getElementById('goal-tag-hint');

        if (currentAmountInput) {
            currentAmountInput.disabled = tagLinked;
            if (tagLinked) {
                currentAmountInput.value = '';
                currentAmountInput.placeholder = 'Auto-calculated from tag';
            } else {
                currentAmountInput.placeholder = '0.00';
            }
        }
        if (hint) {
            hint.style.display = tagLinked ? 'block' : 'none';
        }
    }

    populateGoalAccountDropdown() {
        const dropdown = document.getElementById('goal-account');
        if (!dropdown) return;

        dropdown.innerHTML = '<option value="">No linked account</option>' +
            (Array.isArray(this.accounts) ? this.accounts.map(a =>
                `<option value="${a.id}">${dom.escapeHtml(a.name)}</option>`
            ).join('') : '');
    }

    async populateGoalTagDropdown() {
        const dropdown = document.getElementById('goal-tag');
        if (!dropdown) return;

        try {
            const response = await fetch(OC.generateUrl('/apps/budget/api/tag-sets'), {
                headers: { 'requesttoken': OC.requestToken }
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this._allTagSets = await response.json();

            let html = '<option value="">No linked tag</option>';
            for (const tagSet of this._allTagSets) {
                if (tagSet.tags && tagSet.tags.length > 0) {
                    html += `<optgroup label="${dom.escapeHtml(tagSet.name)}">`;
                    for (const tag of tagSet.tags) {
                        html += `<option value="${tag.id}">${dom.escapeHtml(tag.name)}</option>`;
                    }
                    html += '</optgroup>';
                }
            }
            dropdown.innerHTML = html;
        } catch (error) {
            console.error('Failed to load tags for goal dropdown:', error);
        }
    }

    showGoalModal(goal = null) {
        const modal = document.getElementById('goal-modal');
        const title = document.getElementById('goal-modal-title');
        const form = document.getElementById('goal-form');

        if (!modal || !form) return;

        title.textContent = goal ? 'Edit Savings Goal' : 'Add Savings Goal';

        // Reset form
        form.reset();
        document.getElementById('goal-id').value = '';
        document.getElementById('goal-color').value = '#0082c9';

        // Reset tag dropdown and current amount field state
        const tagDropdown = document.getElementById('goal-tag');
        if (tagDropdown) {
            tagDropdown.value = '';
        }
        this.updateCurrentAmountFieldState(false);

        // Populate if editing
        if (goal) {
            document.getElementById('goal-id').value = goal.id;
            document.getElementById('goal-name').value = goal.name;
            document.getElementById('goal-target').value = goal.targetAmount || goal.target_amount || '';
            document.getElementById('goal-current').value = goal.currentAmount || goal.current_amount || 0;
            document.getElementById('goal-target-date').value = goal.targetDate || goal.target_date || '';
            document.getElementById('goal-notes').value = goal.description || '';

            // Set tag dropdown
            if (tagDropdown && goal.tagId) {
                tagDropdown.value = goal.tagId;
                this.updateCurrentAmountFieldState(true);
            }
        }

        modal.style.display = 'flex';
    }

    async saveGoal() {
        const goalId = document.getElementById('goal-id').value;
        const tagValue = document.getElementById('goal-tag')?.value;

        const targetDateValue = document.getElementById('goal-target-date').value;
        const descriptionValue = document.getElementById('goal-notes').value;

        const data = {
            name: document.getElementById('goal-name').value,
            targetAmount: parseFloat(document.getElementById('goal-target').value) || 0,
            currentAmount: parseFloat(document.getElementById('goal-current').value) || 0,
            targetDate: targetDateValue || null,
            description: descriptionValue || null,
            tagId: tagValue ? parseInt(tagValue) : null
        };

        try {
            const url = goalId
                ? OC.generateUrl(`/apps/budget/api/savings-goals/${goalId}`)
                : OC.generateUrl('/apps/budget/api/savings-goals');

            const response = await fetch(url, {
                method: goalId ? 'PUT' : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify(data)
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                console.error('Save goal error response:', response.status, errorData);
                OC.Notification.showTemporary(errorData.error || 'Failed to save goal');
                return;
            }

            document.getElementById('goal-modal').style.display = 'none';
            OC.Notification.showTemporary(goalId ? 'Goal updated' : 'Goal created');
            await this.loadSavingsGoalsView();
        } catch (error) {
            console.error('Failed to save goal:', error);
            OC.Notification.showTemporary('Failed to save goal');
        }
    }

    editGoal(goalId) {
        const goal = this.savingsGoals?.find(g => g.id === goalId);
        if (goal) {
            this.showGoalModal(goal);
        }
    }

    async deleteGoal(goalId) {
        if (!confirm('Are you sure you want to delete this savings goal?')) {
            return;
        }

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/savings-goals/${goalId}`), {
                method: 'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            OC.Notification.showTemporary('Goal deleted');
            await this.loadSavingsGoalsView();
        } catch (error) {
            console.error('Failed to delete goal:', error);
            OC.Notification.showTemporary('Failed to delete goal');
        }
    }

    showAddMoneyModal(goalId) {
        const goal = this.savingsGoals?.find(g => g.id === goalId);
        if (!goal) return;

        // Guard against tag-linked goals
        if (goal.tagId) {
            OC.Notification.showTemporary('This goal is auto-tracked via a tag');
            return;
        }

        const modal = document.getElementById('add-to-goal-modal');
        document.getElementById('add-to-goal-name').textContent = goal.name;
        document.getElementById('add-to-goal-id').value = goalId;
        document.getElementById('add-amount').value = '';

        modal.style.display = 'flex';
    }

    async addMoneyToGoal() {
        const goalId = document.getElementById('add-to-goal-id').value;
        const amount = parseFloat(document.getElementById('add-amount').value) || 0;

        if (amount <= 0) {
            OC.Notification.showTemporary('Please enter a valid amount');
            return;
        }

        const goal = this.savingsGoals?.find(g => g.id === parseInt(goalId));
        if (!goal) return;

        // Guard against tag-linked goals
        if (goal.tagId) {
            OC.Notification.showTemporary('This goal is auto-tracked via a tag');
            return;
        }

        const currentAmount = parseFloat(goal.currentAmount || goal.current_amount) || 0;
        const newAmount = currentAmount + amount;

        try {
            const response = await fetch(OC.generateUrl(`/apps/budget/api/savings-goals/${goalId}`), {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ currentAmount: newAmount })
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            document.getElementById('add-to-goal-modal').style.display = 'none';
            OC.Notification.showTemporary(`Added ${formatters.formatCurrency(amount, null, this.settings)} to goal`);
            await this.loadSavingsGoalsView();
        } catch (error) {
            console.error('Failed to add money to goal:', error);
            OC.Notification.showTemporary('Failed to add money to goal');
        }
    }
}
