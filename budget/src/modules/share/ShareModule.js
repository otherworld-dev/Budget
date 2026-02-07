/**
 * ShareModule - Multi-user budget sharing management
 *
 * Handles:
 * - Viewing budgets shared with user
 * - Sharing own budget with other users
 * - Revoking access
 * - User search and autocomplete
 */
export default class ShareModule {
	constructor(app) {
		this.app = app;
		this.apiClient = app.apiClient;
		this.shares = {
			owned: [], // Shares I've created (users I've shared my budget with)
			received: [] // Shares I've received (budgets shared with me)
		};
		this.searchResults = [];
		this.searchTimeout = null;
	}

	/**
	 * Initialize the share module
	 */
	async init() {
		console.log('[ShareModule] Initializing...');
		await this.loadShares();

		// Set up budget switcher
		this.setupBudgetSwitcher();
	}

	/**
	 * Set up the budget switcher component
	 */
	setupBudgetSwitcher() {
		const backBtn = document.getElementById('budget-switcher-back-btn');
		if (backBtn) {
			backBtn.addEventListener('click', () => this.switchToOwnBudget());
		}
	}

	/**
	 * Update budget switcher visibility based on current owner context
	 */
	updateBudgetSwitcher() {
		const switcher = document.getElementById('budget-switcher');
		const ownerSpan = document.getElementById('budget-switcher-owner');
		const currentOwner = this.app.authModule.currentOwnerUserId;

		if (!switcher || !ownerSpan) return;

		if (currentOwner) {
			// Find the owner's display name from received shares
			const sharedBudget = this.shares.received.find(s => s.ownerUserId === currentOwner);
			const ownerName = sharedBudget ?
				(sharedBudget.ownerDisplayName || sharedBudget.ownerUserId) :
				currentOwner;

			ownerSpan.textContent = ownerName;
			switcher.style.display = 'block';
		} else {
			switcher.style.display = 'none';
		}
	}

	/**
	 * Load all shares (owned and received)
	 */
	async loadShares() {
		try {
			const response = await this.apiClient.get('/shares');

			if (response.data) {
				this.shares.owned = response.data.owned || [];
				this.shares.received = response.data.received || [];

				console.log('[ShareModule] Loaded shares:', {
					owned: this.shares.owned.length,
					received: this.shares.received.length
				});
			}
		} catch (error) {
			console.error('[ShareModule] Failed to load shares:', error);
			this.app.showNotification('Failed to load shares', 'error');
		}
	}

	/**
	 * Render the sharing settings section
	 * @param {HTMLElement} container Container element to render into
	 */
	render(container) {
		container.innerHTML = `
			<div class="share-module">
				<div class="share-section">
					<h3>Share My Budget</h3>
					<p class="share-description">
						Share your budget with family members or trusted users.
						They will have read-only access to view your accounts, transactions, and reports.
					</p>

					<div class="share-new-form">
						<div class="form-group">
							<label for="share-user-search">Find User</label>
							<input
								type="text"
								id="share-user-search"
								class="form-control"
								placeholder="Search by name or email..."
								autocomplete="off"
							/>
							<div id="share-user-results" class="share-user-results" style="display: none;"></div>
						</div>
					</div>

					<div class="share-list">
						<h4>Users with access to my budget</h4>
						<div id="owned-shares-list">
							${this.renderOwnedShares()}
						</div>
					</div>
				</div>

				<div class="share-section">
					<h3>Budgets Shared With Me</h3>
					<p class="share-description">
						View budgets that others have shared with you. You have read-only access.
					</p>

					<div class="share-list">
						<div id="received-shares-list">
							${this.renderReceivedShares()}
						</div>
					</div>
				</div>
			</div>
		`;

		this.attachEventListeners(container);
	}

	/**
	 * Render owned shares (users I've shared my budget with)
	 */
	renderOwnedShares() {
		if (this.shares.owned.length === 0) {
			return '<p class="empty-message">You haven\'t shared your budget with anyone yet.</p>';
		}

		return `
			<table class="share-table">
				<thead>
					<tr>
						<th>User</th>
						<th>Email</th>
						<th>Permission</th>
						<th>Shared On</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					${this.shares.owned.map(share => `
						<tr data-share-id="${share.id}">
							<td>${this.escapeHtml(share.sharedWithDisplayName || share.sharedWithUserId)}</td>
							<td>${share.sharedWithEmail ? this.escapeHtml(share.sharedWithEmail) : '-'}</td>
							<td><span class="badge badge-${share.permissionLevel}">${share.permissionLevel}</span></td>
							<td>${this.formatDate(share.createdAt)}</td>
							<td>
								<button
									class="btn btn-sm btn-danger revoke-share-btn"
									data-share-id="${share.id}"
									data-user-name="${this.escapeHtml(share.sharedWithDisplayName || share.sharedWithUserId)}"
								>
									Revoke
								</button>
							</td>
						</tr>
					`).join('')}
				</tbody>
			</table>
		`;
	}

	/**
	 * Render received shares (budgets shared with me)
	 */
	renderReceivedShares() {
		if (this.shares.received.length === 0) {
			return '<p class="empty-message">No budgets have been shared with you yet.</p>';
		}

		return `
			<table class="share-table">
				<thead>
					<tr>
						<th>Owner</th>
						<th>Email</th>
						<th>Permission</th>
						<th>Shared On</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					${this.shares.received.map(share => `
						<tr data-share-id="${share.id}">
							<td>${this.escapeHtml(share.ownerDisplayName || share.ownerUserId)}</td>
							<td>${share.ownerEmail ? this.escapeHtml(share.ownerEmail) : '-'}</td>
							<td><span class="badge badge-${share.permissionLevel}">${share.permissionLevel}</span></td>
							<td>${this.formatDate(share.createdAt)}</td>
							<td>
								<button
									class="btn btn-sm btn-primary view-shared-budget-btn"
									data-owner-user-id="${share.ownerUserId}"
									data-owner-display-name="${this.escapeHtml(share.ownerDisplayName || share.ownerUserId)}"
								>
									View Budget
								</button>
							</td>
						</tr>
					`).join('')}
				</tbody>
			</table>
		`;
	}

	/**
	 * Attach event listeners
	 */
	attachEventListeners(container) {
		// User search input
		const searchInput = container.querySelector('#share-user-search');
		if (searchInput) {
			searchInput.addEventListener('input', (e) => this.handleUserSearch(e.target.value));
			searchInput.addEventListener('focus', () => {
				if (this.searchResults.length > 0) {
					container.querySelector('#share-user-results').style.display = 'block';
				}
			});
		}

		// Revoke share buttons
		container.querySelectorAll('.revoke-share-btn').forEach(btn => {
			btn.addEventListener('click', (e) => {
				const shareId = parseInt(e.target.dataset.shareId);
				const userName = e.target.dataset.userName;
				this.handleRevokeShare(shareId, userName);
			});
		});

		// View shared budget buttons
		container.querySelectorAll('.view-shared-budget-btn').forEach(btn => {
			btn.addEventListener('click', (e) => {
				const ownerUserId = e.target.dataset.ownerUserId;
				const ownerDisplayName = e.target.dataset.ownerDisplayName;
				this.handleViewSharedBudget(ownerUserId, ownerDisplayName);
			});
		});

		// Click outside to close search results
		document.addEventListener('click', (e) => {
			const resultsDiv = container.querySelector('#share-user-results');
			if (resultsDiv && !e.target.closest('.share-new-form')) {
				resultsDiv.style.display = 'none';
			}
		});
	}

	/**
	 * Handle user search with debouncing
	 */
	async handleUserSearch(query) {
		clearTimeout(this.searchTimeout);

		if (!query || query.length < 2) {
			this.searchResults = [];
			document.querySelector('#share-user-results').style.display = 'none';
			return;
		}

		this.searchTimeout = setTimeout(async () => {
			try {
				const response = await this.apiClient.get('/shares/users/search', { query });
				this.searchResults = response.data || [];
				this.renderSearchResults();
			} catch (error) {
				console.error('[ShareModule] User search failed:', error);
				this.searchResults = [];
			}
		}, 300);
	}

	/**
	 * Render user search results
	 */
	renderSearchResults() {
		const resultsDiv = document.querySelector('#share-user-results');
		if (!resultsDiv) return;

		if (this.searchResults.length === 0) {
			resultsDiv.innerHTML = '<div class="search-no-results">No users found</div>';
			resultsDiv.style.display = 'block';
			return;
		}

		resultsDiv.innerHTML = this.searchResults.map(user => `
			<div class="search-result-item" data-user-id="${user.userId}">
				<div class="search-result-name">${this.escapeHtml(user.displayName)}</div>
				<div class="search-result-email">${user.email ? this.escapeHtml(user.email) : user.userId}</div>
			</div>
		`).join('');

		// Attach click handlers to results
		resultsDiv.querySelectorAll('.search-result-item').forEach(item => {
			item.addEventListener('click', () => {
				const userId = item.dataset.userId;
				const user = this.searchResults.find(u => u.userId === userId);
				this.handleShareWithUser(user);
			});
		});

		resultsDiv.style.display = 'block';
	}

	/**
	 * Handle sharing budget with a user
	 */
	async handleShareWithUser(user) {
		if (!confirm(`Share your budget with ${user.displayName}?\n\nThey will have read-only access to view all your financial data.`)) {
			return;
		}

		try {
			await this.apiClient.post('/shares', {
				sharedWithUserId: user.userId,
				permissionLevel: 'read'
			});

			this.app.showNotification(`Budget shared with ${user.displayName}`, 'success');

			// Clear search
			document.querySelector('#share-user-search').value = '';
			document.querySelector('#share-user-results').style.display = 'none';
			this.searchResults = [];

			// Reload and re-render
			await this.loadShares();
			const container = document.querySelector('.share-module');
			if (container) {
				this.render(container.parentElement);
			}
		} catch (error) {
			console.error('[ShareModule] Failed to share budget:', error);

			let errorMsg = 'Failed to share budget';
			if (error.response?.data?.error) {
				errorMsg = error.response.data.error;
			}

			this.app.showNotification(errorMsg, 'error');
		}
	}

	/**
	 * Handle revoking a share
	 */
	async handleRevokeShare(shareId, userName) {
		if (!confirm(`Revoke access for ${userName}?\n\nThey will no longer be able to view your budget.`)) {
			return;
		}

		try {
			await this.apiClient.delete(`/shares/${shareId}`);

			this.app.showNotification(`Access revoked for ${userName}`, 'success');

			// Reload and re-render
			await this.loadShares();
			const container = document.querySelector('.share-module');
			if (container) {
				this.render(container.parentElement);
			}
		} catch (error) {
			console.error('[ShareModule] Failed to revoke share:', error);
			this.app.showNotification('Failed to revoke access', 'error');
		}
	}

	/**
	 * Handle viewing a shared budget
	 */
	async handleViewSharedBudget(ownerUserId, ownerDisplayName) {
		console.log('[ShareModule] Switching to shared budget:', ownerUserId, ownerDisplayName);

		// Check if shared budget requires password unlock
		const authStatus = await this.app.authModule.checkSharedBudgetAuth(ownerUserId);

		if (authStatus.requiresPassword) {
			// Show password modal
			this.app.authModule.showSharedBudgetPasswordModal(ownerUserId, ownerDisplayName, async () => {
				await this.switchToSharedBudget(ownerUserId, ownerDisplayName);
			});
		} else {
			// No password required, switch directly
			await this.switchToSharedBudget(ownerUserId, ownerDisplayName);
		}
	}

	/**
	 * Switch to viewing a shared budget
	 */
	async switchToSharedBudget(ownerUserId, ownerDisplayName) {
		try {
			// Set current owner context
			this.app.authModule.currentOwnerUserId = ownerUserId;

			// Update budget switcher
			this.updateBudgetSwitcher();

			// Show notification
			this.app.showNotification(`Viewing ${ownerDisplayName}'s budget`, 'success');

			// Reload data for the shared budget
			await this.app.loadInitialData();

			// Navigate to dashboard
			this.app.showView('dashboard');
		} catch (error) {
			console.error('[ShareModule] Failed to switch to shared budget:', error);
			this.app.showNotification('Failed to load shared budget', 'error');

			// Reset owner context on error
			this.app.authModule.currentOwnerUserId = null;
			this.updateBudgetSwitcher();
		}
	}

	/**
	 * Switch back to viewing own budget
	 */
	async switchToOwnBudget() {
		try {
			// Clear current owner context
			this.app.authModule.currentOwnerUserId = null;

			// Update budget switcher
			this.updateBudgetSwitcher();

			// Show notification
			this.app.showNotification('Viewing your budget', 'success');

			// Reload own data
			await this.app.loadInitialData();

			// Navigate to dashboard
			this.app.showView('dashboard');
		} catch (error) {
			console.error('[ShareModule] Failed to switch to own budget:', error);
			this.app.showNotification('Failed to load your budget', 'error');
		}
	}

	/**
	 * Utility: Escape HTML to prevent XSS
	 */
	escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	/**
	 * Utility: Format date
	 */
	formatDate(dateString) {
		if (!dateString) return '-';
		const date = new Date(dateString);
		return date.toLocaleDateString();
	}
}
