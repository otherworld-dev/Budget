<?php
script('budget', 'budget-main');
style('budget', 'style');
?>

<div id="app-navigation">
    <!-- Search Bar -->
    <div class="app-navigation-search">
        <div class="app-navigation-search-wrapper">
            <input type="text"
                   id="app-navigation-search-input"
                   class="app-navigation-search-input"
                   placeholder="Search here ..."
                   aria-label="Search navigation">
            <span class="app-navigation-search-icon icon-search" aria-hidden="true"></span>
            <button type="button"
                    id="app-navigation-search-clear"
                    class="app-navigation-search-clear icon-close"
                    aria-label="Clear search"
                    style="display: none;"></button>
        </div>
    </div>

    <ul>
        <li class="app-navigation-entry active" data-id="dashboard">
            <a href="#dashboard" class="nav-icon-dashboard svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/>
                    </svg>
                </span>
                Dashboard
            </a>
        </li>
        <li class="app-navigation-entry" data-id="accounts">
            <a href="#accounts" class="nav-icon-folder svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M10 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2h-8l-2-2z"/>
                    </svg>
                </span>
                Accounts
            </a>
        </li>
        <li class="app-navigation-entry" data-id="transactions">
            <a href="#transactions" class="nav-icon-activity svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                    </svg>
                </span>
                Transactions
            </a>
        </li>
        <li class="app-navigation-entry" data-id="categories">
            <a href="#categories" class="nav-icon-tag svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.63 5.84C17.27 5.33 16.67 5 16 5L5 5.01C3.9 5.01 3 5.9 3 7v10c0 1.1.9 1.99 2 1.99L16 19c.67 0 1.27-.33 1.63-.84L22 12l-4.37-6.16z"/>
                    </svg>
                </span>
                Categories
            </a>
        </li>
        <li class="app-navigation-entry" data-id="budget">
            <a href="#budget" class="nav-icon-budget svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16z"/>
                    </svg>
                </span>
                Budget
            </a>
        </li>
        <li class="app-navigation-entry" data-id="import">
            <a href="#import" class="nav-icon-upload svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                    </svg>
                </span>
                Import
            </a>
        </li>
        <li class="app-navigation-entry" data-id="bills">
            <a href="#bills" class="nav-icon-bills svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19,14V6c0-1.1-0.9-2-2-2H3C1.9,4,1,4.9,1,6v8c0,1.1,0.9,2,2,2h14C18.1,16,19,15.1,19,14z M17,14H3V6h14V14z M10,7 c-1.66,0-3,1.34-3,3s1.34,3,3,3s3-1.34,3-3S11.66,7,10,7z M23,7v11c0,1.1-0.9,2-2,2H4v-2h17V7H23z"/>
                    </svg>
                </span>
                Bills
            </a>
        </li>
        <li class="app-navigation-entry" data-id="savings-goals">
            <a href="#savings-goals" class="nav-icon-savings svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12,3C7.58,3 4,4.79 4,7C4,9.21 7.58,11 12,11C16.42,11 20,9.21 20,7C20,4.79 16.42,3 12,3M4,9V12C4,14.21 7.58,16 12,16C16.42,16 20,14.21 20,12V9C20,11.21 16.42,13 12,13C7.58,13 4,11.21 4,9M4,14V17C4,19.21 7.58,21 12,21C16.42,21 20,19.21 20,17V14C20,16.21 16.42,18 12,18C7.58,18 4,16.21 4,14Z"/>
                    </svg>
                </span>
                Savings Goals
            </a>
        </li>
        <li class="app-navigation-entry" data-id="forecast">
            <a href="#forecast" class="nav-icon-trending svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M16,6L18.29,8.29L13.41,13.17L9.41,9.17L2,16.59L3.41,18L9.41,12L13.41,16L19.71,9.71L22,12V6H16Z"/>
                    </svg>
                </span>
                Forecast
            </a>
        </li>
        <li class="app-navigation-entry" data-id="reports">
            <a href="#reports" class="nav-icon-clipboard svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19,3H14.82C14.4,1.84 13.3,1 12,1C10.7,1 9.6,1.84 9.18,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M12,3A1,1 0 0,1 13,4A1,1 0 0,1 12,5A1,1 0 0,1 11,4A1,1 0 0,1 12,3"/>
                    </svg>
                </span>
                Reports
            </a>
        </li>
        <li class="app-navigation-entry" data-id="settings">
            <a href="#settings" class="nav-icon-settings svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12,15.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.22,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.68 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z"/>
                    </svg>
                </span>
                Settings
            </a>
        </li>
    </ul>
</div>

<div id="app-content" class="app-content">
    <div id="app-navigation-toggle" class="icon-menu" style="display: none;"></div>
    <div id="app-content-wrapper">
        <!-- Dashboard View -->
        <div id="dashboard-view" class="view active">
            <!-- Hero Section - Key Financial Metrics -->
            <div class="dashboard-hero">
                <div class="hero-card hero-net-worth">
                    <div class="hero-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label">Net Worth</span>
                        <span id="hero-net-worth-value" class="hero-value">--</span>
                        <span id="hero-net-worth-change" class="hero-change"></span>
                    </div>
                </div>

                <div class="hero-card hero-income">
                    <div class="hero-icon income">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label">Income This Month</span>
                        <span id="hero-income-value" class="hero-value income">--</span>
                        <span id="hero-income-change" class="hero-change"></span>
                    </div>
                </div>

                <div class="hero-card hero-expenses">
                    <div class="hero-icon expenses">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M16 18l2.29-2.29-4.88-4.88-4 4L2 7.41 3.41 6l6 6 4-4 6.3 6.29L22 12v6z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label">Expenses This Month</span>
                        <span id="hero-expenses-value" class="hero-value expenses">--</span>
                        <span id="hero-expenses-change" class="hero-change"></span>
                    </div>
                </div>

                <div class="hero-card hero-savings">
                    <div class="hero-icon savings">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19.5 3.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2 4.5 3.5 3 2v20l1.5-1.5L6 22l1.5-1.5L9 22l1.5-1.5L12 22l1.5-1.5L15 22l1.5-1.5L18 22l1.5-1.5L21 22V2l-1.5 1.5zM19 19.09H5V4.91h14v14.18zM6 15h12v2H6zm0-4h12v2H6zm0-4h12v2H6z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label">Net Savings</span>
                        <span id="hero-savings-value" class="hero-value">--</span>
                        <span id="hero-savings-rate" class="hero-subtext"></span>
                    </div>
                </div>
            </div>

            <!-- Main Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Left Column -->
                <div class="dashboard-column dashboard-column-main">
                    <!-- Income vs Expenses Chart -->
                    <div class="dashboard-card dashboard-card-large">
                        <div class="card-header">
                            <h3>Income vs Expenses</h3>
                            <div class="card-header-controls">
                                <select id="trend-period-select" class="card-select">
                                    <option value="6">Last 6 Months</option>
                                    <option value="12">Last 12 Months</option>
                                    <option value="3">Last 3 Months</option>
                                </select>
                            </div>
                        </div>
                        <div class="chart-container chart-container-large">
                            <canvas id="trend-chart"></canvas>
                        </div>
                        <div id="trend-chart-legend" class="chart-legend"></div>
                    </div>

                    <!-- Spending by Category Chart -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3>Spending by Category</h3>
                            <div class="card-header-controls">
                                <select id="spending-period-select" class="card-select">
                                    <option value="month">This Month</option>
                                    <option value="3months">Last 3 Months</option>
                                    <option value="year">This Year</option>
                                </select>
                            </div>
                        </div>
                        <div class="spending-chart-wrapper">
                            <div class="chart-container chart-container-doughnut">
                                <canvas id="spending-chart"></canvas>
                            </div>
                            <div id="spending-chart-legend" class="spending-legend"></div>
                        </div>
                    </div>

                    <!-- Recent Transactions -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3>Recent Transactions</h3>
                            <a href="#transactions" class="card-link">View All</a>
                        </div>
                        <div id="recent-transactions" class="recent-transactions-list"></div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="dashboard-column dashboard-column-side">
                    <!-- Account Balances -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3>Accounts</h3>
                            <a href="#accounts" class="card-link">Manage</a>
                        </div>
                        <div id="accounts-summary" class="accounts-widget"></div>
                    </div>

                    <!-- Upcoming Bills -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3>Upcoming Bills</h3>
                            <a href="#bills" class="card-link">View All</a>
                        </div>
                        <div id="upcoming-bills" class="bills-widget">
                            <div class="empty-state-small">No upcoming bills</div>
                        </div>
                    </div>

                    <!-- Budget Progress -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3>Budget Progress</h3>
                            <a href="#budget" class="card-link">Details</a>
                        </div>
                        <div id="budget-progress" class="budget-widget">
                            <div class="empty-state-small">No budgets configured</div>
                        </div>
                    </div>

                    <!-- Savings Goals -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3>Savings Goals</h3>
                            <a href="#savings-goals" class="card-link">Manage</a>
                        </div>
                        <div id="savings-goals-summary" class="savings-goals-widget">
                            <div class="empty-state-small">No savings goals yet</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Accounts View -->
        <div id="accounts-view" class="view">
            <div class="view-header">
                <h2>Accounts</h2>
                <button id="add-account-btn" class="primary" aria-label="Add new account">
                    <span class="icon-add" aria-hidden="true"></span>
                    Add Account
                </button>
            </div>

            <!-- Account Summary Cards -->
            <div class="accounts-summary-header">
                <div class="summary-card summary-card-assets">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M5 6h14v2H5zm0 4h14v2H5zm0 4h14v2H5zm0 4h14v2H5z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <span class="summary-label">Total Assets</span>
                        <span id="summary-total-assets" class="summary-value">--</span>
                    </div>
                </div>
                <div class="summary-card summary-card-liabilities">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <span class="summary-label">Total Liabilities</span>
                        <span id="summary-total-liabilities" class="summary-value">--</span>
                    </div>
                </div>
                <div class="summary-card summary-card-networth">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <span class="summary-label">Net Worth</span>
                        <span id="summary-net-worth" class="summary-value">--</span>
                    </div>
                </div>
            </div>

            <!-- Assets Section -->
            <div id="accounts-assets-section" class="accounts-section">
                <div class="section-header-row">
                    <h3 class="section-title">Assets</h3>
                    <span id="assets-subtotal" class="section-subtotal">--</span>
                </div>
                <div id="accounts-assets-grid" class="accounts-grid"></div>
            </div>

            <!-- Liabilities Section -->
            <div id="accounts-liabilities-section" class="accounts-section">
                <div class="section-header-row">
                    <h3 class="section-title">Liabilities</h3>
                    <span id="liabilities-subtotal" class="section-subtotal">--</span>
                </div>
                <div id="accounts-liabilities-grid" class="accounts-grid"></div>
            </div>
        </div>

        <!-- Account Details View -->
        <div id="account-details-view" class="view" style="display: none;">
            <div class="view-header">
                <div class="breadcrumb">
                    <button id="back-to-accounts-btn" class="breadcrumb-back">
                        <span class="icon-arrow-left" aria-hidden="true"></span>
                        Accounts
                    </button>
                    <span class="breadcrumb-separator">/</span>
                    <h2 id="account-details-title">Account Details</h2>
                </div>
                <div class="view-controls">
                    <button id="edit-account-btn" class="secondary" title="Edit account">
                        <span class="icon-rename" aria-hidden="true"></span>
                        Edit
                    </button>
                    <button id="reconcile-account-btn" class="secondary" title="Reconcile account">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                        Reconcile
                    </button>
                    <button id="account-menu-btn" class="secondary" title="More actions">
                        <span class="icon-more" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <div class="account-details-container">
                <!-- Account Overview -->
                <div class="account-overview-section">
                    <div class="account-overview-card">
                        <div class="account-header">
                            <div class="account-icon-container">
                                <span id="account-type-icon" class="account-type-icon" aria-hidden="true"></span>
                            </div>
                            <div class="account-info">
                                <h3 id="account-display-name">Account Name</h3>
                                <div class="account-meta">
                                    <span id="account-type-label" class="account-type">Account Type</span>
                                    <span id="account-institution" class="account-institution"></span>
                                </div>
                            </div>
                            <div class="account-status">
                                <div id="account-health-indicator" class="health-indicator"></div>
                            </div>
                        </div>

                        <div class="account-balance-section">
                            <div class="balance-primary">
                                <label>Current Balance</label>
                                <div id="account-current-balance" class="balance-amount"></div>
                            </div>
                            <div class="balance-secondary">
                                <div class="balance-item">
                                    <label>Available Balance</label>
                                    <div id="account-available-balance" class="balance-amount"></div>
                                </div>
                                <div class="balance-item" id="credit-info" style="display: none;">
                                    <label>Credit Limit</label>
                                    <div id="account-credit-limit" class="balance-amount"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Metrics -->
                    <div class="account-metrics-grid">
                        <div class="metric-card">
                            <div class="metric-icon">
                                <span class="icon-category-integration" aria-hidden="true"></span>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="total-transactions">0</div>
                                <div class="metric-label">Total Transactions</div>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-icon">
                                <span class="icon-add" style="color: var(--color-success);" aria-hidden="true"></span>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="total-income">$0</div>
                                <div class="metric-label">This Month Income</div>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-icon">
                                <span class="icon-close" style="color: var(--color-error);" aria-hidden="true"></span>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="total-expenses">$0</div>
                                <div class="metric-label">This Month Expenses</div>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-icon">
                                <span class="icon-activity" aria-hidden="true"></span>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="avg-transaction">$0</div>
                                <div class="metric-label">Avg. Transaction</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account Details Information -->
                <div class="account-details-section">
                    <h3>Account Information</h3>
                    <div class="details-grid">
                        <div class="detail-item">
                            <label>Account Number</label>
                            <span id="account-number">Not provided</span>
                        </div>
                        <div class="detail-item">
                            <label>Routing Number</label>
                            <span id="routing-number">Not provided</span>
                        </div>
                        <div class="detail-item">
                            <label>IBAN</label>
                            <span id="account-iban">Not provided</span>
                        </div>
                        <div class="detail-item">
                            <label>Sort Code</label>
                            <span id="sort-code">Not provided</span>
                        </div>
                        <div class="detail-item">
                            <label>SWIFT/BIC</label>
                            <span id="swift-bic">Not provided</span>
                        </div>
                        <div class="detail-item">
                            <label>Currency</label>
                            <span id="account-display-currency">USD</span>
                        </div>
                        <div class="detail-item">
                            <label>Opened Date</label>
                            <span id="account-opened">Not provided</span>
                        </div>
                        <div class="detail-item">
                            <label>Last Reconciled</label>
                            <span id="last-reconciled">Never</span>
                        </div>
                    </div>
                </div>

                <!-- Transaction History -->
                <div class="account-transactions-section">
                    <div class="section-header">
                        <h3>Transaction History</h3>
                        <div class="section-controls">
                            <button id="account-add-transaction-btn" class="primary">
                                <span class="icon-add" aria-hidden="true"></span>
                                Add Transaction
                            </button>
                            <button id="account-import-btn" class="secondary">
                                <span class="icon-upload" aria-hidden="true"></span>
                                Import
                            </button>
                            <button id="account-export-btn" class="secondary">
                                <span class="icon-download" aria-hidden="true"></span>
                                Export
                            </button>
                        </div>
                    </div>

                    <!-- Account Transaction Filters -->
                    <div id="account-transaction-filters" class="filters-panel">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label for="account-filter-category">Category</label>
                                <select id="account-filter-category">
                                    <option value="">All Categories</option>
                                    <option value="uncategorized">Uncategorized</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="account-filter-type">Type</label>
                                <select id="account-filter-type">
                                    <option value="">All Types</option>
                                    <option value="credit">Income</option>
                                    <option value="debit">Expense</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label for="account-filter-date-from">From Date</label>
                                <input type="date" id="account-filter-date-from">
                            </div>
                            <div class="filter-group">
                                <label for="account-filter-date-to">To Date</label>
                                <input type="date" id="account-filter-date-to">
                            </div>
                            <div class="filter-group">
                                <label for="account-filter-amount-min">Min Amount</label>
                                <input type="number" id="account-filter-amount-min" step="0.01" placeholder="0.00">
                            </div>
                            <div class="filter-group">
                                <label for="account-filter-amount-max">Max Amount</label>
                                <input type="number" id="account-filter-amount-max" step="0.01" placeholder="1000.00">
                            </div>
                            <div class="filter-group">
                                <label for="account-filter-search">Search</label>
                                <input type="text" id="account-filter-search" placeholder="Description, vendor...">
                            </div>
                        </div>
                        <div class="filters-actions">
                            <button id="account-apply-filters-btn" class="primary">Apply Filters</button>
                            <button id="account-clear-filters-btn" class="secondary">Clear All</button>
                        </div>
                    </div>

                    <!-- Account Transactions Table -->
                    <div class="transactions-container">
                        <table id="account-transactions-table" class="transactions-table">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="date">
                                        Date <span class="sort-indicator"></span>
                                    </th>
                                    <th class="sortable" data-sort="description">
                                        Description <span class="sort-indicator"></span>
                                    </th>
                                    <th>Category</th>
                                    <th class="sortable" data-sort="amount">
                                        Amount <span class="sort-indicator"></span>
                                    </th>
                                    <th>Balance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="account-transactions-body"></tbody>
                        </table>

                        <!-- Pagination -->
                        <div id="account-transactions-pagination" class="pagination">
                            <button id="account-prev-page" class="pagination-btn" disabled>
                                <span class="icon-arrow-left" aria-hidden="true"></span>
                                Previous
                            </button>
                            <div class="page-info">
                                <span id="account-page-info">Page 1 of 1</span>
                            </div>
                            <button id="account-next-page" class="pagination-btn" disabled>
                                Next
                                <span class="icon-arrow-right" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transactions View -->
        <div id="transactions-view" class="view">
            <div class="view-header">
                <h2>Transactions</h2>
                <div class="view-controls">
                    <button id="toggle-filters-btn" class="secondary" title="Toggle advanced filters">
                        <span class="icon-filter" aria-hidden="true"></span>
                        Filters
                    </button>
                    <button id="bulk-actions-btn" class="secondary" title="Bulk actions" disabled>
                        <span class="icon-checkmark" aria-hidden="true"></span>
                        Bulk Actions
                    </button>
                    <button id="reconcile-mode-btn" class="secondary" title="Reconciliation mode">
                        <span class="icon-history" aria-hidden="true"></span>
                        Reconcile
                    </button>
                    <button id="add-transaction-btn" class="primary" aria-label="Add new transaction">
                        <span class="icon-add" aria-hidden="true"></span>
                        Add Transaction
                    </button>
                </div>
            </div>

            <!-- Advanced Filters Panel -->
            <div id="transactions-filters" class="filters-panel" style="display: none;">
                <div class="filters-section">
                    <h3>Filter Transactions</h3>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label for="filter-account">Account</label>
                            <select id="filter-account">
                                <option value="">All Accounts</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filter-category">Category</label>
                            <select id="filter-category">
                                <option value="">All Categories</option>
                                <option value="uncategorized">Uncategorized</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filter-type">Type</label>
                            <select id="filter-type">
                                <option value="">All Types</option>
                                <option value="credit">Income</option>
                                <option value="debit">Expense</option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filter-date-from">From Date</label>
                            <input type="date" id="filter-date-from">
                        </div>

                        <div class="filter-group">
                            <label for="filter-date-to">To Date</label>
                            <input type="date" id="filter-date-to">
                        </div>

                        <div class="filter-group">
                            <label for="filter-amount-min">Min Amount</label>
                            <input type="number" id="filter-amount-min" step="0.01" placeholder="0.00">
                        </div>

                        <div class="filter-group">
                            <label for="filter-amount-max">Max Amount</label>
                            <input type="number" id="filter-amount-max" step="0.01" placeholder="1000.00">
                        </div>

                        <div class="filter-group">
                            <label for="filter-search">Search</label>
                            <input type="text" id="filter-search" placeholder="Description, vendor, reference...">
                        </div>
                    </div>

                    <div class="filters-actions">
                        <button id="apply-filters-btn" class="primary">Apply Filters</button>
                        <button id="clear-filters-btn" class="secondary">Clear All</button>
                        <button id="save-filter-preset-btn" class="secondary">Save Preset</button>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions Panel -->
            <div id="bulk-actions-panel" class="bulk-panel" style="display: none;">
                <div class="bulk-info">
                    <span id="selected-count">0</span> transactions selected
                </div>
                <div class="bulk-actions">
                    <select id="bulk-category-select">
                        <option value="">Select category...</option>
                    </select>
                    <button id="bulk-categorize-btn" class="secondary">Categorize</button>
                    <button id="bulk-delete-btn" class="error">Delete</button>
                    <button id="bulk-export-btn" class="secondary">Export</button>
                    <button id="cancel-bulk-btn" class="secondary">Cancel</button>
                </div>
            </div>

            <!-- Reconciliation Panel -->
            <div id="reconcile-panel" class="reconcile-panel" style="display: none;">
                <div class="reconcile-info">
                    <h3>Account Reconciliation</h3>
                    <div class="reconcile-form">
                        <div class="form-group">
                            <label for="reconcile-account">Account</label>
                            <select id="reconcile-account" required>
                                <option value="">Select account to reconcile</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reconcile-statement-balance">Statement Balance</label>
                            <input type="number" id="reconcile-statement-balance" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label for="reconcile-statement-date">Statement Date</label>
                            <input type="date" id="reconcile-statement-date" required>
                        </div>
                        <button id="start-reconcile-btn" class="primary">Start Reconciliation</button>
                        <button id="cancel-reconcile-btn" class="secondary">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- Transactions Table -->
            <div class="table-container">
                <div class="table-header">
                    <div class="table-info">
                        <span id="transactions-count">0 transactions</span>
                        <span id="transactions-total">Total: $0.00</span>
                    </div>
                    <div class="table-pagination">
                        <select id="rows-per-page">
                            <option value="25">25 per page</option>
                            <option value="50">50 per page</option>
                            <option value="100">100 per page</option>
                            <option value="250">250 per page</option>
                        </select>
                        <div class="pagination-controls">
                            <button id="prev-page-btn" class="secondary" disabled>←</button>
                            <span id="page-info">Page 1 of 1</span>
                            <button id="next-page-btn" class="secondary" disabled>→</button>
                        </div>
                    </div>
                </div>

                <table id="transactions-table" class="transactions-table enhanced">
                    <thead>
                        <tr>
                            <th class="select-column">
                                <input type="checkbox" id="select-all-transactions" title="Select all">
                            </th>
                            <th class="sortable" data-sort="date">
                                Date
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="sortable" data-sort="description">
                                Description
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="sortable" data-sort="category">
                                Category
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="sortable" data-sort="amount">
                                Amount
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="sortable" data-sort="account">
                                Account
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="actions-column">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
        
        <!-- Categories View -->
        <div id="categories-view" class="view">
            <div class="view-header">
                <h2>Categories</h2>
                <div class="view-controls">
                    <div class="categories-tabs">
                        <button class="tab-button active" data-tab="expense">
                            <span class="icon-close" aria-hidden="true"></span>
                            Expenses
                        </button>
                        <button class="tab-button" data-tab="income">
                            <span class="icon-add" aria-hidden="true"></span>
                            Income
                        </button>
                    </div>
                    <div class="categories-actions">
                        <button id="add-category-btn" class="primary" aria-label="Add new category">
                            <span class="icon-add" aria-hidden="true"></span>
                            Add Category
                        </button>
                        <button id="category-settings-btn" class="secondary" title="Category settings">
                            <span class="icon-settings" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="categories-container">
                <!-- Categories Tree Panel -->
                <div class="categories-panel">
                    <div class="categories-toolbar">
                        <div class="search-container">
                            <input type="text" id="categories-search" placeholder="Search categories..." class="search-input">
                            <span class="icon-search search-icon" aria-hidden="true"></span>
                        </div>
                        <div class="view-options">
                            <button id="expand-all-btn" class="icon-button" title="Expand all">
                                <span class="icon-toggle" aria-hidden="true"></span>
                            </button>
                            <button id="collapse-all-btn" class="icon-button" title="Collapse all">
                                <span class="icon-triangle-s" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Bulk Actions Toolbar -->
                    <div id="category-bulk-toolbar" class="category-bulk-toolbar" style="display: none;">
                        <span id="category-bulk-count">0 selected</span>
                        <div class="bulk-actions">
                            <button id="category-select-all-btn" class="secondary small">
                                Select All
                            </button>
                            <button id="category-clear-selection-btn" class="secondary small">
                                Clear
                            </button>
                            <button id="category-bulk-delete-btn" class="error small">
                                <span class="icon-delete" aria-hidden="true"></span>
                                Delete Selected
                            </button>
                        </div>
                    </div>

                    <!-- Categories Tree -->
                    <div class="categories-tree-container">
                        <div id="categories-tree" class="categories-tree sortable-tree"></div>
                        <div class="empty-categories" id="empty-categories" style="display: none;">
                            <div class="empty-content">
                                <span class="icon-tag" aria-hidden="true"></span>
                                <h3>No categories yet</h3>
                                <p>Create your first category to start organizing your transactions, or use our recommended defaults.</p>
                                <div class="empty-buttons">
                                    <button class="primary" id="empty-categories-add-btn">
                                        <span class="icon-add" aria-hidden="true"></span>
                                        Add Category
                                    </button>
                                    <button class="secondary" id="create-default-categories-btn">
                                        <span class="icon-template" aria-hidden="true"></span>
                                        Use Default Categories
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Category Details Panel -->
                <div class="category-details-panel" id="category-details-panel">
                    <div class="category-details-header">
                        <h3 id="category-details-title">Category Details</h3>
                        <div class="category-details-actions">
                            <button id="edit-category-btn" class="secondary" title="Edit category">
                                <span class="icon-rename" aria-hidden="true"></span>
                                Edit
                            </button>
                            <button id="delete-category-btn" class="secondary" title="Delete category">
                                <span class="icon-delete" aria-hidden="true"></span>
                                Delete
                            </button>
                        </div>
                    </div>

                    <div class="category-details-content" id="category-details-content">
                        <!-- Category Overview -->
                        <div class="category-overview">
                            <div class="category-icon-display">
                                <span id="category-display-icon" class="category-icon large" aria-hidden="true"></span>
                            </div>
                            <div class="category-info">
                                <h4 id="category-display-name">Select a category</h4>
                                <div class="category-meta">
                                    <span id="category-display-type" class="category-type-badge"></span>
                                    <span id="category-display-path" class="category-path"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Transactions -->
                        <div class="recent-transactions-section">
                            <h5>Recent Transactions</h5>
                            <div id="category-recent-transactions" class="recent-transactions-list">
                                <div class="empty-state">
                                    <p>No transactions in this category yet.</p>
                                </div>
                            </div>
                            <button id="view-all-transactions-btn" class="secondary full-width">
                                View All Transactions
                            </button>
                        </div>

                        <!-- Category Analytics -->
                        <div class="category-analytics-section">
                            <h5>Analytics</h5>
                            <div class="analytics-grid">
                                <div class="analytics-card">
                                    <div class="analytics-icon">
                                        <span class="icon-category-integration" aria-hidden="true"></span>
                                    </div>
                                    <div class="analytics-content">
                                        <div class="analytics-value" id="total-transactions-count">0</div>
                                        <div class="analytics-label">Total Transactions</div>
                                    </div>
                                </div>
                                <div class="analytics-card">
                                    <div class="analytics-icon">
                                        <span class="icon-activity" aria-hidden="true"></span>
                                    </div>
                                    <div class="analytics-content">
                                        <div class="analytics-value" id="avg-transaction-amount">$0</div>
                                        <div class="analytics-label">Average Amount</div>
                                    </div>
                                </div>
                                <div class="analytics-card">
                                    <div class="analytics-icon">
                                        <span class="icon-trending" aria-hidden="true"></span>
                                    </div>
                                    <div class="analytics-content">
                                        <div class="analytics-value" id="category-trend">—</div>
                                        <div class="analytics-label">Monthly Trend</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Default State -->
                    <div class="category-details-empty" id="category-details-empty">
                        <div class="empty-content">
                            <span class="icon-tag" aria-hidden="true"></span>
                            <h3>Select a category</h3>
                            <p>Choose a category from the tree to view details, budget information, and analytics.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Budget View -->
        <div id="budget-view" class="view">
            <div class="view-header">
                <h2>Budget</h2>
                <div class="view-controls">
                    <div class="budget-period-selector">
                        <label for="budget-month">Period:</label>
                        <select id="budget-month">
                            <!-- Populated dynamically -->
                        </select>
                    </div>
                </div>
            </div>

            <!-- Budget Summary Cards -->
            <div class="budget-summary-cards">
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-quota" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="budget-total-budgeted">$0</div>
                        <div class="summary-label">Total Budgeted</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-close" style="color: var(--color-error);" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="budget-total-spent">$0</div>
                        <div class="summary-label">Total Spent</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-checkmark" style="color: var(--color-success);" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="budget-total-remaining">$0</div>
                        <div class="summary-label">Remaining</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-category-integration" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="budget-categories-count">0</div>
                        <div class="summary-label">Categories with Budget</div>
                    </div>
                </div>
            </div>

            <!-- Budget Type Tabs -->
            <div class="budget-tabs">
                <button class="tab-button active" data-budget-type="expense">
                    <span class="icon-close" aria-hidden="true"></span>
                    Expenses
                </button>
                <button class="tab-button" data-budget-type="income">
                    <span class="icon-add" aria-hidden="true"></span>
                    Income
                </button>
            </div>

            <!-- Budget Tree Container -->
            <div class="budget-container">
                <div class="budget-tree-header">
                    <div class="budget-col-name">Category</div>
                    <div class="budget-col-budget">Budget</div>
                    <div class="budget-col-period">Period</div>
                    <div class="budget-col-spent">Spent</div>
                    <div class="budget-col-remaining">Remaining</div>
                    <div class="budget-col-progress">Progress</div>
                </div>
                <div id="budget-tree" class="budget-tree">
                    <!-- Budget rows rendered dynamically -->
                </div>
                <div class="empty-budget" id="empty-budget" style="display: none;">
                    <div class="empty-content">
                        <span class="icon-quota" aria-hidden="true"></span>
                        <h3>No categories yet</h3>
                        <p>Create categories first to start setting up your budget.</p>
                        <button class="primary" id="empty-budget-go-categories-btn">
                            <span class="icon-tag" aria-hidden="true"></span>
                            Go to Categories
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import View -->
        <div id="import-view" class="view">
            <h2>Import Transactions</h2>

            <!-- Import Navigation Tabs -->
            <div class="import-nav-tabs">
                <button class="import-tab-btn active" data-tab="wizard">Import Wizard</button>
                <button class="import-tab-btn" data-tab="rules">Import Rules</button>
                <button class="import-tab-btn" data-tab="history">Import History</button>
            </div>

            <!-- Import Wizard Tab -->
            <div id="import-wizard-tab" class="import-tab-content active">
                <!-- Wizard Progress Bar -->
                <div class="wizard-progress">
                    <div class="wizard-step active" data-step="1">
                        <div class="step-number">1</div>
                        <div class="step-label">Select File</div>
                    </div>
                    <div class="wizard-step" data-step="2">
                        <div class="step-number">2</div>
                        <div class="step-label">Map Columns</div>
                    </div>
                    <div class="wizard-step" data-step="3">
                        <div class="step-number">3</div>
                        <div class="step-label">Review & Import</div>
                    </div>
                </div>

                <div class="import-wizard">
                    <!-- Step 1: File Selection -->
                    <div class="import-step active" id="import-step-1">
                        <div class="step-header">
                            <h3>Step 1: Select File</h3>
                            <p>Choose your bank statement file (CSV, OFX, or QIF format)</p>
                        </div>

                        <div class="import-dropzone" id="import-dropzone">
                            <span class="icon-upload"></span>
                            <p>Drag and drop your bank statement here</p>
                            <p>or</p>
                            <button id="import-browse-btn" class="secondary" aria-label="Browse for file to import">Browse Files</button>
                            <input type="file" id="import-file-input" accept=".csv,.ofx,.qif" style="display: none;">
                        </div>

                        <div class="file-formats-info">
                            <h4>Supported Formats:</h4>
                            <ul>
                                <li><strong>CSV:</strong> Comma-separated values from any bank</li>
                                <li><strong>OFX:</strong> Open Financial Exchange format</li>
                                <li><strong>QIF:</strong> Quicken Interchange Format</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Step 2: Column Mapping -->
                    <div class="import-step" id="import-step-2" style="display: none;">
                        <div class="step-header">
                            <h3>Step 2: Map Columns</h3>
                            <p>Match your file columns to budget fields</p>
                        </div>

                        <div class="import-file-info">
                            <div class="file-details">
                                <span class="file-name"></span>
                                <span class="file-size"></span>
                                <span class="record-count"></span>
                            </div>
                        </div>

                        <div class="mapping-container">
                            <div class="mapping-fields">
                                <div class="mapping-field required">
                                    <label>Date <span class="required">*</span></label>
                                    <select id="map-date" required>
                                        <option value="">Select column...</option>
                                    </select>
                                </div>
                                <div class="mapping-field required">
                                    <label>Amount <span class="required">*</span></label>
                                    <select id="map-amount" required>
                                        <option value="">Select column...</option>
                                    </select>
                                </div>
                                <div class="mapping-field required">
                                    <label>Description <span class="required">*</span></label>
                                    <select id="map-description" required>
                                        <option value="">Select column...</option>
                                    </select>
                                </div>
                                <div class="mapping-field">
                                    <label>Transaction Type</label>
                                    <select id="map-type">
                                        <option value="">Auto-detect from amount</option>
                                    </select>
                                </div>
                                <div class="mapping-field">
                                    <label>Vendor/Payee</label>
                                    <select id="map-vendor">
                                        <option value="">Select column...</option>
                                    </select>
                                </div>
                                <div class="mapping-field">
                                    <label>Reference/Check Number</label>
                                    <select id="map-reference">
                                        <option value="">Select column...</option>
                                    </select>
                                </div>
                            </div>

                            <div class="preview-data">
                                <h4>Data Preview</h4>
                                <div class="preview-table-container">
                                    <table id="mapping-preview-table">
                                        <thead></thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="mapping-options">
                            <label>
                                <input type="checkbox" id="skip-first-row">
                                Skip first row (headers)
                            </label>
                            <label>
                                <input type="checkbox" id="apply-rules">
                                Apply import rules for categorization
                            </label>
                        </div>
                    </div>

                    <!-- Step 3: Review & Import -->
                    <div class="import-step" id="import-step-3" style="display: none;">
                        <div class="step-header">
                            <h3>Step 3: Review & Import</h3>
                            <p>Review mapped transactions before importing</p>
                        </div>

                        <div class="import-summary">
                            <div class="summary-stats">
                                <div class="stat-item">
                                    <span class="stat-label">Total Transactions:</span>
                                    <span class="stat-value" id="total-transactions">0</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">New Transactions:</span>
                                    <span class="stat-value" id="new-transactions">0</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Duplicates Found:</span>
                                    <span class="stat-value" id="duplicate-transactions">0</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Auto-categorized:</span>
                                    <span class="stat-value" id="categorized-transactions">0</span>
                                </div>
                            </div>

                            <!-- Single account selection (for CSV) -->
                            <div class="account-selection" id="single-account-selection">
                                <label for="import-account">Import to Account:</label>
                                <select id="import-account">
                                    <option value="">Select account...</option>
                                </select>
                            </div>

                            <!-- Multi-account mapping (for OFX/QIF with multiple accounts) -->
                            <div class="account-mapping-section" id="multi-account-mapping" style="display: none;">
                                <h4>Map Source Accounts to Destination Accounts</h4>
                                <p class="mapping-description">Your file contains multiple accounts. Map each source account to a destination account in your budget.</p>
                                <div id="account-mapping-list" class="account-mapping-list">
                                    <!-- Dynamically populated -->
                                </div>
                            </div>
                        </div>

                        <div class="preview-transactions">
                            <div class="preview-controls">
                                <div class="filter-options">
                                    <label>
                                        <input type="checkbox" id="show-duplicates" checked>
                                        Show duplicates
                                    </label>
                                    <label>
                                        <input type="checkbox" id="show-uncategorized" checked>
                                        Show uncategorized
                                    </label>
                                </div>
                                <div class="preview-pagination">
                                    <span id="preview-info">Showing 0 of 0</span>
                                </div>
                            </div>

                            <div class="preview-table-container">
                                <table id="preview-table">
                                    <thead>
                                        <tr>
                                            <th>Import</th>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th>Amount</th>
                                            <th>Category</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Wizard Navigation -->
                    <div class="wizard-navigation">
                        <button id="prev-step-btn" class="secondary" style="display: none;">Previous</button>
                        <button id="next-step-btn" class="primary" disabled>Next</button>
                        <button id="import-btn" class="primary" style="display: none;">Import Transactions</button>
                        <button id="cancel-import-btn" class="secondary">Cancel</button>
                    </div>
                </div>
            </div>

            <!-- Import Rules Tab -->
            <div id="import-rules-tab" class="import-tab-content">
                <div class="rules-header">
                    <h3>Import Rules</h3>
                    <p>Create rules to automatically categorize imported transactions</p>
                    <button id="add-rule-btn" class="primary">Add Rule</button>
                </div>

                <div class="rules-list">
                    <div class="rules-table-container">
                        <table id="rules-table">
                            <thead>
                                <tr>
                                    <th>Priority</th>
                                    <th>Field</th>
                                    <th>Condition</th>
                                    <th>Pattern</th>
                                    <th>Category</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                <div class="rule-tester">
                    <h4>Test Rules</h4>
                    <div class="test-input">
                        <input type="text" id="test-description" placeholder="Enter transaction description to test">
                        <button id="test-rules-btn" class="secondary">Test</button>
                    </div>
                    <div id="test-results"></div>
                </div>
            </div>

            <!-- Import History Tab -->
            <div id="import-history-tab" class="import-tab-content">
                <div class="history-header">
                    <h3>Import History</h3>
                    <p>View and manage previous imports</p>
                </div>

                <div class="history-list">
                    <div class="history-table-container">
                        <table id="history-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>File Name</th>
                                    <th>Account</th>
                                    <th>Transactions</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bills View -->
        <div id="bills-view" class="view">
            <div class="view-header">
                <h2>Recurring Bills</h2>
                <div class="view-controls">
                    <button id="detect-bills-btn" class="secondary" title="Auto-detect recurring bills from transactions">
                        <span class="icon-search" aria-hidden="true"></span>
                        Detect Bills
                    </button>
                    <button id="add-bill-btn" class="primary" aria-label="Add new bill">
                        <span class="icon-add" aria-hidden="true"></span>
                        Add Bill
                    </button>
                </div>
            </div>

            <!-- Bills Summary Cards -->
            <div class="bills-summary">
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-calendar" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="bills-due-count">0</div>
                        <div class="summary-label">Due This Month</div>
                    </div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-icon">
                        <span class="icon-alert" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="bills-overdue-count">0</div>
                        <div class="summary-label">Overdue</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-quota" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="bills-monthly-total">$0</div>
                        <div class="summary-label">Monthly Total</div>
                    </div>
                </div>
                <div class="summary-card success">
                    <div class="summary-icon">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="bills-paid-count">0</div>
                        <div class="summary-label">Paid This Month</div>
                    </div>
                </div>
            </div>

            <!-- Bills Filter Tabs -->
            <div class="bills-tabs">
                <button class="tab-button active" data-filter="all">All Bills</button>
                <button class="tab-button" data-filter="due">Due Soon</button>
                <button class="tab-button" data-filter="overdue">Overdue</button>
                <button class="tab-button" data-filter="paid">Paid</button>
            </div>

            <!-- Bills List -->
            <div class="bills-container">
                <div id="bills-list" class="bills-list">
                    <!-- Bills will be rendered here -->
                </div>

                <div class="empty-bills" id="empty-bills" style="display: none;">
                    <div class="empty-content">
                        <span class="icon-calendar" aria-hidden="true"></span>
                        <h3>No bills yet</h3>
                        <p>Add your recurring bills to track due dates and never miss a payment.</p>
                        <button class="primary" id="empty-bills-add-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            Add Your First Bill
                        </button>
                    </div>
                </div>
            </div>

            <!-- Detected Bills Panel (hidden by default) -->
            <div id="detected-bills-panel" class="detected-bills-panel" style="display: none;">
                <div class="panel-header">
                    <h3>Detected Recurring Transactions</h3>
                    <p>We found these potential recurring bills in your transaction history</p>
                    <button id="close-detected-panel" class="icon-button" title="Close">
                        <span class="icon-close" aria-hidden="true"></span>
                    </button>
                </div>
                <div class="detected-bills-list" id="detected-bills-list">
                    <!-- Detected bills will be rendered here -->
                </div>
                <div class="panel-actions">
                    <button id="add-selected-bills-btn" class="primary">Add Selected Bills</button>
                    <button id="cancel-detected-btn" class="secondary">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Savings Goals View -->
        <div id="savings-goals-view" class="view">
            <div class="view-header">
                <h2>Savings Goals</h2>
                <button id="add-goal-btn" class="primary" aria-label="Add new savings goal">
                    <span class="icon-add" aria-hidden="true"></span>
                    Add Goal
                </button>
            </div>

            <!-- Goals Summary Cards -->
            <div class="goals-summary">
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-star" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="goals-total-count">0</div>
                        <div class="summary-label">Active Goals</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-quota" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="goals-total-saved">$0</div>
                        <div class="summary-label">Total Saved</div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-category-office" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="goals-total-target">$0</div>
                        <div class="summary-label">Total Target</div>
                    </div>
                </div>
                <div class="summary-card success">
                    <div class="summary-icon">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="goals-completed-count">0</div>
                        <div class="summary-label">Completed</div>
                    </div>
                </div>
            </div>

            <!-- Goals List -->
            <div class="goals-container">
                <div id="goals-list" class="goals-list">
                    <!-- Goals will be rendered here -->
                </div>

                <div class="empty-goals" id="empty-goals" style="display: none;">
                    <div class="empty-content">
                        <span class="icon-star" aria-hidden="true" style="font-size: 48px;"></span>
                        <h3>No savings goals yet</h3>
                        <p>Set up savings goals to track your progress towards financial milestones like an emergency fund, vacation, or new car.</p>
                        <button class="primary" id="empty-goals-add-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            Create Your First Goal
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Goal Modal -->
        <div id="goal-modal" class="modal" style="display: none;">
            <div class="modal-content modal-medium">
                <div class="modal-header">
                    <h3 id="goal-modal-title">Add Savings Goal</h3>
                    <button class="modal-close cancel-btn" aria-label="Close">&times;</button>
                </div>
                <form id="goal-form" class="modal-form">
                    <div class="form-group">
                        <label for="goal-name">Goal Name *</label>
                        <input type="text" id="goal-name" name="name" required placeholder="e.g., Emergency Fund, Vacation, New Car">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="goal-target">Target Amount *</label>
                            <input type="number" id="goal-target" name="targetAmount" min="0" step="0.01" required placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label for="goal-current">Current Amount</label>
                            <input type="number" id="goal-current" name="currentAmount" min="0" step="0.01" value="0" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="goal-account">Linked Account (optional)</label>
                            <select id="goal-account" name="accountId">
                                <option value="">No linked account</option>
                                <!-- Account options populated dynamically -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="goal-target-date">Target Date (optional)</label>
                            <input type="date" id="goal-target-date" name="targetDate">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="goal-color">Color</label>
                        <div class="color-picker-row">
                            <input type="color" id="goal-color" name="color" value="#0082c9">
                            <span class="color-preview" id="goal-color-preview"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="goal-notes">Notes</label>
                        <textarea id="goal-notes" name="notes" rows="3" placeholder="Any notes about this goal..."></textarea>
                    </div>

                    <input type="hidden" id="goal-id" name="id" value="">

                    <div class="modal-actions">
                        <button type="button" class="cancel-btn">Cancel</button>
                        <button type="submit" class="primary" id="save-goal-btn">Save Goal</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Money to Goal Modal -->
        <div id="add-to-goal-modal" class="modal" style="display: none;">
            <div class="modal-content modal-small">
                <div class="modal-header">
                    <h3>Add to <span id="add-to-goal-name">Goal</span></h3>
                    <button class="modal-close cancel-btn" aria-label="Close">&times;</button>
                </div>
                <form id="add-to-goal-form" class="modal-form">
                    <div class="form-group">
                        <label for="add-amount">Amount to Add</label>
                        <input type="number" id="add-amount" name="amount" min="0.01" step="0.01" required placeholder="0.00">
                    </div>
                    <input type="hidden" id="add-to-goal-id" name="goalId" value="">
                    <div class="modal-actions">
                        <button type="button" class="cancel-btn">Cancel</button>
                        <button type="submit" class="primary">Add</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Forecast View -->
        <div id="forecast-view" class="view">
            <div class="forecast-header">
                <h2>Financial Forecast</h2>
                <div class="forecast-controls">
                    <label for="forecast-horizon">Forecast Period:</label>
                    <select id="forecast-horizon">
                        <option value="3">3 months</option>
                        <option value="6" selected>6 months</option>
                        <option value="12">12 months</option>
                        <option value="24">24 months</option>
                    </select>
                </div>
            </div>

            <!-- Loading State -->
            <div id="forecast-loading" class="forecast-loading">
                <div class="loading-spinner"></div>
                <p>Calculating forecast...</p>
            </div>

            <!-- Empty State -->
            <div id="forecast-empty" class="forecast-empty" style="display: none;">
                <div class="empty-icon">📊</div>
                <h3>Not Enough Data</h3>
                <p>Add more transactions to generate an accurate forecast. We need at least 1 month of transaction history.</p>
            </div>

            <!-- Balance Overview Cards -->
            <div id="forecast-overview" class="forecast-section" style="display: none;">
                <div class="overview-cards">
                    <div class="overview-card current-balance">
                        <span class="card-label">Current Balance</span>
                        <span class="card-value" id="current-balance">--</span>
                    </div>
                    <div class="overview-card projected-balance">
                        <span class="card-label">Projected Balance</span>
                        <span class="card-value" id="projected-balance">--</span>
                        <span class="card-change" id="balance-change">--</span>
                    </div>
                </div>
            </div>

            <!-- Trends Summary -->
            <div id="forecast-trends" class="forecast-section" style="display: none;">
                <h3>Monthly Trends</h3>
                <div class="trends-grid">
                    <div class="trend-card income">
                        <span class="trend-label">Avg Monthly Income</span>
                        <span class="trend-value" id="avg-income">--</span>
                        <span class="trend-direction" id="income-direction"></span>
                    </div>
                    <div class="trend-card expenses">
                        <span class="trend-label">Avg Monthly Expenses</span>
                        <span class="trend-value" id="avg-expenses">--</span>
                        <span class="trend-direction" id="expense-direction"></span>
                    </div>
                    <div class="trend-card savings">
                        <span class="trend-label">Avg Monthly Savings</span>
                        <span class="trend-value" id="avg-savings">--</span>
                        <span class="trend-direction" id="savings-direction"></span>
                    </div>
                </div>
            </div>

            <!-- Savings Projection (Chart + Numbers) -->
            <div id="forecast-savings" class="forecast-section" style="display: none;">
                <h3>Savings Projection</h3>
                <div class="savings-container">
                    <div class="savings-chart">
                        <canvas id="savings-chart"></canvas>
                    </div>
                    <div class="savings-summary">
                        <div class="savings-stat">
                            <span class="stat-label">Current Monthly Savings</span>
                            <span class="stat-value" id="current-monthly-savings">--</span>
                        </div>
                        <div class="savings-stat">
                            <span class="stat-label">Projected Total Savings</span>
                            <span class="stat-value" id="projected-total-savings">--</span>
                        </div>
                        <div class="savings-stat">
                            <span class="stat-label">Savings Rate</span>
                            <span class="stat-value" id="savings-rate">--</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Balance Projection Chart -->
            <div id="forecast-chart" class="forecast-section" style="display: none;">
                <h3>Balance Projection</h3>
                <div class="chart-container">
                    <canvas id="balance-projection-chart"></canvas>
                </div>
            </div>

            <!-- Category Spending Trends -->
            <div id="forecast-categories" class="forecast-section" style="display: none;">
                <h3>Spending by Category</h3>
                <div id="category-trends-list" class="category-trends"></div>
            </div>

            <!-- Data Quality Indicator -->
            <div id="forecast-quality" class="forecast-section" style="display: none;">
                <div class="quality-indicator">
                    <span class="quality-label">Forecast Confidence:</span>
                    <span class="quality-value" id="forecast-confidence">--</span>
                    <span class="quality-info" id="data-info">--</span>
                </div>
            </div>
        </div>
        
        <!-- Reports View -->
        <div id="reports-view" class="view">
            <div class="view-header">
                <h2>Financial Reports</h2>
                <div class="view-controls">
                    <button id="export-csv-btn" class="secondary" title="Export as CSV">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/></svg>
                        CSV
                    </button>
                    <button id="export-pdf-btn" class="secondary" title="Export as PDF">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/></svg>
                        PDF
                    </button>
                </div>
            </div>

            <!-- Report Controls -->
            <div class="reports-controls">
                <div class="control-group">
                    <label for="report-type">Report Type</label>
                    <select id="report-type" class="report-select">
                        <option value="summary">Summary Dashboard</option>
                        <option value="spending">Spending by Category</option>
                        <option value="cashflow">Cash Flow</option>
                    </select>
                </div>

                <div class="control-group">
                    <label for="report-period-preset">Period</label>
                    <select id="report-period-preset" class="report-select">
                        <option value="this-month">This Month</option>
                        <option value="last-3-months" selected>Last 3 Months</option>
                        <option value="ytd">Year to Date</option>
                        <option value="last-year">Last Year</option>
                        <option value="custom">Custom Range</option>
                    </select>
                </div>

                <div id="custom-date-range" class="control-group custom-range" style="display: none;">
                    <label>Custom Range</label>
                    <div class="date-range-inputs">
                        <input type="date" id="report-start-date" aria-label="Start date">
                        <span class="date-separator">to</span>
                        <input type="date" id="report-end-date" aria-label="End date">
                    </div>
                </div>

                <div class="control-group">
                    <label for="report-account">Account</label>
                    <select id="report-account" class="report-select">
                        <option value="">All Accounts</option>
                    </select>
                </div>

                <button id="generate-report-btn" class="primary">Generate Report</button>
            </div>

            <!-- Report Loading State -->
            <div id="report-loading" class="loading-state" style="display: none;">
                <div class="loading-spinner"></div>
                <p>Generating report...</p>
            </div>

            <!-- Report Content Area -->
            <div id="report-content" class="report-content">
                <!-- Summary Dashboard Report -->
                <div id="report-summary" class="report-section" style="display: none;">
                    <!-- Summary Cards -->
                    <div class="report-summary-cards">
                        <div class="summary-card summary-card-income">
                            <div class="summary-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/>
                                </svg>
                            </div>
                            <div class="summary-content">
                                <span class="summary-label">Total Income</span>
                                <span id="report-total-income" class="summary-value">--</span>
                                <span id="report-income-change" class="summary-change"></span>
                            </div>
                        </div>
                        <div class="summary-card summary-card-expenses">
                            <div class="summary-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M16 18l2.29-2.29-4.88-4.88-4 4L2 7.41 3.41 6l6 6 4-4 6.3 6.29L22 12v6z"/>
                                </svg>
                            </div>
                            <div class="summary-content">
                                <span class="summary-label">Total Expenses</span>
                                <span id="report-total-expenses" class="summary-value">--</span>
                                <span id="report-expenses-change" class="summary-change"></span>
                            </div>
                        </div>
                        <div class="summary-card summary-card-net">
                            <div class="summary-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/>
                                </svg>
                            </div>
                            <div class="summary-content">
                                <span class="summary-label">Net Income</span>
                                <span id="report-net-income" class="summary-value">--</span>
                                <span id="report-net-change" class="summary-change"></span>
                            </div>
                        </div>
                        <div class="summary-card summary-card-savings">
                            <div class="summary-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12,3C7.58,3 4,4.79 4,7C4,9.21 7.58,11 12,11C16.42,11 20,9.21 20,7C20,4.79 16.42,3 12,3M4,9V12C4,14.21 7.58,16 12,16C16.42,16 20,14.21 20,12V9C20,11.21 16.42,13 12,13C7.58,13 4,11.21 4,9M4,14V17C4,19.21 7.58,21 12,21C16.42,21 20,19.21 20,17V14C20,16.21 16.42,18 12,18C7.58,18 4,16.21 4,14Z"/>
                                </svg>
                            </div>
                            <div class="summary-content">
                                <span class="summary-label">Savings Rate</span>
                                <span id="report-savings-rate" class="summary-value">--</span>
                            </div>
                        </div>
                    </div>

                    <!-- Trend Chart -->
                    <div class="dashboard-card dashboard-card-large">
                        <div class="card-header">
                            <h3>Income vs Expenses Trend</h3>
                        </div>
                        <div class="chart-container chart-container-large">
                            <canvas id="report-trend-chart"></canvas>
                        </div>
                    </div>

                    <!-- Account Breakdown Table -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3>Account Breakdown</h3>
                        </div>
                        <div class="table-responsive">
                            <table id="report-accounts-table" class="data-table">
                                <thead>
                                    <tr>
                                        <th>Account</th>
                                        <th class="text-right">Income</th>
                                        <th class="text-right">Expenses</th>
                                        <th class="text-right">Net</th>
                                        <th class="text-right">Balance</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Spending by Category Report -->
                <div id="report-spending" class="report-section" style="display: none;">
                    <div class="report-grid">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3>Spending by Category</h3>
                            </div>
                            <div class="spending-chart-wrapper">
                                <div class="chart-container chart-container-doughnut">
                                    <canvas id="report-spending-chart"></canvas>
                                </div>
                                <div id="report-spending-legend" class="spending-legend"></div>
                            </div>
                        </div>

                        <div class="dashboard-card">
                            <div class="card-header">
                                <h3>Category Breakdown</h3>
                            </div>
                            <div class="table-responsive">
                                <table id="report-categories-table" class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Category</th>
                                            <th class="text-right">Amount</th>
                                            <th class="text-right">% of Total</th>
                                            <th class="text-right">Transactions</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Top Vendors -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3>Top Vendors</h3>
                        </div>
                        <div class="table-responsive">
                            <table id="report-vendors-table" class="data-table">
                                <thead>
                                    <tr>
                                        <th>Vendor</th>
                                        <th class="text-right">Amount</th>
                                        <th class="text-right">Transactions</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Cash Flow Report -->
                <div id="report-cashflow" class="report-section" style="display: none;">
                    <!-- Cash Flow Summary Cards -->
                    <div class="report-summary-cards report-summary-cards-3">
                        <div class="summary-card summary-card-income">
                            <div class="summary-content">
                                <span class="summary-label">Avg Monthly Income</span>
                                <span id="report-avg-income" class="summary-value">--</span>
                            </div>
                        </div>
                        <div class="summary-card summary-card-expenses">
                            <div class="summary-content">
                                <span class="summary-label">Avg Monthly Expenses</span>
                                <span id="report-avg-expenses" class="summary-value">--</span>
                            </div>
                        </div>
                        <div class="summary-card summary-card-net">
                            <div class="summary-content">
                                <span class="summary-label">Avg Monthly Savings</span>
                                <span id="report-avg-net" class="summary-value">--</span>
                            </div>
                        </div>
                    </div>

                    <!-- Cash Flow Chart -->
                    <div class="dashboard-card dashboard-card-large">
                        <div class="card-header">
                            <h3>Monthly Cash Flow</h3>
                        </div>
                        <div class="chart-container chart-container-large">
                            <canvas id="report-cashflow-chart"></canvas>
                        </div>
                    </div>

                    <!-- Cash Flow Table -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3>Monthly Breakdown</h3>
                        </div>
                        <div class="table-responsive">
                            <table id="report-cashflow-table" class="data-table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th class="text-right">Income</th>
                                        <th class="text-right">Expenses</th>
                                        <th class="text-right">Net</th>
                                        <th class="text-right">Cumulative</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings View -->
        <div id="settings-view" class="view">
            <div class="view-header">
                <h2>Settings</h2>
                <div class="view-controls">
                    <button id="reset-settings-btn" class="secondary" title="Reset all settings to defaults">
                        <span class="icon-history" aria-hidden="true"></span>
                        Reset All
                    </button>
                    <button id="save-settings-btn" class="primary" title="Save settings">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                        Save Changes
                    </button>
                </div>
            </div>

            <div class="settings-container">
                <!-- General Settings Section -->
                <div class="settings-section">
                    <h3>General Settings</h3>

                    <div class="settings-group">
                        <div class="setting-item">
                            <label for="setting-default-currency">
                                <strong>Default Currency</strong>
                                <small>Default currency for new accounts and transactions</small>
                            </label>
                            <select id="setting-default-currency" class="setting-input">
                                <option value="GBP">GBP - British Pound (£)</option>
                                <option value="USD">USD - US Dollar ($)</option>
                                <option value="EUR">EUR - Euro (€)</option>
                                <option value="CAD">CAD - Canadian Dollar (C$)</option>
                                <option value="AUD">AUD - Australian Dollar (A$)</option>
                                <option value="JPY">JPY - Japanese Yen (¥)</option>
                                <option value="CHF">CHF - Swiss Franc</option>
                                <option value="CNY">CNY - Chinese Yuan (¥)</option>
                                <option value="INR">INR - Indian Rupee (₹)</option>
                                <option value="MXN">MXN - Mexican Peso ($)</option>
                            </select>
                        </div>

                        <div class="setting-item">
                            <label for="setting-budget-period">
                                <strong>Budget Period</strong>
                                <small>Default period for budget tracking</small>
                            </label>
                            <select id="setting-budget-period" class="setting-input">
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Display Settings Section -->
                <div class="settings-section">
                    <h3>Display Settings</h3>

                    <div class="settings-group">
                        <div class="setting-item">
                            <label for="setting-date-format">
                                <strong>Date Format</strong>
                                <small>How dates are displayed throughout the app</small>
                            </label>
                            <select id="setting-date-format" class="setting-input">
                                <option value="Y-m-d">YYYY-MM-DD (2025-10-12)</option>
                                <option value="m/d/Y">MM/DD/YYYY (10/12/2025)</option>
                                <option value="d/m/Y">DD/MM/YYYY (12/10/2025)</option>
                                <option value="d.m.Y">DD.MM.YYYY (12.10.2025)</option>
                                <option value="M j, Y">Mon D, YYYY (Oct 12, 2025)</option>
                            </select>
                        </div>

                        <div class="setting-item">
                            <label for="setting-first-day-of-week">
                                <strong>First Day of Week</strong>
                                <small>Starting day for calendars and weekly reports</small>
                            </label>
                            <select id="setting-first-day-of-week" class="setting-input">
                                <option value="0">Sunday</option>
                                <option value="1">Monday</option>
                            </select>
                        </div>

                        <div class="setting-item">
                            <label for="setting-theme-preference">
                                <strong>Theme</strong>
                                <small>Color scheme preference</small>
                            </label>
                            <select id="setting-theme-preference" class="setting-input">
                                <option value="system">Follow Nextcloud theme</option>
                                <option value="light">Light theme</option>
                                <option value="dark">Dark theme</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Number Format Settings Section -->
                <div class="settings-section">
                    <h3>Number Format</h3>

                    <div class="settings-group">
                        <div class="setting-item">
                            <label for="setting-number-format-decimals">
                                <strong>Decimal Places</strong>
                                <small>Number of decimal places to display</small>
                            </label>
                            <select id="setting-number-format-decimals" class="setting-input">
                                <option value="0">0 (1234)</option>
                                <option value="2">2 (1234.56)</option>
                                <option value="3">3 (1234.567)</option>
                            </select>
                        </div>

                        <div class="setting-item">
                            <label for="setting-number-format-decimal-sep">
                                <strong>Decimal Separator</strong>
                                <small>Character used for decimal separation</small>
                            </label>
                            <select id="setting-number-format-decimal-sep" class="setting-input">
                                <option value=".">Period (.)</option>
                                <option value=",">,Comma (,)</option>
                            </select>
                        </div>

                        <div class="setting-item">
                            <label for="setting-number-format-thousands-sep">
                                <strong>Thousands Separator</strong>
                                <small>Character used for thousands separation</small>
                            </label>
                            <select id="setting-number-format-thousands-sep" class="setting-input">
                                <option value=",">Comma (,)</option>
                                <option value=".">Period (.)</option>
                                <option value=" ">Space ( )</option>
                                <option value="">None</option>
                            </select>
                        </div>
                    </div>

                    <div class="setting-preview">
                        <strong>Preview:</strong>
                        <span id="number-format-preview">$1,234.56</span>
                    </div>
                </div>

                <!-- Notification Settings Section -->
                <div class="settings-section">
                    <h3>Notifications</h3>

                    <div class="settings-group">
                        <div class="setting-item checkbox-setting">
                            <label>
                                <input type="checkbox" id="setting-notification-budget-alert" class="setting-input">
                                <div>
                                    <strong>Budget Alerts</strong>
                                    <small>Notify when approaching or exceeding budget limits</small>
                                </div>
                            </label>
                        </div>

                        <div class="setting-item checkbox-setting">
                            <label>
                                <input type="checkbox" id="setting-notification-forecast-warning" class="setting-input">
                                <div>
                                    <strong>Forecast Warnings</strong>
                                    <small>Notify about negative cash flow predictions</small>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Import/Export Settings Section -->
                <div class="settings-section">
                    <h3>Import & Export</h3>

                    <div class="settings-group">
                        <div class="setting-item checkbox-setting">
                            <label>
                                <input type="checkbox" id="setting-import-auto-apply-rules" class="setting-input">
                                <div>
                                    <strong>Auto-apply Import Rules</strong>
                                    <small>Automatically categorize transactions when importing</small>
                                </div>
                            </label>
                        </div>

                        <div class="setting-item checkbox-setting">
                            <label>
                                <input type="checkbox" id="setting-import-skip-duplicates" class="setting-input">
                                <div>
                                    <strong>Skip Duplicate Transactions</strong>
                                    <small>Automatically skip duplicate transactions during import</small>
                                </div>
                            </label>
                        </div>

                        <div class="setting-item">
                            <label for="setting-export-default-format">
                                <strong>Default Export Format</strong>
                                <small>Preferred format for data exports</small>
                            </label>
                            <select id="setting-export-default-format" class="setting-input">
                                <option value="csv">CSV</option>
                                <option value="json">JSON</option>
                                <option value="pdf">PDF</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Data Migration Section -->
                <div class="settings-section">
                    <h3>Data Migration</h3>
                    <p class="settings-description">Export all your data for backup or migration to another Nextcloud instance. Import to restore or migrate data.</p>

                    <div class="settings-group">
                        <!-- Export -->
                        <div class="migration-subsection">
                            <h4>Export Data</h4>
                            <p class="migration-info">Download all your accounts, transactions, categories, bills, import rules, and settings as a ZIP file.</p>
                            <div class="migration-warning">
                                <span class="icon-password" aria-hidden="true"></span>
                                <strong>Security Notice:</strong> The export file contains sensitive data including decrypted banking details. Store it securely and delete after use.
                            </div>
                            <button id="migration-export-btn" class="primary">
                                <span class="icon-download" aria-hidden="true"></span>
                                Export All Data
                            </button>
                        </div>

                        <!-- Import -->
                        <div class="migration-subsection">
                            <h4>Import Data</h4>
                            <p class="migration-info">Import data from a previously exported ZIP file. This will <strong>replace all existing data</strong>.</p>
                            <div class="migration-warning warning-danger">
                                <span class="icon-error" aria-hidden="true"></span>
                                <strong>Warning:</strong> Importing will permanently delete all your current data and replace it with the imported data. This cannot be undone.
                            </div>

                            <div id="migration-import-dropzone" class="migration-dropzone">
                                <div class="dropzone-content">
                                    <span class="icon-upload" aria-hidden="true"></span>
                                    <p>Drag and drop your export file here</p>
                                    <p class="dropzone-hint">or</p>
                                    <button type="button" id="migration-browse-btn" class="secondary">Browse Files</button>
                                    <input type="file" id="migration-file-input" accept=".zip" style="display: none;">
                                    <p class="dropzone-formats">Supported format: ZIP (exported from Budget app)</p>
                                </div>
                            </div>

                            <!-- Preview Section (hidden by default) -->
                            <div id="migration-preview" class="migration-preview" style="display: none;">
                                <h5>Import Preview</h5>
                                <div id="migration-preview-content">
                                    <div class="preview-info">
                                        <div class="preview-row">
                                            <span class="preview-label">Export Version:</span>
                                            <span id="preview-version">-</span>
                                        </div>
                                        <div class="preview-row">
                                            <span class="preview-label">Exported At:</span>
                                            <span id="preview-date">-</span>
                                        </div>
                                    </div>
                                    <div class="preview-counts">
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-categories">0</span>
                                            <span class="count-label">Categories</span>
                                        </div>
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-accounts">0</span>
                                            <span class="count-label">Accounts</span>
                                        </div>
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-transactions">0</span>
                                            <span class="count-label">Transactions</span>
                                        </div>
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-bills">0</span>
                                            <span class="count-label">Bills</span>
                                        </div>
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-rules">0</span>
                                            <span class="count-label">Import Rules</span>
                                        </div>
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-settings">0</span>
                                            <span class="count-label">Settings</span>
                                        </div>
                                    </div>
                                    <div id="migration-warnings" class="migration-warnings" style="display: none;"></div>
                                </div>
                                <div class="preview-actions">
                                    <button type="button" id="migration-cancel-btn" class="secondary">Cancel</button>
                                    <button type="button" id="migration-confirm-btn" class="primary danger">
                                        <span class="icon-confirm" aria-hidden="true"></span>
                                        Confirm Import
                                    </button>
                                </div>
                            </div>

                            <!-- Progress Section (hidden by default) -->
                            <div id="migration-progress" class="migration-progress" style="display: none;">
                                <div class="progress-spinner"></div>
                                <p id="migration-progress-text">Processing import...</p>
                            </div>

                            <!-- Result Section (hidden by default) -->
                            <div id="migration-result" class="migration-result" style="display: none;">
                                <div id="migration-result-content"></div>
                                <button type="button" id="migration-done-btn" class="primary">Done</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Settings Actions -->
                <div class="settings-actions">
                    <button id="save-settings-btn-bottom" class="primary">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                        Save Changes
                    </button>
                    <button id="reset-settings-btn-bottom" class="secondary">
                        <span class="icon-history" aria-hidden="true"></span>
                        Reset All to Defaults
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->
<div id="transaction-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="transaction-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="transaction-modal-title">Add/Edit Transaction</h3>
        <form id="transaction-form">
            <input type="hidden" id="transaction-id">
            <div class="form-group">
                <label for="transaction-date">Date</label>
                <input type="date" id="transaction-date" required aria-describedby="transaction-date-help">
                <small id="transaction-date-help" class="form-text">Select the transaction date</small>
            </div>
            <div class="form-group">
                <label for="transaction-account">Account</label>
                <select id="transaction-account" required aria-describedby="transaction-account-help">
                    <option value="">Choose an account</option>
                </select>
                <small id="transaction-account-help" class="form-text">Select which account this transaction belongs to</small>
            </div>
            <div class="form-group">
                <label for="transaction-type">Type</label>
                <select id="transaction-type" required aria-describedby="transaction-type-help">
                    <option value="">Choose transaction type</option>
                    <option value="debit">Expense</option>
                    <option value="credit">Income</option>
                </select>
                <small id="transaction-type-help" class="form-text">Whether this is money coming in or going out</small>
            </div>
            <div class="form-group">
                <label for="transaction-amount">Amount</label>
                <input type="number" id="transaction-amount" step="0.01" required aria-describedby="transaction-amount-help" min="0">
                <small id="transaction-amount-help" class="form-text">Enter the transaction amount (positive number)</small>
            </div>
            <div class="form-group">
                <label for="transaction-description">Description</label>
                <input type="text" id="transaction-description" required aria-describedby="transaction-description-help" maxlength="255">
                <small id="transaction-description-help" class="form-text">Brief description of the transaction</small>
            </div>
            <div class="form-group">
                <label for="transaction-vendor">Vendor</label>
                <input type="text" id="transaction-vendor" aria-describedby="transaction-vendor-help" maxlength="255">
                <small id="transaction-vendor-help" class="form-text">Name of the merchant or person (optional)</small>
            </div>
            <div class="form-group">
                <label for="transaction-category">Category</label>
                <select id="transaction-category" aria-describedby="transaction-category-help">
                    <option value="">No category</option>
                </select>
                <small id="transaction-category-help" class="form-text">Organize this transaction by category (optional)</small>
            </div>
            <div class="form-group">
                <label for="transaction-notes">Notes</label>
                <textarea id="transaction-notes" aria-describedby="transaction-notes-help" maxlength="500" rows="3"></textarea>
                <small id="transaction-notes-help" class="form-text">Additional notes or details (optional)</small>
            </div>
            <div class="modal-buttons">
                <button type="submit" class="primary" aria-label="Save transaction">Save</button>
                <button type="button" class="secondary cancel-btn" aria-label="Cancel and close dialog">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Account Modal -->
<div id="account-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="account-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="account-modal-title">Add/Edit Account</h3>
        <form id="account-form">
            <input type="hidden" id="account-id">

            <!-- Basic Account Information -->
            <div class="form-section">
                <h4>Basic Information</h4>

                <div class="form-group">
                    <label for="account-name">Account Name <span class="required">*</span></label>
                    <input type="text" id="account-name" required aria-describedby="account-name-help" maxlength="255">
                    <small id="account-name-help" class="form-text">Enter a descriptive name for this account</small>
                </div>

                <div class="form-group">
                    <label for="account-type">Account Type <span class="required">*</span></label>
                    <select id="account-type" required aria-describedby="account-type-help">
                        <option value="">Choose account type</option>
                        <option value="checking">Checking Account</option>
                        <option value="savings">Savings Account</option>
                        <option value="credit_card">Credit Card</option>
                        <option value="investment">Investment Account</option>
                        <option value="loan">Loan Account</option>
                        <option value="cash">Cash</option>
                    </select>
                    <small id="account-type-help" class="form-text">Select the type of account</small>
                </div>

                <div class="form-group">
                    <label for="account-balance">Current Balance</label>
                    <input type="number" id="account-balance" step="0.01" aria-describedby="account-balance-help">
                    <small id="account-balance-help" class="form-text">Enter the current account balance</small>
                </div>

                <div class="form-group">
                    <label for="account-currency">Currency</label>
                    <select id="account-currency" aria-describedby="account-currency-help">
                        <option value="GBP">GBP - British Pound</option>
                        <option value="USD">USD - US Dollar</option>
                        <option value="EUR">EUR - Euro</option>
                        <option value="CAD">CAD - Canadian Dollar</option>
                        <option value="AUD">AUD - Australian Dollar</option>
                        <option value="JPY">JPY - Japanese Yen</option>
                    </select>
                    <small id="account-currency-help" class="form-text">Select the account currency</small>
                </div>
            </div>

            <!-- Bank Information -->
            <div class="form-section">
                <h4>Bank Information</h4>

                <div class="form-group">
                    <label for="account-institution">Institution</label>
                    <input type="text" id="account-institution" aria-describedby="account-institution-help" maxlength="255" autocomplete="off">
                    <div id="institution-suggestions" class="autocomplete-dropdown" style="display: none;"></div>
                    <small id="account-institution-help" class="form-text">Bank or financial institution name</small>
                </div>

                <div class="form-group">
                    <label for="account-holder-name">Account Holder Name</label>
                    <input type="text" id="account-holder-name" aria-describedby="account-holder-name-help" maxlength="255">
                    <small id="account-holder-name-help" class="form-text">Name on the account</small>
                </div>

                <div class="form-group">
                    <label for="form-account-number">Account Number</label>
                    <input type="text" id="form-account-number" aria-describedby="form-account-number-help" maxlength="100">
                    <small id="form-account-number-help" class="form-text">Your account number</small>
                </div>

                <div class="form-group">
                    <label for="account-opening-date">Opening Date</label>
                    <input type="date" id="account-opening-date" aria-describedby="account-opening-date-help">
                    <small id="account-opening-date-help" class="form-text">When the account was opened</small>
                </div>
            </div>

            <!-- Banking Details (conditional) -->
            <div class="form-section" id="banking-details-section">
                <h4>Banking Details</h4>

                <div class="form-group conditional" id="routing-number-group">
                    <label for="form-routing-number">Routing Number</label>
                    <input type="text" id="form-routing-number" aria-describedby="form-routing-number-help" maxlength="20">
                    <small id="form-routing-number-help" class="form-text">9-digit routing number (US banks)</small>
                </div>

                <div class="form-group conditional" id="sort-code-group">
                    <label for="form-sort-code">Sort Code</label>
                    <input type="text" id="form-sort-code" aria-describedby="form-sort-code-help" maxlength="10">
                    <small id="form-sort-code-help" class="form-text">6-digit sort code (UK banks)</small>
                </div>

                <div class="form-group conditional" id="iban-group">
                    <label for="form-iban">IBAN</label>
                    <input type="text" id="form-iban" aria-describedby="form-iban-help" maxlength="34">
                    <small id="form-iban-help" class="form-text">International Bank Account Number</small>
                </div>

                <div class="form-group conditional" id="swift-bic-group">
                    <label for="form-swift-bic">SWIFT/BIC Code</label>
                    <input type="text" id="form-swift-bic" aria-describedby="form-swift-bic-help" maxlength="11">
                    <small id="form-swift-bic-help" class="form-text">SWIFT/BIC code for international transfers</small>
                </div>
            </div>

            <!-- Account Limits (conditional) -->
            <div class="form-section" id="limits-section">
                <h4>Account Limits & Rates</h4>

                <div class="form-group conditional" id="interest-rate-group">
                    <label for="account-interest-rate">Interest Rate (%)</label>
                    <input type="number" id="account-interest-rate" step="0.0001" min="0" max="100" aria-describedby="account-interest-rate-help">
                    <small id="account-interest-rate-help" class="form-text">Annual interest rate percentage</small>
                </div>

                <div class="form-group conditional" id="credit-limit-group">
                    <label for="account-credit-limit">Credit Limit</label>
                    <input type="number" id="account-credit-limit" step="0.01" min="0" aria-describedby="account-credit-limit-help">
                    <small id="account-credit-limit-help" class="form-text">Maximum credit limit for credit cards</small>
                </div>

                <div class="form-group conditional" id="overdraft-limit-group">
                    <label for="account-overdraft-limit">Overdraft Limit</label>
                    <input type="number" id="account-overdraft-limit" step="0.01" min="0" aria-describedby="account-overdraft-limit-help">
                    <small id="account-overdraft-limit-help" class="form-text">Maximum overdraft amount</small>
                </div>
            </div>

            <div class="modal-buttons">
                <button type="submit" class="primary" aria-label="Save account">Save</button>
                <button type="button" class="secondary cancel-btn" aria-label="Cancel and close dialog">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Bill Modal -->
<div id="bill-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="bill-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="bill-modal-title">Add/Edit Bill</h3>
        <form id="bill-form">
            <input type="hidden" id="bill-id">

            <div class="form-group">
                <label for="bill-name">Bill Name <span class="required">*</span></label>
                <input type="text" id="bill-name" required aria-describedby="bill-name-help" maxlength="255" placeholder="e.g., Netflix, Rent, Electric Bill">
                <small id="bill-name-help" class="form-text">Name of the recurring bill</small>
            </div>

            <div class="form-group">
                <label for="bill-amount">Amount <span class="required">*</span></label>
                <input type="number" id="bill-amount" step="0.01" required min="0" aria-describedby="bill-amount-help" placeholder="0.00">
                <small id="bill-amount-help" class="form-text">Expected bill amount</small>
            </div>

            <div class="form-group">
                <label for="bill-frequency">Frequency <span class="required">*</span></label>
                <select id="bill-frequency" required aria-describedby="bill-frequency-help">
                    <option value="weekly">Weekly</option>
                    <option value="monthly" selected>Monthly</option>
                    <option value="quarterly">Quarterly</option>
                    <option value="yearly">Yearly</option>
                </select>
                <small id="bill-frequency-help" class="form-text">How often this bill is due</small>
            </div>

            <div class="form-group" id="due-day-group">
                <label for="bill-due-day">Due Day</label>
                <input type="number" id="bill-due-day" min="1" max="31" aria-describedby="bill-due-day-help" placeholder="1-31">
                <small id="bill-due-day-help" class="form-text">Day of the month when bill is due</small>
            </div>

            <div class="form-group" id="due-month-group" style="display: none;">
                <label for="bill-due-month">Due Month</label>
                <select id="bill-due-month" aria-describedby="bill-due-month-help">
                    <option value="">Select month...</option>
                    <option value="1">January</option>
                    <option value="2">February</option>
                    <option value="3">March</option>
                    <option value="4">April</option>
                    <option value="5">May</option>
                    <option value="6">June</option>
                    <option value="7">July</option>
                    <option value="8">August</option>
                    <option value="9">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select>
                <small id="bill-due-month-help" class="form-text">Month when yearly bill is due</small>
            </div>

            <div class="form-group">
                <label for="bill-category">Category</label>
                <select id="bill-category" aria-describedby="bill-category-help">
                    <option value="">No category</option>
                </select>
                <small id="bill-category-help" class="form-text">Categorize this bill (optional)</small>
            </div>

            <div class="form-group">
                <label for="bill-account">Pay From Account</label>
                <select id="bill-account" aria-describedby="bill-account-help">
                    <option value="">No specific account</option>
                </select>
                <small id="bill-account-help" class="form-text">Account used to pay this bill (optional)</small>
            </div>

            <div class="form-group">
                <label for="bill-auto-pattern">Auto-detect Pattern</label>
                <input type="text" id="bill-auto-pattern" aria-describedby="bill-auto-pattern-help" maxlength="255" placeholder="e.g., NETFLIX, SPOTIFY">
                <small id="bill-auto-pattern-help" class="form-text">Pattern to match in transaction descriptions for auto-linking</small>
            </div>

            <div class="form-group">
                <label for="bill-notes">Notes</label>
                <textarea id="bill-notes" aria-describedby="bill-notes-help" maxlength="500" rows="2" placeholder="Additional notes..."></textarea>
                <small id="bill-notes-help" class="form-text">Any additional notes (optional)</small>
            </div>

            <div class="modal-buttons">
                <button type="submit" class="primary" aria-label="Save bill">Save</button>
                <button type="button" class="secondary cancel-btn" aria-label="Cancel and close dialog">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Category Modal -->
<div id="category-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="category-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="category-modal-title">Add/Edit Category</h3>
        <form id="category-form">
            <input type="hidden" id="category-id">

            <div class="form-group">
                <label for="category-name">Category Name <span class="required">*</span></label>
                <input type="text" id="category-name" required aria-describedby="category-name-help" maxlength="255">
                <small id="category-name-help" class="form-text">Name for this category</small>
            </div>

            <div class="form-group">
                <label for="category-type">Type <span class="required">*</span></label>
                <select id="category-type" required aria-describedby="category-type-help">
                    <option value="expense">Expense</option>
                    <option value="income">Income</option>
                </select>
                <small id="category-type-help" class="form-text">Whether this is for income or expenses</small>
            </div>

            <div class="form-group">
                <label for="category-parent">Parent Category</label>
                <select id="category-parent" aria-describedby="category-parent-help">
                    <option value="">None (Top Level)</option>
                </select>
                <small id="category-parent-help" class="form-text">Make this a subcategory (optional)</small>
            </div>

            <div class="form-group">
                <label for="category-color">Color</label>
                <input type="color" id="category-color" value="#3b82f6" aria-describedby="category-color-help">
                <small id="category-color-help" class="form-text">Color for charts and display</small>
            </div>

            <div class="modal-buttons">
                <button type="submit" class="primary" aria-label="Save category">Save</button>
                <button type="button" class="secondary cancel-btn" aria-label="Cancel and close dialog">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Transaction Matching Modal -->
<div id="matching-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="matching-modal-title" aria-hidden="true">
    <div class="modal-content modal-wide">
        <h3 id="matching-modal-title">Find Transfer Matches</h3>
        <div id="matching-source-transaction" class="matching-source">
            <h4>Source Transaction</h4>
            <div class="source-details">
                <span class="source-date"></span>
                <span class="source-description"></span>
                <span class="source-amount"></span>
                <span class="source-account"></span>
            </div>
        </div>
        <div id="matching-results" class="matching-results">
            <div id="matching-loading" class="matching-loading" style="display: none;">
                <div class="loading-spinner"></div>
                <p>Searching for matches...</p>
            </div>
            <div id="matching-empty" class="matching-empty" style="display: none;">
                <p>No matching transactions found within the date range.</p>
                <p class="hint">Matches must have the same amount, opposite type (income/expense), and be within 3 days.</p>
            </div>
            <div id="matching-list" class="matching-list"></div>
        </div>
        <div class="modal-buttons">
            <button type="button" class="secondary cancel-btn" aria-label="Close dialog">Close</button>
        </div>
    </div>
</div>