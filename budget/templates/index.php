<?php
script('budget', 'budget-main');
style('budget', 'style');
style('budget', 'budget-main');
?>

<div id="budget-nav-toggle-wrapper">
    <button id="budget-nav-toggle" aria-label="<?php p($l->t('Toggle navigation')); ?>">
        <svg id="nav-toggle-icon-open" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
        <svg id="nav-toggle-icon-close" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="display:none"><path d="M3 18h13v-2H3v2zm0-5h10v-2H3v2zm0-7v2h13V6H3zm18 9.59L17.42 12 21 8.41 19.59 7l-5 5 5 5L21 15.59z"/></svg>
    </button>
</div>
<div id="app-navigation">
    <!-- Search Bar -->
    <div class="app-navigation-search">
        <div class="app-navigation-search-wrapper">
            <input type="text"
                   id="app-navigation-search-input"
                   class="app-navigation-search-input"
                   placeholder="<?php p($l->t('Search here ...')); ?>"
                   aria-label="<?php p($l->t('Search navigation')); ?>">
            <span class="app-navigation-search-icon icon-search" aria-hidden="true"></span>
            <button type="button"
                    id="app-navigation-search-clear"
                    class="app-navigation-search-clear icon-close"
                    aria-label="<?php p($l->t('Clear search')); ?>"
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
                <?php p($l->t('Dashboard')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="accounts">
            <a href="#accounts" class="nav-icon-folder svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M10 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2h-8l-2-2z"/>
                    </svg>
                </span>
                <?php p($l->t('Accounts')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="transactions">
            <a href="#transactions" class="nav-icon-activity svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                    </svg>
                </span>
                <?php p($l->t('Transactions')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="categories">
            <a href="#categories" class="nav-icon-tag svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M17.63 5.84C17.27 5.33 16.67 5 16 5L5 5.01C3.9 5.01 3 5.9 3 7v10c0 1.1.9 1.99 2 1.99L16 19c.67 0 1.27-.33 1.63-.84L22 12l-4.37-6.16z"/>
                    </svg>
                </span>
                <?php p($l->t('Categories')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="tags">
            <a href="#tags" class="nav-icon-tag svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M5.5,7A1.5,1.5 0 0,1 4,5.5A1.5,1.5 0 0,1 5.5,4A1.5,1.5 0 0,1 7,5.5A1.5,1.5 0 0,1 5.5,7M21.41,11.58L12.41,2.58C12.05,2.22 11.55,2 11,2H4C2.89,2 2,2.89 2,4V11C2,11.55 2.22,12.05 2.59,12.42L11.59,21.42C11.95,21.78 12.45,22 13,22C13.55,22 14.05,21.78 14.41,21.41L21.41,14.41C21.78,14.05 22,13.55 22,13C22,12.45 21.77,11.94 21.41,11.58Z"/>
                    </svg>
                </span>
                <?php p($l->t('Tags')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="budget">
            <a href="#budget" class="nav-icon-budget svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M14 21H5v-2h5.5c-.55-1.14-.88-2.4-.97-3.71L9 15.3V17H7v-2.19l-2.66.94-.66-1.88L7 12.8V11H5V9h2V6.5C7 4.01 9.01 2 11.5 2c2.03 0 3.76 1.35 4.32 3.21l-1.89.63C13.6 4.74 12.63 4 11.5 4 10.12 4 9 5.12 9 6.5V9h4v2H9v1.3l6.13-2.16.66 1.88-5.86 2.07c.09 1.14.4 2.21.89 3.19.47.92 1.1 1.73 1.84 2.38L14 19v2z"/>
                    </svg>
                </span>
                <?php p($l->t('Budget')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="income">
            <a href="#income" class="nav-icon-income svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 21H3v-2h4.05c-.55-1.14-.88-2.4-.97-3.71L5.5 15.3V17h-2v-2.19l-1.16.41-.66-1.88L5.5 12.1V11h-2V9h2V6.5C5.5 4.01 7.51 2 10 2c2.03 0 3.76 1.35 4.32 3.21l-1.89.63C12.1 4.74 11.13 4 10 4 8.62 4 7.5 5.12 7.5 6.5V9h4v2h-4v.8l4.63-1.63.66 1.88-4.36 1.54c.09 1.14.4 2.21.89 3.19.36.71.82 1.35 1.35 1.9L12 19v2zm8-10l4 4-4 4v-3h-4v-2h4V11z"/>
                    </svg>
                </span>
                <?php p($l->t('Income')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="bills">
            <a href="#bills" class="nav-icon-bills svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19,14V6c0-1.1-0.9-2-2-2H3C1.9,4,1,4.9,1,6v8c0,1.1,0.9,2,2,2h14C18.1,16,19,15.1,19,14z M17,14H3V6h14V14z M10,7 c-1.66,0-3,1.34-3,3s1.34,3,3,3s3-1.34,3-3S11.66,7,10,7z M23,7v11c0,1.1-0.9,2-2,2H4v-2h17V7H23z"/>
                    </svg>
                </span>
                <?php p($l->t('Bills')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="transfers">
            <a href="#transfers" class="nav-icon-transfers svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M6.99 11L3 15l3.99 4v-3H14v-2H6.99v-3zM21 9l-3.99-4v3H10v2h7.01v3L21 9z"/>
                    </svg>
                </span>
                <?php p($l->t('Transfers')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="savings-goals">
            <a href="#savings-goals" class="nav-icon-savings svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12,3C7.58,3 4,4.79 4,7C4,9.21 7.58,11 12,11C16.42,11 20,9.21 20,7C20,4.79 16.42,3 12,3M4,9V12C4,14.21 7.58,16 12,16C16.42,16 20,14.21 20,12V9C20,11.21 16.42,13 12,13C7.58,13 4,11.21 4,9M4,14V17C4,19.21 7.58,21 12,21C16.42,21 20,19.21 20,17V14C20,16.21 16.42,18 12,18C7.58,18 4,16.21 4,14Z"/>
                    </svg>
                </span>
                <?php p($l->t('Savings Goals')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="debt-payoff">
            <a href="#debt-payoff" class="nav-icon-debt svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                </span>
                <?php p($l->t('Debt Payoff')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="pensions">
            <a href="#pensions" class="nav-icon-pensions svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4M12,6A6,6 0 0,0 6,12A6,6 0 0,0 12,18A6,6 0 0,0 18,12A6,6 0 0,0 12,6M12,8A4,4 0 0,1 16,12A4,4 0 0,1 12,16A4,4 0 0,1 8,12A4,4 0 0,1 12,8Z"/>
                    </svg>
                </span>
                <?php p($l->t('Pensions')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="assets">
            <a href="#assets" class="nav-icon-assets svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M10,2V4.26L12,5.59V4H22V19H17V21H24V2H10M7.5,5L0,10V21H15V10L7.5,5M14,6V6.93L15.61,8H16V6H14M18,6V8H20V6H18M7.5,7.5L13,11V19H10V13H5V19H2V11L7.5,7.5M18,10V12H20V10H18M18,14V16H20V14H18Z"/>
                    </svg>
                </span>
                <?php p($l->t('Assets')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="shared-expenses">
            <a href="#shared-expenses" class="nav-icon-split svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M16,13C15.71,13 15.38,13 15.03,13.05C16.19,13.89 17,15 17,16.5V19H23V16.5C23,14.17 18.33,13 16,13M8,13C5.67,13 1,14.17 1,16.5V19H15V16.5C15,14.17 10.33,13 8,13M8,11A3,3 0 0,0 11,8A3,3 0 0,0 8,5A3,3 0 0,0 5,8A3,3 0 0,0 8,11M16,11A3,3 0 0,0 19,8A3,3 0 0,0 16,5A3,3 0 0,0 13,8A3,3 0 0,0 16,11Z"/>
                    </svg>
                </span>
                <?php p($l->t('Shared Expenses')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="forecast">
            <a href="#forecast" class="nav-icon-trending svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M16,6L18.29,8.29L13.41,13.17L9.41,9.17L2,16.59L3.41,18L9.41,12L13.41,16L19.71,9.71L22,12V6H16Z"/>
                    </svg>
                </span>
                <?php p($l->t('Forecast')); ?>
            </a>
        </li>
        <li class="app-navigation-entry" data-id="reports">
            <a href="#reports" class="nav-icon-clipboard svg">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M19,3H14.82C14.4,1.84 13.3,1 12,1C10.7,1 9.6,1.84 9.18,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M12,3A1,1 0 0,1 13,4A1,1 0 0,1 12,5A1,1 0 0,1 11,4A1,1 0 0,1 12,3"/>
                    </svg>
                </span>
                <?php p($l->t('Reports')); ?>
            </a>
        </li>
    </ul>
    <div id="app-settings">
        <div id="app-settings-header">
            <button class="settings-toggle" type="button" aria-expanded="false" aria-controls="app-settings-content">
                <span class="app-navigation-entry-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" class="toggle-icon">
                        <path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/>
                    </svg>
                </span>
                <?php p($l->t('Tools & Settings')); ?>
            </button>
        </div>
        <ul id="app-settings-content">
            <li class="app-navigation-entry" data-id="import">
                <a href="#import" class="nav-icon-upload svg">
                    <span class="app-navigation-entry-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                        </svg>
                    </span>
                    <?php p($l->t('Import')); ?>
                </a>
            </li>
            <li class="app-navigation-entry" data-id="rules">
                <a href="#rules" class="nav-icon-rules svg">
                    <span class="app-navigation-entry-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M10,18h4v-2h-4V18z M3,6v2h18V6H3z M6,13h12v-2H6V13z"/>
                        </svg>
                    </span>
                    <?php p($l->t('Rules')); ?>
                </a>
            </li>
            <li class="app-navigation-entry" data-id="exchange-rates">
                <a href="#exchange-rates" class="nav-icon-exchange-rates svg">
                    <span class="app-navigation-entry-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M7.5,21.5L3.75,17.75L5.16,16.34L6.75,17.92V12.5H8.25V17.92L9.84,16.34L11.25,17.75L7.5,21.5M16.5,6.5L12.75,2.75L14.16,4.16L15.75,2.58V8H17.25V2.58L18.84,4.16L20.25,2.75L16.5,6.5M3,8V6H11V8H3M13,18V16H21V18H13M7,14V12H17V14H7Z"/>
                        </svg>
                    </span>
                    <?php p($l->t('Exchange Rates')); ?>
                </a>
            </li>
            <li class="app-navigation-entry" data-id="sharing">
                <a href="#sharing" class="nav-icon-sharing svg">
                    <span class="app-navigation-entry-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M18,16.08C17.24,16.08 16.56,16.38 16.04,16.85L8.91,12.7C8.96,12.47 9,12.24 9,12C9,11.76 8.96,11.53 8.91,11.3L15.96,7.19C16.5,7.69 17.21,8 18,8A3,3 0 0,0 21,5A3,3 0 0,0 18,2A3,3 0 0,0 15,5C15,5.24 15.04,5.47 15.09,5.7L8.04,9.81C7.5,9.31 6.79,9 6,9A3,3 0 0,0 3,12A3,3 0 0,0 6,15C6.79,15 7.5,14.69 8.04,14.19L15.16,18.35C15.11,18.56 15.08,18.78 15.08,19C15.08,20.61 16.39,21.92 18,21.92C19.61,21.92 20.92,20.61 20.92,19C20.92,17.39 19.61,16.08 18,16.08Z"/>
                        </svg>
                    </span>
                    <?php p($l->t('Sharing')); ?>
                </a>
            </li>
            <li class="app-navigation-entry" data-id="bank-sync" id="bank-sync-nav" style="display: none;">
                <a href="#bank-sync" class="nav-icon-bank-sync svg">
                    <span class="app-navigation-entry-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.5,1L2,6V8H21V6M16,10V17H19V10M2,22H21V19H2M10,10V17H13V10M4,10V17H7V10H4Z"/>
                        </svg>
                    </span>
                    <?php p($l->t('Bank Sync')); ?>
                </a>
            </li>
            <li class="app-navigation-entry" data-id="settings">
                <a href="#settings" class="nav-icon-settings svg">
                    <span class="app-navigation-entry-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12,15.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.22,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.21,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.68 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z"/>
                        </svg>
                    </span>
                    <?php p($l->t('Settings')); ?>
                </a>
            </li>
            <li class="app-navigation-entry" data-id="lock" id="lock-app-btn" style="display: none;">
                <a href="#" class="nav-icon-lock svg">
                    <span class="app-navigation-entry-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12,17C10.89,17 10,16.1 10,15C10,13.89 10.89,13 12,13A2,2 0 0,1 14,15A2,2 0 0,1 12,17M18,20V10H6V20H18M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V10C4,8.89 4.89,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z"/>
                        </svg>
                    </span>
                    <?php p($l->t('Lock App')); ?>
                </a>
            </li>
        </ul>
    </div>
</div>

<div id="nav-backdrop" class="nav-open-backdrop"></div>

<div id="app-content" class="app-content">
    <div id="app-content-wrapper">
        <!-- Dashboard View -->
        <div id="dashboard-view" class="view active">
            <div class="dashboard-header">
                <div class="dashboard-header-hint" id="dashboard-hint">
                    <span class="icon-info" aria-hidden="true"></span>
                    <span><?php p($l->t('Dashboard is locked. Click unlock to reorder tiles.')); ?></span>
                </div>
                <div class="dashboard-header-actions">
                    <div class="add-tiles-dropdown" id="add-tiles-dropdown" style="display: none;">
                        <button id="add-tiles-btn" class="btn btn-secondary" aria-label="<?php p($l->t('Add tiles')); ?>">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Add Tiles')); ?>
                        </button>
                        <div class="add-tiles-menu" id="add-tiles-menu" style="display: none;">
                            <div class="add-tiles-menu-header"><?php p($l->t('Hidden Tiles')); ?></div>
                            <div class="add-tiles-menu-list" id="add-tiles-menu-list">
                                <!-- Hidden widgets will be populated here -->
                            </div>
                        </div>
                    </div>
                    <button id="toggle-dashboard-lock-btn" class="btn btn-secondary" aria-label="<?php p($l->t('Toggle dashboard lock')); ?>">
                        <span class="icon-lock" aria-hidden="true"></span>
                        <span id="lock-btn-text"><?php p($l->t('Unlock Dashboard')); ?></span>
                    </button>
                </div>
            </div>

            <!-- Hero Section - Key Financial Metrics -->
            <div class="dashboard-hero">
                <div class="hero-card hero-net-worth" data-widget-id="netWorth" data-widget-category="hero">
                    <div class="hero-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Net Worth')); ?></span>
                        <span id="hero-net-worth-value" class="hero-value">--</span>
                        <span id="hero-net-worth-change" class="hero-change"></span>
                    </div>
                </div>

                <div class="hero-card hero-income" data-widget-id="income" data-widget-category="hero">
                    <div class="hero-icon income">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Income This Month')); ?></span>
                        <span id="hero-income-value" class="hero-value income">--</span>
                        <span id="hero-income-change" class="hero-change"></span>
                    </div>
                </div>

                <div class="hero-card hero-expenses" data-widget-id="expenses" data-widget-category="hero">
                    <div class="hero-icon expenses">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M16 18l2.29-2.29-4.88-4.88-4 4L2 7.41 3.41 6l6 6 4-4 6.3 6.29L22 12v6z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Expenses This Month')); ?></span>
                        <span id="hero-expenses-value" class="hero-value expenses">--</span>
                        <span id="hero-expenses-change" class="hero-change"></span>
                    </div>
                </div>

                <div class="hero-card hero-savings" data-widget-id="savings" data-widget-category="hero">
                    <div class="hero-icon savings">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19.5 3.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2 4.5 3.5 3 2v20l1.5-1.5L6 22l1.5-1.5L9 22l1.5-1.5L12 22l1.5-1.5L15 22l1.5-1.5L18 22l1.5-1.5L21 22V2l-1.5 1.5zM19 19.09H5V4.91h14v14.18zM6 15h12v2H6zm0-4h12v2H6zm0-4h12v2H6z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Net Savings')); ?></span>
                        <span id="hero-savings-value" class="hero-value">--</span>
                        <span id="hero-savings-rate" class="hero-subtext"></span>
                    </div>
                </div>

                <div class="hero-card hero-pension" data-widget-id="pension" data-widget-category="hero">
                    <div class="hero-icon pension">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4M12,6A6,6 0 0,0 6,12A6,6 0 0,0 12,18A6,6 0 0,0 18,12A6,6 0 0,0 12,6M12,8A4,4 0 0,1 16,12A4,4 0 0,1 12,16A4,4 0 0,1 8,12A4,4 0 0,1 12,8Z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Pension Worth')); ?></span>
                        <span id="hero-pension-value" class="hero-value">--</span>
                        <span id="hero-pension-count" class="hero-subtext"></span>
                    </div>
                </div>

                <div class="hero-card hero-assets" data-widget-id="assets" data-widget-category="hero">
                    <div class="hero-icon assets">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M10,2V4.26L12,5.59V4H22V19H17V21H24V2H10M7.5,5L0,10V21H15V10L7.5,5M14,6V6.93L15.61,8H16V6H14M18,6V8H20V6H18M7.5,7.5L13,11V19H10V13H5V19H2V11L7.5,7.5M18,10V12H20V10H18M18,14V16H20V14H18Z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Assets Worth')); ?></span>
                        <span id="hero-assets-value" class="hero-value">--</span>
                        <span id="hero-assets-change" class="hero-change"></span>
                        <span id="hero-assets-count" class="hero-subtext"></span>
                    </div>
                </div>

                <!-- Phase 1: New Hero Tiles (Hidden by Default) -->
                <div class="hero-card hero-savings-rate" data-widget-id="savingsRate" data-widget-category="hero" style="display: none;">
                    <div class="hero-icon savings">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2L2 7L12 12L22 7L12 2Z"/>
                            <path d="M2 17L12 22L22 17"/>
                            <path d="M2 12L12 17L22 12"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Savings Rate')); ?></span>
                        <span id="hero-savings-rate-value" class="hero-value">--</span>
                        <span id="hero-savings-rate-change" class="hero-change"></span>
                    </div>
                </div>

                <div class="hero-card hero-cash-flow" data-widget-id="cashFlow" data-widget-category="hero" style="display: none;">
                    <div class="hero-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Cash Flow')); ?></span>
                        <span id="hero-cash-flow-value" class="hero-value">--</span>
                        <span id="hero-cash-flow-change" class="hero-change"></span>
                    </div>
                </div>

                <div class="hero-card hero-budget-remaining" data-widget-id="budgetRemaining" data-widget-category="hero" style="display: none;">
                    <div class="hero-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M13,2.05V5.08C16.39,5.57 19,8.47 19,12C19,12.9 18.82,13.75 18.5,14.54L21.12,16.07C21.68,14.83 22,13.45 22,12C22,6.82 18.05,2.55 13,2.05M12,19A7,7 0 0,1 5,12C5,8.47 7.61,5.57 11,5.08V2.05C5.94,2.55 2,6.81 2,12A10,10 0 0,0 12,22C15.3,22 18.23,20.39 20.05,17.91L17.45,16.38C16.17,18 14.21,19 12,19Z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Budget Remaining')); ?></span>
                        <span id="hero-budget-remaining-value" class="hero-value">--</span>
                        <span id="hero-budget-remaining-change" class="hero-subtext"></span>
                    </div>
                </div>

                <div class="hero-card hero-budget-health" data-widget-id="budgetHealth" data-widget-category="hero" style="display: none;">
                    <div class="hero-icon savings">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M9,20.42L2.79,14.21L5.62,11.38L9,14.77L18.88,4.88L21.71,7.71L9,20.42Z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Budget Health')); ?></span>
                        <span id="hero-budget-health-value" class="hero-value">--</span>
                        <span id="hero-budget-health-change" class="hero-subtext"></span>
                    </div>
                </div>

                <!-- Per-Account Hero Tiles -->
                <div class="hero-card hero-account-income" data-widget-id="accountIncome" data-widget-category="hero" style="display: none;">
                    <div class="hero-icon income">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <div class="hero-label-row">
                            <span class="hero-label"><?php p($l->t('Account Income')); ?></span>
                            <select id="hero-account-income-select" class="hero-inline-select"></select>
                        </div>
                        <span id="hero-account-income-value" class="hero-value income">--</span>
                        <span id="hero-account-income-change" class="hero-change"></span>
                    </div>
                </div>

                <div class="hero-card hero-account-expenses" data-widget-id="accountExpenses" data-widget-category="hero" style="display: none;">
                    <div class="hero-icon expenses">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M16 18l2.29-2.29-4.88-4.88-4 4L2 7.41 3.41 6l6 6 4-4 6.3 6.29L22 12v6z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <div class="hero-label-row">
                            <span class="hero-label"><?php p($l->t('Account Expenses')); ?></span>
                            <select id="hero-account-expenses-select" class="hero-inline-select"></select>
                        </div>
                        <span id="hero-account-expenses-value" class="hero-value expenses">--</span>
                        <span id="hero-account-expenses-change" class="hero-change"></span>
                    </div>
                </div>

                <!-- Phase 2: Lazy-Loaded Hero Tiles -->
                <div class="hero-card hero-uncategorized" data-widget-id="uncategorizedCount" data-widget-category="hero" style="display: none;">
                    <div class="hero-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Uncategorized')); ?></span>
                        <span id="hero-uncategorized-value" class="hero-value">--</span>
                        <span id="hero-uncategorized-change" class="hero-subtext"></span>
                    </div>
                </div>

                <div class="hero-card hero-low-balance" data-widget-id="lowBalanceAlert" data-widget-category="hero" style="display: none;">
                    <div class="hero-icon expenses">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Low Balance Alert')); ?></span>
                        <span id="hero-low-balance-value" class="hero-value">--</span>
                        <span id="hero-low-balance-change" class="hero-subtext"></span>
                    </div>
                </div>

                <!-- Phase 3: Advanced Hero Tiles -->
                <div class="hero-card hero-burn-rate" data-widget-id="burnRate" data-widget-category="hero" style="display: none;">
                    <div class="hero-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15.67 4H14V2h-4v2H8.33C7.6 4 7 4.6 7 5.33v15.33C7 21.4 7.6 22 8.33 22h7.33c.74 0 1.34-.6 1.34-1.33V5.33C17 4.6 16.4 4 15.67 4zM13 19H11v-2h2v2zm0-4H11v-4h2v4zm0-6H11V7h2v2z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Burn Rate')); ?></span>
                        <span id="hero-burn-rate-value" class="hero-value">--</span>
                        <span id="hero-burn-rate-change" class="hero-subtext"></span>
                    </div>
                </div>

                <div class="hero-card hero-debt-free" data-widget-id="daysUntilDebtFree" data-widget-category="hero" style="display: none;">
                    <div class="hero-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.11 0 2-.9 2-2V5c0-1.1-.89-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                    </div>
                    <div class="hero-content">
                        <span class="hero-label"><?php p($l->t('Days Until Debt Free')); ?></span>
                        <span id="hero-debt-free-value" class="hero-value">--</span>
                        <span id="hero-debt-free-change" class="hero-subtext"></span>
                    </div>
                </div>
            </div>

            <!-- Main Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Left Column -->
                <div class="dashboard-column dashboard-column-main">
                    <!-- Income vs Expenses Chart -->
                    <div id="trend-chart-card" class="dashboard-card dashboard-card-large" data-widget-id="trendChart" data-widget-category="widget">
                        <div class="card-header">
                            <h3><?php p($l->t('Income vs Expenses')); ?></h3>
                            <div class="card-header-controls">
                                <select id="trend-account-select" class="card-select">
                                    <option value=""><?php p($l->t('All Accounts')); ?></option>
                                    <!-- Populated dynamically by JS -->
                                </select>
                                <select id="trend-period-select" class="card-select">
                                    <option value="6"><?php p($l->t('Last 6 Months')); ?></option>
                                    <option value="12"><?php p($l->t('Last 12 Months')); ?></option>
                                    <option value="3"><?php p($l->t('Last 3 Months')); ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="chart-container chart-container-large">
                            <canvas id="trend-chart"></canvas>
                        </div>
                        <div id="trend-chart-legend" class="chart-legend"></div>
                    </div>

                    <!-- Spending by Category Chart -->
                    <div id="spending-chart-card" class="dashboard-card" data-widget-id="spendingChart" data-widget-category="widget">
                        <div class="card-header">
                            <h3><?php p($l->t('Spending by Category')); ?></h3>
                            <div class="card-header-controls">
                                <select id="spending-account-select" class="card-select">
                                    <option value=""><?php p($l->t('All Accounts')); ?></option>
                                </select>
                                <select id="spending-period-select" class="card-select">
                                    <option value="month"><?php p($l->t('This Month')); ?></option>
                                    <option value="3months"><?php p($l->t('Last 3 Months')); ?></option>
                                    <option value="year"><?php p($l->t('This Year')); ?></option>
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

                    <!-- Net Worth History Chart -->
                    <div id="net-worth-history-card" class="dashboard-card" data-widget-id="netWorthHistory" data-widget-category="widget">
                        <div class="card-header">
                            <h3><?php p($l->t('Net Worth History')); ?></h3>
                            <div class="card-header-controls">
                                <select id="net-worth-account-select" class="card-select">
                                    <option value=""><?php p($l->t('All Accounts')); ?></option>
                                </select>
                                <div class="period-selector" id="net-worth-period-selector">
                                    <button class="period-btn active" data-days="30">30D</button>
                                    <button class="period-btn" data-days="90">90D</button>
                                    <button class="period-btn" data-days="365">1Y</button>
                                </div>
                            </div>
                        </div>
                        <div id="net-worth-snapshot-status" class="net-worth-status"></div>
                        <div class="chart-container chart-container-medium">
                            <canvas id="net-worth-chart"></canvas>
                        </div>
                        <div id="net-worth-chart-empty" class="chart-empty-state" style="display: none;">
                            <div class="empty-state-content">
                                <p class="empty-state-title"><?php p($l->t('No net worth history yet')); ?></p>
                                <p class="empty-state-subtitle"><?php p($l->t('Snapshots are recorded automatically every day. Your first snapshot will appear within 24 hours.')); ?></p>
                                <button id="record-net-worth-btn" class="btn btn-secondary btn-small"><?php p($l->t('Record snapshot now')); ?></button>
                            </div>
                        </div>
                    </div>

                    <!-- Asset Value History Chart -->
                    <div id="asset-value-history-card" class="dashboard-card" data-widget-id="assetValueHistory" data-widget-category="widget">
                        <div class="card-header">
                            <h3><?php p($l->t('Asset Value History')); ?></h3>
                            <div class="card-header-controls">
                                <div class="period-selector" id="asset-value-period-selector">
                                    <button class="period-btn active" data-days="30">30D</button>
                                    <button class="period-btn" data-days="90">90D</button>
                                    <button class="period-btn" data-days="365">1Y</button>
                                </div>
                            </div>
                        </div>
                        <div class="chart-container chart-container-medium">
                            <canvas id="asset-value-history-chart"></canvas>
                        </div>
                        <div id="asset-value-chart-empty" class="chart-empty-state" style="display: none;">
                            <div class="empty-state-content">
                                <p class="empty-state-title"><?php p($l->t('No asset value history yet')); ?></p>
                                <p class="empty-state-subtitle"><?php p($l->t('Add snapshots to your assets to track their combined value over time.')); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Transactions -->
                    <div id="recent-transactions-card" class="dashboard-card" data-widget-id="recentTransactions" data-widget-category="widget">
                        <div class="card-header">
                            <h3><?php p($l->t('Recent Transactions')); ?></h3>
                            <div class="card-header-controls">
                                <select id="recent-transactions-account-select" class="card-select">
                                    <option value=""><?php p($l->t('All Accounts')); ?></option>
                                </select>
                                <a href="#transactions" class="card-link"><?php p($l->t('View All')); ?></a>
                            </div>
                        </div>
                        <div id="recent-transactions" class="recent-transactions-list"></div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="dashboard-column dashboard-column-side">
                    <!-- Account Balances -->
                    <div id="accounts-card" class="dashboard-card" data-widget-id="accounts" data-widget-category="widget">
                        <div class="card-header">
                            <h3><?php p($l->t('Accounts')); ?></h3>
                            <a href="#accounts" class="card-link"><?php p($l->t('Manage')); ?></a>
                        </div>
                        <div id="accounts-summary" class="accounts-widget"></div>
                    </div>

                    <!-- Budget Alerts -->
                    <div id="budget-alerts-card" class="dashboard-card budget-alerts-card" data-widget-id="budgetAlerts" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Budget Alerts')); ?></h3>
                            <a href="#budget" class="card-link"><?php p($l->t('Details')); ?></a>
                        </div>
                        <div id="budget-alerts" class="budget-alerts-widget">
                            <!-- Alert items will be rendered here -->
                        </div>
                    </div>

                    <!-- Upcoming Bills -->
                    <div id="upcoming-bills-card" class="dashboard-card" data-widget-id="upcomingBills" data-widget-category="widget">
                        <div class="card-header">
                            <h3><?php p($l->t('Upcoming Bills')); ?></h3>
                            <a href="#bills" class="card-link"><?php p($l->t('View All')); ?></a>
                        </div>
                        <div id="upcoming-bills" class="bills-widget">
                            <div class="empty-state-small"><?php p($l->t('No upcoming bills')); ?></div>
                        </div>
                    </div>

                    <!-- Budget Progress -->
                    <div id="budget-progress-card" class="dashboard-card" data-widget-id="budgetProgress" data-widget-category="widget">
                        <div class="card-header">
                            <h3><?php p($l->t('Budget Progress')); ?></h3>
                            <a href="#budget" class="card-link"><?php p($l->t('Details')); ?></a>
                        </div>
                        <div id="budget-progress" class="budget-widget">
                            <div class="empty-state-small"><?php p($l->t('No budgets configured')); ?></div>
                        </div>
                    </div>

                    <!-- Savings Goals -->
                    <div id="savings-goals-card" class="dashboard-card" data-widget-id="savingsGoals" data-widget-category="widget">
                        <div class="card-header">
                            <h3><?php p($l->t('Savings Goals')); ?></h3>
                            <a href="#savings-goals" class="card-link"><?php p($l->t('Manage')); ?></a>
                        </div>
                        <div id="savings-goals-summary" class="savings-goals-widget">
                            <div class="empty-state-small"><?php p($l->t('No savings goals yet')); ?></div>
                        </div>
                    </div>

                    <!-- Debt Payoff Summary -->
                    <div id="debt-payoff-card" class="dashboard-card debt-payoff-card" data-widget-id="debtPayoff" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Debt Payoff')); ?></h3>
                            <a href="#debt-payoff" class="card-link"><?php p($l->t('Plan')); ?></a>
                        </div>
                        <div id="debt-payoff-summary" class="debt-payoff-widget">
                            <div class="debt-summary-stats">
                                <div class="debt-stat">
                                    <span class="debt-stat-label"><?php p($l->t('Total Debt')); ?></span>
                                    <span id="debt-total-balance" class="debt-stat-value">--</span>
                                </div>
                                <div class="debt-stat">
                                    <span class="debt-stat-label"><?php p($l->t('Accounts')); ?></span>
                                    <span id="debt-account-count" class="debt-stat-value">--</span>
                                </div>
                                <div class="debt-stat">
                                    <span class="debt-stat-label"><?php p($l->t('Monthly Min')); ?></span>
                                    <span id="debt-minimum-payment" class="debt-stat-value">--</span>
                                </div>
                            </div>
                            <div id="debt-payoff-estimate" class="debt-payoff-estimate"></div>
                        </div>
                    </div>

                    <!-- Phase 1: New Widget Tiles (Hidden by Default) -->
                    <div id="top-categories-card" class="dashboard-card" data-widget-id="topCategories" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Top Spending Categories')); ?></h3>
                        </div>
                        <div id="top-categories-list" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('No spending data')); ?></div>
                        </div>
                    </div>

                    <div id="account-performance-card" class="dashboard-card" data-widget-id="accountPerformance" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Account Performance')); ?></h3>
                        </div>
                        <div id="account-performance-list" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('No account data')); ?></div>
                        </div>
                    </div>

                    <div id="budget-breakdown-card" class="dashboard-card" data-widget-id="budgetBreakdown" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Budget Breakdown')); ?></h3>
                        </div>
                        <div id="budget-breakdown-table" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('No budget data')); ?></div>
                        </div>
                    </div>

                    <div id="goals-summary-card" class="dashboard-card" data-widget-id="goalsSummary" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('All Goals Progress')); ?></h3>
                        </div>
                        <div id="goals-summary-list" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('No savings goals')); ?></div>
                        </div>
                    </div>

                    <div id="payment-breakdown-card" class="dashboard-card" data-widget-id="paymentBreakdown" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Payment Methods')); ?></h3>
                        </div>
                        <div id="payment-breakdown-list" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('No account data')); ?></div>
                        </div>
                    </div>

                    <div id="reconciliation-card" class="dashboard-card" data-widget-id="reconciliationStatus" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Reconciliation Status')); ?></h3>
                        </div>
                        <div id="reconciliation-status-list" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('No accounts to reconcile')); ?></div>
                        </div>
                    </div>

                    <!-- Phase 2: Lazy-Loaded Widget Tiles -->
                    <div id="monthly-comparison-card" class="dashboard-card" data-widget-id="monthlyComparison" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Monthly Comparison')); ?></h3>
                        </div>
                        <div id="monthly-comparison-content" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('Loading...')); ?></div>
                        </div>
                    </div>

                    <div id="large-transactions-card" class="dashboard-card" data-widget-id="largeTransactions" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Large Transactions')); ?></h3>
                        </div>
                        <div id="large-transactions-list" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('Loading...')); ?></div>
                        </div>
                    </div>

                    <div id="weekly-trend-card" class="dashboard-card" data-widget-id="weeklyTrend" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Weekly Spending')); ?></h3>
                        </div>
                        <div id="weekly-trend-content" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('Loading...')); ?></div>
                        </div>
                    </div>

                    <div id="unmatched-transfers-card" class="dashboard-card" data-widget-id="unmatchedTransfers" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Unmatched Transfers')); ?></h3>
                        </div>
                        <div id="unmatched-transfers-list" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('Loading...')); ?></div>
                        </div>
                    </div>

                    <div id="category-trends-card" class="dashboard-card" data-widget-id="categoryTrends" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Category Trends')); ?></h3>
                        </div>
                        <div id="category-trends-content" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('Loading...')); ?></div>
                        </div>
                    </div>

                    <div id="bills-due-soon-card" class="dashboard-card" data-widget-id="billsDueSoon" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Bills Due Soon')); ?></h3>
                        </div>
                        <div id="bills-due-soon-list" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('Loading...')); ?></div>
                        </div>
                    </div>

                    <!-- Phase 3: Advanced Widget Tiles -->
                    <div id="cash-flow-forecast-card" class="dashboard-card dashboard-card-large" data-widget-id="cashFlowForecast" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Cash Flow Forecast')); ?></h3>
                        </div>
                        <div class="chart-container chart-container-large">
                            <canvas id="cash-flow-forecast-chart"></canvas>
                        </div>
                    </div>

                    <div id="yoy-comparison-card" class="dashboard-card dashboard-card-large" data-widget-id="yoyComparison" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Year-over-Year Comparison')); ?></h3>
                        </div>
                        <div class="chart-container chart-container-large">
                            <canvas id="yoy-comparison-chart"></canvas>
                        </div>
                    </div>

                    <div id="income-tracking-card" class="dashboard-card" data-widget-id="incomeTracking" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Income Tracking')); ?></h3>
                        </div>
                        <div id="income-tracking-content" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('Loading...')); ?></div>
                        </div>
                    </div>

                    <div id="recent-imports-card" class="dashboard-card" data-widget-id="recentImports" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Recent Imports')); ?></h3>
                        </div>
                        <div id="recent-imports-list" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('Loading...')); ?></div>
                        </div>
                    </div>

                    <div id="rule-effectiveness-card" class="dashboard-card" data-widget-id="ruleEffectiveness" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Rule Effectiveness')); ?></h3>
                        </div>
                        <div id="rule-effectiveness-content" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('Loading...')); ?></div>
                        </div>
                    </div>

                    <div id="spending-velocity-card" class="dashboard-card" data-widget-id="spendingVelocity" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Spending Velocity')); ?></h3>
                        </div>
                        <div id="spending-velocity-content" class="widget-content">
                            <div class="empty-state-small"><?php p($l->t('Loading...')); ?></div>
                        </div>
                    </div>

                    <!-- Phase 4: Interactive Widget Tiles -->
                    <div id="quick-add-card" class="dashboard-card" data-widget-id="quickAdd" data-widget-category="widget" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Quick Add Transaction')); ?></h3>
                        </div>
                        <div class="widget-content">
                            <form id="quick-add-form" class="quick-add-form">
                                <div class="form-group-inline">
                                    <label for="quick-add-date"><?php p($l->t('Date')); ?></label>
                                    <input type="date" id="quick-add-date" required>
                                </div>
                                <div class="form-group-inline">
                                    <label for="quick-add-account"><?php p($l->t('Account')); ?></label>
                                    <select id="quick-add-account" required>
                                        <option value=""><?php p($l->t('Select account')); ?></option>
                                    </select>
                                </div>
                                <div class="form-group-inline">
                                    <label for="quick-add-type"><?php p($l->t('Type')); ?></label>
                                    <select id="quick-add-type" required>
                                        <option value=""><?php p($l->t('Select type')); ?></option>
                                        <option value="debit"><?php p($l->t('Expense')); ?></option>
                                        <option value="credit"><?php p($l->t('Income')); ?></option>
                                    </select>
                                </div>
                                <div class="form-group-inline">
                                    <label for="quick-add-amount"><?php p($l->t('Amount')); ?></label>
                                    <input type="number" id="quick-add-amount" step="0.01" required min="0" placeholder="0.00">
                                </div>
                                <div class="form-group-inline">
                                    <label for="quick-add-description"><?php p($l->t('Description')); ?></label>
                                    <input type="text" id="quick-add-description" required maxlength="255" placeholder="<?php p($l->t('Description')); ?>">
                                </div>
                                <div class="form-group-inline">
                                    <label for="quick-add-category"><?php p($l->t('Category')); ?></label>
                                    <select id="quick-add-category">
                                        <option value=""><?php p($l->t('No category')); ?></option>
                                    </select>
                                </div>
                                <div class="quick-add-actions">
                                    <button type="submit" class="primary"><?php p($l->t('Add Transaction')); ?></button>
                                    <button type="button" class="secondary" id="quick-add-reset"><?php p($l->t('Clear')); ?></button>
                                </div>
                                <div id="quick-add-message" class="quick-add-message" style="display: none;"></div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accounts View -->
        <div id="accounts-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Accounts')); ?></h2>
                <button id="add-account-btn" class="primary" aria-label="<?php p($l->t('Add new account')); ?>">
                    <span class="icon-add" aria-hidden="true"></span>
                    <?php p($l->t('Add Account')); ?>
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
                        <span class="summary-label"><?php p($l->t('Total Assets')); ?></span>
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
                        <span class="summary-label"><?php p($l->t('Total Liabilities')); ?></span>
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
                        <span class="summary-label"><?php p($l->t('Net Worth')); ?></span>
                        <span id="summary-net-worth" class="summary-value">--</span>
                    </div>
                </div>
            </div>

            <!-- Currency conversion warning -->
            <div id="accounts-conversion-warning" class="conversion-warning" style="display: none;"></div>

            <!-- Assets Section -->
            <div id="accounts-assets-section" class="accounts-section">
                <div class="section-header-row">
                    <h3 class="section-title"><?php p($l->t('Assets')); ?></h3>
                    <span id="assets-subtotal" class="section-subtotal">--</span>
                </div>
                <div id="accounts-assets-grid" class="accounts-grid"></div>
            </div>

            <!-- Liabilities Section -->
            <div id="accounts-liabilities-section" class="accounts-section">
                <div class="section-header-row">
                    <h3 class="section-title"><?php p($l->t('Liabilities')); ?></h3>
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
                        <?php p($l->t('Accounts')); ?>
                    </button>
                    <span class="breadcrumb-separator">/</span>
                    <h2 id="account-details-title"><?php p($l->t('Account Details')); ?></h2>
                </div>
                <div class="view-controls">
                    <button id="edit-account-btn" class="secondary" title="<?php p($l->t('Edit account')); ?>">
                        <span class="icon-rename" aria-hidden="true"></span>
                        <?php p($l->t('Edit')); ?>
                    </button>
                    <button id="reconcile-account-btn" class="secondary" title="<?php p($l->t('Reconcile account')); ?>">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                        <?php p($l->t('Reconcile')); ?>
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
                                <h3 id="account-display-name"><?php p($l->t('Account Name')); ?></h3>
                                <div class="account-meta">
                                    <span id="account-type-label" class="account-type"><?php p($l->t('Account Type')); ?></span>
                                    <span id="account-institution" class="account-institution"></span>
                                </div>
                            </div>
                            <div class="account-status">
                                <div id="account-health-indicator" class="health-indicator"></div>
                            </div>
                        </div>

                        <div class="account-balance-section">
                            <div class="balance-primary">
                                <label><?php p($l->t('Current Balance')); ?></label>
                                <div id="account-current-balance" class="balance-amount"></div>
                            </div>
                            <div class="balance-secondary">
                                <div class="balance-item">
                                    <label><?php p($l->t('Available Balance')); ?></label>
                                    <div id="account-available-balance" class="balance-amount"></div>
                                </div>
                                <div class="balance-item" id="credit-info" style="display: none;">
                                    <label><?php p($l->t('Credit Limit')); ?></label>
                                    <div id="account-credit-limit" class="balance-amount"></div>
                                </div>
                                <div class="balance-item" id="minimum-payment-info" style="display: none;">
                                    <label><?php p($l->t('Min Payment')); ?></label>
                                    <div id="account-minimum-payment-display" class="balance-amount"></div>
                                </div>
                                <div class="balance-item" id="accrued-interest-info" style="display: none;">
                                    <label><?php p($l->t('Accrued Interest')); ?></label>
                                    <div id="account-accrued-interest" class="balance-amount"></div>
                                </div>
                                <div class="balance-item" id="total-owing-info" style="display: none;">
                                    <label><?php p($l->t('Total (incl. Interest)')); ?></label>
                                    <div id="account-total-owing" class="balance-amount"></div>
                                </div>
                                <div class="balance-item" id="investment-cost-info" style="display: none;">
                                    <label><?php p($l->t('Net Invested')); ?></label>
                                    <div id="account-total-cost" class="balance-amount"></div>
                                </div>
                                <div class="balance-item" id="investment-value-info" style="display: none;">
                                    <label><?php p($l->t('Current Value')); ?></label>
                                    <div id="account-current-value" class="balance-amount"></div>
                                </div>
                                <div class="balance-item" id="investment-pnl-info" style="display: none;">
                                    <label><?php p($l->t('Unrealised P&L')); ?></label>
                                    <div id="account-unrealised-pnl" class="balance-amount"></div>
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
                                <div class="metric-label"><?php p($l->t('Total Transactions')); ?></div>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-icon">
                                <span class="icon-add" style="color: var(--color-success);" aria-hidden="true"></span>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="total-income">$0</div>
                                <div class="metric-label"><?php p($l->t('This Month Income')); ?></div>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-icon">
                                <span class="icon-close" style="color: var(--color-error);" aria-hidden="true"></span>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="total-expenses">$0</div>
                                <div class="metric-label"><?php p($l->t('This Month Expenses')); ?></div>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-icon">
                                <span class="icon-activity" aria-hidden="true"></span>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="avg-transaction">$0</div>
                                <div class="metric-label"><?php p($l->t('Avg. Transaction')); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Account Details Information -->
                <div class="account-details-section">
                    <h3><?php p($l->t('Account Information')); ?></h3>
                    <div class="details-grid">
                        <div class="detail-item">
                            <label><?php p($l->t('Account Number')); ?></label>
                            <span id="account-number"><?php p($l->t('Not provided')); ?></span>
                        </div>
                        <div class="detail-item">
                            <label><?php p($l->t('Routing Number')); ?></label>
                            <span id="routing-number"><?php p($l->t('Not provided')); ?></span>
                        </div>
                        <div class="detail-item">
                            <label><?php p($l->t('IBAN')); ?></label>
                            <span id="account-iban"><?php p($l->t('Not provided')); ?></span>
                        </div>
                        <div class="detail-item">
                            <label><?php p($l->t('Sort Code')); ?></label>
                            <span id="sort-code"><?php p($l->t('Not provided')); ?></span>
                        </div>
                        <div class="detail-item">
                            <label><?php p($l->t('SWIFT/BIC')); ?></label>
                            <span id="swift-bic"><?php p($l->t('Not provided')); ?></span>
                        </div>
                        <div class="detail-item">
                            <label><?php p($l->t('Currency')); ?></label>
                            <span id="account-display-currency">USD</span>
                        </div>
                        <div class="detail-item">
                            <label><?php p($l->t('Opened Date')); ?></label>
                            <span id="account-opened"><?php p($l->t('Not provided')); ?></span>
                        </div>
                        <div class="detail-item">
                            <label><?php p($l->t('Last Reconciled')); ?></label>
                            <span id="last-reconciled"><?php p($l->t('Never')); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Interest Rate History (shown when interest tracking is enabled) -->
                <div class="account-details-section" id="interest-rate-history-section" style="display: none;">
                    <div class="section-header" style="display: flex; justify-content: space-between; align-items: center;">
                        <h3><?php p($l->t('Interest Rate History')); ?></h3>
                        <button class="btn btn-secondary btn-sm" id="add-rate-change-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Add Rate Change')); ?>
                        </button>
                    </div>
                    <table class="rate-history-table">
                        <thead>
                            <tr>
                                <th><?php p($l->t('Effective Date')); ?></th>
                                <th><?php p($l->t('Rate (APR)')); ?></th>
                                <th><?php p($l->t('Compounding')); ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="rate-history-body">
                        </tbody>
                    </table>
                </div>

                <!-- Transaction History -->
                <div class="account-transactions-section">
                    <div class="section-header">
                        <h3><?php p($l->t('Transaction History')); ?></h3>
                        <div class="section-controls">
                            <button id="account-add-transaction-btn" class="primary">
                                <span class="icon-add" aria-hidden="true"></span>
                                <?php p($l->t('Add Transaction')); ?>
                            </button>
                            <button id="account-import-btn" class="secondary">
                                <span class="icon-upload" aria-hidden="true"></span>
                                <?php p($l->t('Import')); ?>
                            </button>
                            <button id="account-export-btn" class="secondary">
                                <span class="icon-download" aria-hidden="true"></span>
                                <?php p($l->t('Export')); ?>
                            </button>
                            <button id="account-toggle-filters-btn" class="secondary" title="<?php p($l->t('Toggle advanced filters')); ?>">
                                <span class="icon-filter" aria-hidden="true"></span>
                                <?php p($l->t('Filters')); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Account Transaction Filters -->
                    <div id="account-transaction-filters" class="filters-panel" style="display: none;">
                        <div class="filters-section">
                            <h3><?php p($l->t('Filter Transactions')); ?></h3>
                            <div class="filters-grid">
                                <div class="filter-group">
                                    <label for="account-filter-category"><?php p($l->t('Category')); ?></label>
                                    <select id="account-filter-category">
                                        <option value=""><?php p($l->t('All Categories')); ?></option>
                                        <option value="uncategorized"><?php p($l->t('Uncategorized')); ?></option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label for="account-filter-type"><?php p($l->t('Type')); ?></label>
                                    <select id="account-filter-type">
                                        <option value=""><?php p($l->t('All Types')); ?></option>
                                        <option value="credit"><?php p($l->t('Income')); ?></option>
                                        <option value="debit"><?php p($l->t('Expense')); ?></option>
                                        <option value="split"><?php p($l->t('Split')); ?></option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label for="account-filter-status"><?php p($l->t('Status')); ?></label>
                                    <select id="account-filter-status">
                                        <option value=""><?php p($l->t('All')); ?></option>
                                        <option value="cleared"><?php p($l->t('Cleared')); ?></option>
                                        <option value="scheduled"><?php p($l->t('Scheduled')); ?></option>
                                    </select>
                                </div>

                                <div class="filter-group">
                                    <label for="account-filter-date-from"><?php p($l->t('From Date')); ?></label>
                                    <input type="date" id="account-filter-date-from">
                                </div>

                                <div class="filter-group">
                                    <label for="account-filter-date-to"><?php p($l->t('To Date')); ?></label>
                                    <input type="date" id="account-filter-date-to">
                                </div>

                                <div class="filter-group">
                                    <label for="account-filter-amount-min"><?php p($l->t('Min Amount')); ?></label>
                                    <input type="number" id="account-filter-amount-min" step="0.01" placeholder="0.00">
                                </div>

                                <div class="filter-group">
                                    <label for="account-filter-amount-max"><?php p($l->t('Max Amount')); ?></label>
                                    <input type="number" id="account-filter-amount-max" step="0.01" placeholder="1000.00">
                                </div>

                                <div class="filter-group full-width">
                                    <label for="account-filter-search"><?php p($l->t('Search')); ?></label>
                                    <input type="text" id="account-filter-search" placeholder="<?php p($l->t('Description, vendor, reference...')); ?>">
                                </div>

                                <div class="filter-group">
                                    <label><?php p($l->t('Tags')); ?></label>
                                    <div id="account-filter-tags" class="tags-autocomplete"></div>
                                </div>
                            </div>

                            <div class="filters-actions">
                                <button id="account-apply-filters-btn" class="primary"><?php p($l->t('Apply Filters')); ?></button>
                                <button id="account-clear-filters-btn" class="secondary"><?php p($l->t('Clear All')); ?></button>
                                <button id="account-save-filter-preset-btn" class="secondary"><?php p($l->t('Save Preset')); ?></button>
                            </div>
                        </div>
                    </div>

                    <!-- Account Transactions Table -->
                    <div class="transactions-container">
                        <table id="account-transactions-table" class="transactions-table">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="date">
                                        <?php p($l->t('Date')); ?> <span class="sort-indicator"></span>
                                    </th>
                                    <th class="sortable" data-sort="description">
                                        <?php p($l->t('Description')); ?> <span class="sort-indicator"></span>
                                    </th>
                                    <th><?php p($l->t('Category')); ?></th>
                                    <th class="sortable" data-sort="amount">
                                        <?php p($l->t('Amount')); ?> <span class="sort-indicator"></span>
                                    </th>
                                    <th><?php p($l->t('Balance')); ?></th>
                                    <th><?php p($l->t('Actions')); ?></th>
                                </tr>
                            </thead>
                            <tbody id="account-transactions-body"></tbody>
                        </table>

                        <!-- Pagination -->
                        <div id="account-transactions-pagination" class="pagination">
                            <button id="account-prev-page" class="pagination-btn" disabled>
                                <span class="icon-arrow-left" aria-hidden="true"></span>
                                <?php p($l->t('Previous')); ?>
                            </button>
                            <div class="page-info">
                                <span id="account-page-info">Page 1 of 1</span>
                            </div>
                            <button id="account-next-page" class="pagination-btn" disabled>
                                <?php p($l->t('Next')); ?>
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
                <h2><?php p($l->t('Transactions')); ?></h2>
                <div class="view-controls">
                    <button id="toggle-filters-btn" class="secondary" title="<?php p($l->t('Toggle advanced filters')); ?>">
                        <span class="icon-filter" aria-hidden="true"></span>
                        <?php p($l->t('Filters')); ?>
                    </button>
                    <button id="bulk-actions-btn" class="secondary" title="<?php p($l->t('Bulk actions')); ?>" disabled>
                        <span class="icon-checkmark" aria-hidden="true"></span>
                        <?php p($l->t('Bulk Actions')); ?>
                    </button>
                    <button id="reconcile-mode-btn" class="secondary" title="<?php p($l->t('Reconciliation mode')); ?>">
                        <span class="icon-history" aria-hidden="true"></span>
                        <?php p($l->t('Reconcile')); ?>
                    </button>
                    <button id="find-duplicates-btn" class="secondary" title="<?php p($l->t('Find suspected duplicate transactions')); ?>">
                        <span class="icon-search" aria-hidden="true"></span>
                        <?php p($l->t('Find Duplicates')); ?>
                    </button>
                    <button id="bulk-match-btn" class="secondary" title="<?php p($l->t('Auto-match transfer transactions')); ?>">
                        <span class="icon-link" aria-hidden="true"></span>
                        <?php p($l->t('Match All')); ?>
                    </button>
                    <button id="add-transaction-btn" class="primary" aria-label="<?php p($l->t('Add new transaction')); ?>">
                        <span class="icon-add" aria-hidden="true"></span>
                        <?php p($l->t('Add Transaction')); ?>
                    </button>
                </div>
            </div>

            <!-- Advanced Filters Panel -->
            <div id="transactions-filters" class="filters-panel" style="display: none;">
                <div class="filters-section">
                    <h3><?php p($l->t('Filter Transactions')); ?></h3>
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label for="filter-account"><?php p($l->t('Account')); ?></label>
                            <select id="filter-account">
                                <option value=""><?php p($l->t('All Accounts')); ?></option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filter-category"><?php p($l->t('Category')); ?></label>
                            <select id="filter-category">
                                <option value=""><?php p($l->t('All Categories')); ?></option>
                                <option value="uncategorized"><?php p($l->t('Uncategorized')); ?></option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filter-type"><?php p($l->t('Type')); ?></label>
                            <select id="filter-type">
                                <option value=""><?php p($l->t('All Types')); ?></option>
                                <option value="credit"><?php p($l->t('Income')); ?></option>
                                <option value="debit"><?php p($l->t('Expense')); ?></option>
                                <option value="split"><?php p($l->t('Split')); ?></option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filter-status"><?php p($l->t('Status')); ?></label>
                            <select id="filter-status">
                                <option value=""><?php p($l->t('All')); ?></option>
                                <option value="cleared"><?php p($l->t('Cleared')); ?></option>
                                <option value="scheduled"><?php p($l->t('Scheduled')); ?></option>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label for="filter-date-from"><?php p($l->t('From Date')); ?></label>
                            <input type="date" id="filter-date-from">
                        </div>

                        <div class="filter-group">
                            <label for="filter-date-to"><?php p($l->t('To Date')); ?></label>
                            <input type="date" id="filter-date-to">
                        </div>

                        <div class="filter-group">
                            <label for="filter-created-from"><?php p($l->t('Created From')); ?></label>
                            <input type="date" id="filter-created-from">
                        </div>

                        <div class="filter-group">
                            <label for="filter-created-to"><?php p($l->t('Created To')); ?></label>
                            <input type="date" id="filter-created-to">
                        </div>

                        <div class="filter-group">
                            <label for="filter-amount-min"><?php p($l->t('Min Amount')); ?></label>
                            <input type="number" id="filter-amount-min" step="0.01" placeholder="0.00">
                        </div>

                        <div class="filter-group">
                            <label for="filter-amount-max"><?php p($l->t('Max Amount')); ?></label>
                            <input type="number" id="filter-amount-max" step="0.01" placeholder="1000.00">
                        </div>

                        <div class="filter-group full-width">
                            <label for="filter-search"><?php p($l->t('Search')); ?></label>
                            <input type="text" id="filter-search" placeholder="<?php p($l->t('Description, vendor, reference...')); ?>">
                        </div>

                        <div class="filter-group">
                            <label><?php p($l->t('Tags')); ?></label>
                            <div id="filter-tags" class="tags-autocomplete"></div>
                        </div>
                    </div>

                    <div class="filters-actions">
                        <button id="apply-filters-btn" class="primary"><?php p($l->t('Apply Filters')); ?></button>
                        <button id="clear-filters-btn" class="secondary"><?php p($l->t('Clear All')); ?></button>
                        <button id="save-filter-preset-btn" class="secondary"><?php p($l->t('Save Preset')); ?></button>
                    </div>
                </div>
            </div>

            <!-- Bulk Actions Panel -->
            <div id="bulk-actions-panel" class="bulk-panel" style="display: none;">
                <div class="bulk-info">
                    <span id="selected-count">0</span> transactions selected
                </div>
                <div class="bulk-actions">
                    <button id="bulk-reconcile-btn" class="secondary"><?php p($l->t('Reconciled')); ?></button>
                    <button id="bulk-unreconcile-btn" class="secondary"><?php p($l->t('Unreconciled')); ?></button>
                    <button id="bulk-edit-btn" class="secondary"><?php p($l->t('Edit Fields...')); ?></button>
                    <button id="bulk-delete-btn" class="error"><?php p($l->t('Delete')); ?></button>
                    <button id="cancel-bulk-btn" class="secondary"><?php p($l->t('Cancel')); ?></button>
                </div>
            </div>

            <!-- Reconciliation Panel -->
            <div id="reconcile-panel" class="reconcile-panel" style="display: none;">
                <div class="reconcile-info">
                    <h3><?php p($l->t('Account Reconciliation')); ?></h3>
                    <div class="reconcile-form">
                        <div class="form-group">
                            <label for="reconcile-account"><?php p($l->t('Account')); ?></label>
                            <select id="reconcile-account" required>
                                <option value=""><?php p($l->t('Select account to reconcile')); ?></option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="reconcile-statement-balance"><?php p($l->t('Statement Balance')); ?></label>
                            <input type="number" id="reconcile-statement-balance" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label for="reconcile-statement-date"><?php p($l->t('Statement Date')); ?></label>
                            <input type="date" id="reconcile-statement-date" required>
                        </div>
                        <button id="start-reconcile-btn" class="primary"><?php p($l->t('Start Reconciliation')); ?></button>
                        <button id="cancel-reconcile-btn" class="secondary"><?php p($l->t('Cancel')); ?></button>
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
                        <div class="table-column-config">
                            <button id="column-config-btn" class="icon-button" title="<?php p($l->t('Configure columns')); ?>">
                                <span class="icon-settings" aria-hidden="true"></span>
                            </button>
                            <div id="column-config-dropdown" class="column-config-dropdown" style="display: none;">
                                <div class="dropdown-content">
                                    <h4><?php p($l->t('Show/Hide Columns')); ?></h4>
                                    <label>
                                        <input type="checkbox" id="col-toggle-date" checked>
                                        <span><?php p($l->t('Date')); ?></span>
                                    </label>
                                    <label>
                                        <input type="checkbox" id="col-toggle-description" checked>
                                        <span><?php p($l->t('Description')); ?></span>
                                    </label>
                                    <label>
                                        <input type="checkbox" id="col-toggle-vendor" checked>
                                        <span><?php p($l->t('Vendor')); ?></span>
                                    </label>
                                    <label>
                                        <input type="checkbox" id="col-toggle-category" checked>
                                        <span><?php p($l->t('Category')); ?></span>
                                    </label>
                                    <label>
                                        <input type="checkbox" id="col-toggle-amount" checked>
                                        <span><?php p($l->t('Amount')); ?></span>
                                    </label>
                                    <label>
                                        <input type="checkbox" id="col-toggle-balance" checked>
                                        <span><?php p($l->t('Balance')); ?></span>
                                    </label>
                                    <label>
                                        <input type="checkbox" id="col-toggle-account" checked>
                                        <span><?php p($l->t('Account')); ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <select id="rows-per-page">
                            <option value="25"><?php p($l->t('25 per page')); ?></option>
                            <option value="50"><?php p($l->t('50 per page')); ?></option>
                            <option value="100"><?php p($l->t('100 per page')); ?></option>
                            <option value="250"><?php p($l->t('250 per page')); ?></option>
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
                                <input type="checkbox" id="select-all-transactions" title="<?php p($l->t('Select all')); ?>">
                            </th>
                            <th class="sortable date-column" data-sort="date">
                                <?php p($l->t('Date')); ?>
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="sortable description-column" data-sort="description">
                                <?php p($l->t('Description')); ?>
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="sortable vendor-column" data-sort="vendor">
                                <?php p($l->t('Vendor')); ?>
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="sortable category-column" data-sort="category">
                                <?php p($l->t('Category')); ?>
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="tags-column">
                                <?php p($l->t('Tags')); ?>
                            </th>
                            <th class="sortable amount-column" data-sort="amount">
                                <?php p($l->t('Amount')); ?>
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="balance-column"><?php p($l->t('Balance')); ?></th>
                            <th class="sortable account-column" data-sort="account">
                                <?php p($l->t('Account')); ?>
                                <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th class="actions-column"><?php p($l->t('Actions')); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>

                <div class="pagination-controls pagination-bottom">
                    <button id="prev-page-btn-bottom" class="secondary" disabled>←</button>
                    <span id="page-info-bottom">Page 1 of 1</span>
                    <button id="next-page-btn-bottom" class="secondary" disabled>→</button>
                </div>
            </div>
        </div>

        <!-- Categories View -->
        <div id="categories-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Categories')); ?></h2>
                <div class="view-controls">
                    <div class="categories-tabs">
                        <button class="tab-button active" data-tab="expense">
                            <span class="icon-close" aria-hidden="true"></span>
                            <?php p($l->t('Expenses')); ?>
                        </button>
                        <button class="tab-button" data-tab="income">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Income')); ?>
                        </button>
                    </div>
                    <div class="categories-actions">
                        <button id="add-category-btn" class="primary" aria-label="<?php p($l->t('Add new category')); ?>">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Add Category')); ?>
                        </button>
                        <button id="category-settings-btn" class="secondary" title="<?php p($l->t('Category settings')); ?>">
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
                            <input type="text" id="categories-search" placeholder="<?php p($l->t('Search categories...')); ?>" class="search-input">
                            <span class="icon-search search-icon" aria-hidden="true"></span>
                        </div>
                        <div class="view-options">
                            <button id="expand-all-btn" class="icon-button" title="<?php p($l->t('Expand all')); ?>">
                                <span class="icon-toggle" aria-hidden="true"></span>
                            </button>
                            <button id="collapse-all-btn" class="icon-button" title="<?php p($l->t('Collapse all')); ?>">
                                <span class="icon-triangle-s" aria-hidden="true"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Bulk Actions Toolbar -->
                    <div id="category-bulk-toolbar" class="category-bulk-toolbar" style="display: none;">
                        <span id="category-bulk-count">0 selected</span>
                        <div class="bulk-actions">
                            <button id="category-select-all-btn" class="secondary small">
                                <?php p($l->t('Select All')); ?>
                            </button>
                            <button id="category-clear-selection-btn" class="secondary small">
                                <?php p($l->t('Clear')); ?>
                            </button>
                            <button id="category-bulk-delete-btn" class="error small">
                                <span class="icon-delete" aria-hidden="true"></span>
                                <?php p($l->t('Delete Selected')); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Categories Tree -->
                    <div class="categories-tree-container">
                        <div id="categories-tree" class="categories-tree sortable-tree"></div>
                        <div class="empty-categories" id="empty-categories" style="display: none;">
                            <div class="empty-content">
                                <span class="icon-tag" aria-hidden="true"></span>
                                <h3><?php p($l->t('No categories yet')); ?></h3>
                                <p><?php p($l->t('Create your first category to start organizing your transactions, or use our recommended defaults.')); ?></p>
                                <div class="empty-buttons">
                                    <button class="primary" id="empty-categories-add-btn">
                                        <span class="icon-add" aria-hidden="true"></span>
                                        <?php p($l->t('Add Category')); ?>
                                    </button>
                                    <button class="secondary" id="create-default-categories-btn">
                                        <span class="icon-template" aria-hidden="true"></span>
                                        <?php p($l->t('Use Default Categories')); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Category Details Panel -->
                <div class="category-details-panel" id="category-details-panel">
                    <div class="category-details-header">
                        <h3 id="category-details-title"><?php p($l->t('Category Details')); ?></h3>
                        <div class="category-details-actions">
                            <button id="edit-category-btn" class="secondary" title="<?php p($l->t('Edit category')); ?>">
                                <span class="icon-rename" aria-hidden="true"></span>
                                <?php p($l->t('Edit')); ?>
                            </button>
                            <button id="delete-category-btn" class="secondary" title="<?php p($l->t('Delete category')); ?>">
                                <span class="icon-delete" aria-hidden="true"></span>
                                <?php p($l->t('Delete')); ?>
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
                                <h4 id="category-display-name"><?php p($l->t('Select a category')); ?></h4>
                                <div class="category-meta">
                                    <span id="category-display-type" class="category-type-badge"></span>
                                    <span id="category-display-path" class="category-path"></span>
                                </div>
                            </div>
                            <div class="category-total-spent" id="category-total-spent">
                                <span class="total-spent-label"><?php p($l->t('Total Spent')); ?></span>
                                <span class="total-spent-value" id="category-total-spent-value">$0</span>
                            </div>
                        </div>

                        <!-- Category Analytics -->
                        <div class="category-analytics-section">
                            <h5><?php p($l->t('Analytics')); ?></h5>
                            <div class="analytics-grid">
                                <div class="analytics-card">
                                    <div class="analytics-content">
                                        <div class="analytics-value" id="total-transactions-count">0</div>
                                        <div class="analytics-label"><?php p($l->t('Transactions')); ?></div>
                                    </div>
                                </div>
                                <div class="analytics-card">
                                    <div class="analytics-content">
                                        <div class="analytics-value" id="avg-transaction-amount">$0</div>
                                        <div class="analytics-label"><?php p($l->t('Average')); ?></div>
                                    </div>
                                </div>
                                <div class="analytics-card">
                                    <div class="analytics-content">
                                        <div class="analytics-value" id="category-trend">—</div>
                                        <div class="analytics-label"><?php p($l->t('Trend')); ?></div>
                                    </div>
                                </div>
                                <div class="analytics-card">
                                    <div class="analytics-content">
                                        <div class="analytics-value" id="category-this-month">$0</div>
                                        <div class="analytics-label"><?php p($l->t('This Month')); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Monthly Spending Chart -->
                        <div class="category-chart-section">
                            <h5><?php p($l->t('Monthly Spending')); ?></h5>
                            <div class="category-chart-container">
                                <canvas id="category-spending-chart"></canvas>
                            </div>
                        </div>

                        <!-- Recent Transactions -->
                        <div class="recent-transactions-section">
                            <h5><?php p($l->t('Recent Transactions')); ?></h5>
                            <div id="category-recent-transactions" class="recent-transactions-list">
                                <div class="empty-state">
                                    <p><?php p($l->t('No transactions in this category yet.')); ?></p>
                                </div>
                            </div>
                            <button id="view-all-transactions-btn" class="secondary full-width">
                                <?php p($l->t('View All Transactions')); ?>
                            </button>
                        </div>

                        <!-- Tag Sets Section -->
                        <div class="category-tag-sets-section">
                            <div class="section-header">
                                <h5><?php p($l->t('Tag Sets')); ?></h5>
                                <button id="add-tag-set-btn-detail" class="primary small" title="<?php p($l->t('Add new tag set')); ?>">
                                    <span class="icon-add" aria-hidden="true"></span> <?php p($l->t('Add')); ?>
                                </button>
                            </div>
                            <div id="category-tag-sets-list" class="tag-sets-list">
                                <div class="empty-state">
                                    <p><?php p($l->t('No tag sets yet.')); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Default State -->
                    <div class="category-details-empty" id="category-details-empty">
                        <div class="empty-content">
                            <span class="icon-tag" aria-hidden="true"></span>
                            <h3><?php p($l->t('Select a category')); ?></h3>
                            <p><?php p($l->t('Choose a category from the tree to view details, budget information, and analytics.')); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tags View -->
        <div id="tags-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Tags')); ?></h2>
                <button id="add-global-tag-btn" class="primary" aria-label="<?php p($l->t('Add new tag')); ?>">
                    <span class="icon-add" aria-hidden="true"></span>
                    <?php p($l->t('Add Tag')); ?>
                </button>
            </div>

            <!-- Info Notice -->
            <div class="tags-notice">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M11,9H13V7H11M12,20C7.59,20 4,16.41 4,12C4,7.59 7.59,4 12,4C16.41,4 20,7.59 20,12C20,16.41 16.41,20 12,20M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M11,17H13V11H11V17Z"/>
                </svg>
                <span><?php p($l->t('Tags created here are global and can be applied to any transaction regardless of category. For category-specific tags, use tag sets in the category detail view.')); ?></span>
            </div>

            <!-- Tags Summary Cards -->
            <div class="tags-summary">
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-tag" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="tags-total-count">0</div>
                        <div class="summary-label"><?php p($l->t('Total Tags')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-star" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="tags-most-used">--</div>
                        <div class="summary-label"><?php p($l->t('Most Used')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-history" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="tags-recent">--</div>
                        <div class="summary-label"><?php p($l->t('Recently Added')); ?></div>
                    </div>
                </div>
            </div>

            <div id="global-tags-container">
                <!-- Global tags will be rendered here -->
            </div>
        </div>

        <!-- Global Tag Modal -->
        <div id="global-tag-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="global-tag-modal-title" aria-hidden="true">
            <div class="modal-content modal-small">
                <div class="modal-header">
                    <h3 id="global-tag-modal-title"><?php p($l->t('Add Tag')); ?></h3>
                </div>
                <form id="global-tag-form" class="modal-form">
                    <input type="hidden" id="global-tag-id" value="">
                    <div class="form-group">
                        <label for="global-tag-name"><?php p($l->t('Tag Name')); ?> <span class="required">*</span></label>
                        <input type="text" id="global-tag-name" name="name" required maxlength="255" placeholder="<?php p($l->t('e.g., Business, Vacation, Groceries')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="global-tag-color"><?php p($l->t('Color')); ?></label>
                        <div class="color-picker-row">
                            <input type="color" id="global-tag-color" name="color" value="#4CAF50">
                            <input type="text" id="global-tag-color-hex" maxlength="7" placeholder="#4CAF50" value="#4CAF50">
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="cancel-btn"><?php p($l->t('Cancel')); ?></button>
                        <button type="submit" class="primary" id="global-tag-save-btn"><?php p($l->t('Save')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Budget View -->
        <div id="budget-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Budget')); ?></h2>
                <div class="view-controls">
                    <div class="budget-period-selector">
                        <label for="budget-month"><?php p($l->t('Month:')); ?></label>
                        <select id="budget-month">
                            <!-- Populated dynamically -->
                        </select>
                    </div>
                    <div id="budget-snapshot-controls" class="budget-snapshot-controls">
                        <!-- Populated dynamically: button or notice -->
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
                        <div class="summary-label"><?php p($l->t('Total Budgeted')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-close" style="color: var(--color-error);" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="budget-total-spent">$0</div>
                        <div class="summary-label"><?php p($l->t('Total Spent')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-checkmark" style="color: var(--color-success);" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="budget-total-remaining">$0</div>
                        <div class="summary-label"><?php p($l->t('Remaining')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-category-integration" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="budget-categories-count">0</div>
                        <div class="summary-label"><?php p($l->t('Categories with Budget')); ?></div>
                    </div>
                </div>
            </div>

            <!-- Budget Type Tabs -->
            <div class="budget-tabs">
                <button class="tab-button active" data-budget-type="expense">
                    <span class="icon-close" aria-hidden="true"></span>
                    <?php p($l->t('Expenses')); ?>
                </button>
                <button class="tab-button" data-budget-type="income">
                    <span class="icon-add" aria-hidden="true"></span>
                    <?php p($l->t('Income')); ?>
                </button>
            </div>

            <!-- Budget Tree Container -->
            <div class="budget-container">
                <div class="budget-tree-header">
                    <div class="budget-col-name"><?php p($l->t('Category')); ?></div>
                    <div class="budget-col-budget"><?php p($l->t('Budget')); ?></div>
                    <div class="budget-col-period"><?php p($l->t('Period')); ?></div>
                    <div class="budget-col-spent"><?php p($l->t('Spent')); ?></div>
                    <div class="budget-col-remaining"><?php p($l->t('Remaining')); ?></div>
                    <div class="budget-col-progress"><?php p($l->t('Progress')); ?></div>
                </div>
                <div id="budget-tree" class="budget-tree">
                    <!-- Budget rows rendered dynamically -->
                </div>
                <div class="empty-budget" id="empty-budget" style="display: none;">
                    <div class="empty-content">
                        <span class="icon-quota" aria-hidden="true"></span>
                        <h3><?php p($l->t('No categories yet')); ?></h3>
                        <p><?php p($l->t('Create categories first to start setting up your budget.')); ?></p>
                        <button class="primary" id="empty-budget-go-categories-btn">
                            <span class="icon-tag" aria-hidden="true"></span>
                            <?php p($l->t('Go to Categories')); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Import View -->
        <div id="import-view" class="view">
            <h2><?php p($l->t('Import Transactions')); ?></h2>

            <!-- Import Navigation Tabs -->
            <div class="import-nav-tabs">
                <button class="import-tab-btn active" data-tab="wizard"><?php p($l->t('Import Wizard')); ?></button>
                <button class="import-tab-btn" data-tab="history"><?php p($l->t('Import History')); ?></button>
            </div>

            <!-- Import Wizard Tab -->
            <div id="import-wizard-tab" class="import-tab-content active">
                <!-- Wizard Progress Bar -->
                <div class="wizard-progress">
                    <div class="wizard-step active" data-step="1">
                        <div class="step-number">1</div>
                        <div class="step-label"><?php p($l->t('Select File')); ?></div>
                    </div>
                    <div class="wizard-step" data-step="2">
                        <div class="step-number">2</div>
                        <div class="step-label"><?php p($l->t('Map Columns')); ?></div>
                    </div>
                    <div class="wizard-step" data-step="3">
                        <div class="step-number">3</div>
                        <div class="step-label"><?php p($l->t('Review & Import')); ?></div>
                    </div>
                </div>

                <div class="import-wizard">
                    <!-- Step 1: File Selection -->
                    <div class="import-step active" id="import-step-1">
                        <div class="step-header">
                            <h3><?php p($l->t('Step 1: Select File')); ?></h3>
                            <p><?php p($l->t('Choose your bank statement file (CSV, OFX, or QIF format)')); ?></p>
                        </div>

                        <div class="import-dropzone" id="import-dropzone">
                            <span class="icon-upload"></span>
                            <p><?php p($l->t('Drag and drop your bank statement here')); ?></p>
                            <p><?php p($l->t('or')); ?></p>
                            <button id="import-browse-btn" class="secondary" aria-label="<?php p($l->t('Browse for file to import')); ?>"><?php p($l->t('Browse Files')); ?></button>
                            <input type="file" id="import-file-input" accept=".csv,.ofx,.qif" style="display: none;">
                        </div>

                        <div class="file-formats-info">
                            <h4><?php p($l->t('Supported Formats:')); ?></h4>
                            <ul>
                                <li><strong>CSV:</strong> <?php p($l->t('Comma-separated values from any bank')); ?></li>
                                <li><strong>OFX:</strong> <?php p($l->t('Open Financial Exchange format')); ?></li>
                                <li><strong>QIF:</strong> <?php p($l->t('Quicken Interchange Format')); ?></li>
                            </ul>
                        </div>
                    </div>

                    <!-- Step 2: Column Mapping -->
                    <div class="import-step" id="import-step-2" style="display: none;">
                        <div class="step-header">
                            <h3><?php p($l->t('Step 2: Map Columns')); ?></h3>
                            <p><?php p($l->t('Match your file columns to budget fields')); ?></p>
                        </div>

                        <div class="import-file-info">
                            <div class="file-details">
                                <span class="file-name"></span>
                                <span class="file-size"></span>
                                <span class="record-count"></span>
                            </div>
                        </div>

                        <div class="csv-options" id="csv-options" style="display: none;">
                            <div class="mapping-field">
                                <label><?php p($l->t('CSV Delimiter')); ?></label>
                                <select id="csv-delimiter">
                                    <option value=","><?php p($l->t('Comma (,)')); ?></option>
                                    <option value=";"><?php p($l->t('Semicolon (;)')); ?></option>
                                    <option value="\t"><?php p($l->t('Tab')); ?></option>
                                </select>
                                <p class="hint"><?php p($l->t('Change if columns are not detected correctly')); ?></p>
                            </div>
                        </div>

                        <div class="mapping-container">
                            <div class="mapping-fields">
                                <div class="mapping-field required">
                                    <label><?php p($l->t('Date')); ?> <span class="required">*</span></label>
                                    <select id="map-date" required>
                                        <option value=""><?php p($l->t('Select column...')); ?></option>
                                    </select>
                                </div>
                                <div class="mapping-field required">
                                    <label><?php p($l->t('Amount')); ?> <span class="required">*</span></label>
                                    <select id="map-amount">
                                        <option value=""><?php p($l->t('Select column...')); ?></option>
                                    </select>
                                    <p class="hint"><?php p($l->t('Or use separate income/expense columns below')); ?></p>
                                </div>
                                <div class="mapping-field-group">
                                    <div class="mapping-field">
                                        <label><?php p($l->t('Income Column')); ?></label>
                                        <select id="map-income">
                                            <option value=""><?php p($l->t('Select column...')); ?></option>
                                        </select>
                                    </div>
                                    <div class="mapping-field">
                                        <label><?php p($l->t('Expense Column')); ?></label>
                                        <select id="map-expense">
                                            <option value=""><?php p($l->t('Select column...')); ?></option>
                                        </select>
                                    </div>
                                    <p class="hint"><?php p($l->t('For files with separate income and expense columns')); ?></p>
                                </div>
                                <div class="mapping-field required">
                                    <label><?php p($l->t('Description')); ?> <span class="required">*</span></label>
                                    <select id="map-description" required>
                                        <option value=""><?php p($l->t('Select column...')); ?></option>
                                    </select>
                                </div>
                                <div class="mapping-field">
                                    <label><?php p($l->t('Transaction Type')); ?></label>
                                    <select id="map-type">
                                        <option value=""><?php p($l->t('Auto-detect from amount')); ?></option>
                                    </select>
                                </div>
                                <div class="mapping-field">
                                    <label><?php p($l->t('Vendor/Payee')); ?></label>
                                    <select id="map-vendor">
                                        <option value=""><?php p($l->t('Select column...')); ?></option>
                                    </select>
                                </div>
                                <div class="mapping-field">
                                    <label><?php p($l->t('Reference/Check Number')); ?></label>
                                    <select id="map-reference">
                                        <option value=""><?php p($l->t('Select column...')); ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="preview-data">
                                <h4><?php p($l->t('Data Preview')); ?></h4>
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
                                <?php p($l->t('Skip first row (headers)')); ?>
                            </label>
                            <label>
                                <input type="checkbox" id="apply-rules">
                                <?php p($l->t('Apply import rules for categorization')); ?>
                            </label>
                        </div>
                    </div>

                    <!-- Step 3: Review & Import -->
                    <div class="import-step" id="import-step-3" style="display: none;">
                        <div class="step-header">
                            <h3><?php p($l->t('Step 3: Review & Import')); ?></h3>
                            <p><?php p($l->t('Review mapped transactions before importing')); ?></p>
                        </div>

                        <div class="import-summary">
                            <div class="summary-stats">
                                <div class="stat-item">
                                    <span class="stat-label"><?php p($l->t('Total Transactions:')); ?></span>
                                    <span class="stat-value" id="total-transactions">0</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label"><?php p($l->t('New Transactions:')); ?></span>
                                    <span class="stat-value" id="new-transactions">0</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label"><?php p($l->t('Duplicates Found:')); ?></span>
                                    <span class="stat-value" id="duplicate-transactions">0</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label"><?php p($l->t('Auto-categorized:')); ?></span>
                                    <span class="stat-value" id="categorized-transactions">0</span>
                                </div>
                            </div>

                            <!-- Single account selection (for CSV) -->
                            <div class="account-selection" id="single-account-selection">
                                <label for="import-account"><?php p($l->t('Import to Account:')); ?></label>
                                <select id="import-account">
                                    <option value=""><?php p($l->t('Select account...')); ?></option>
                                </select>
                            </div>

                            <!-- Multi-account mapping (for OFX/QIF with multiple accounts) -->
                            <div class="account-mapping-section" id="multi-account-mapping" style="display: none;">
                                <h4><?php p($l->t('Map Source Accounts to Destination Accounts')); ?></h4>
                                <p class="mapping-description"><?php p($l->t('Your file contains multiple accounts. Map each source account to a destination account in your budget.')); ?></p>
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
                                        <?php p($l->t('Show duplicates')); ?>
                                    </label>
                                    <label>
                                        <input type="checkbox" id="show-uncategorized" checked>
                                        <?php p($l->t('Show uncategorized')); ?>
                                    </label>
                                </div>
                                <div class="preview-pagination">
                                    <span id="preview-info"><?php p($l->t('Showing 0 of 0')); ?></span>
                                </div>
                            </div>

                            <div class="preview-table-container">
                                <table id="preview-table">
                                    <thead>
                                        <tr>
                                            <th><?php p($l->t('Import')); ?></th>
                                            <th><?php p($l->t('Date')); ?></th>
                                            <th><?php p($l->t('Description')); ?></th>
                                            <th><?php p($l->t('Amount')); ?></th>
                                            <th><?php p($l->t('Category')); ?></th>
                                            <th><?php p($l->t('Status')); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Wizard Navigation -->
                    <div class="wizard-navigation">
                        <button id="prev-step-btn" class="secondary" style="display: none;"><?php p($l->t('Previous')); ?></button>
                        <button id="next-step-btn" class="primary" disabled><?php p($l->t('Next')); ?></button>
                        <button id="import-btn" class="primary" style="display: none;"><?php p($l->t('Import Transactions')); ?></button>
                        <button id="cancel-import-btn" class="secondary"><?php p($l->t('Cancel')); ?></button>
                    </div>
                </div>
            </div>

            <!-- Import History Tab -->
            <div id="import-history-tab" class="import-tab-content">
                <div class="history-header">
                    <h3><?php p($l->t('Import History')); ?></h3>
                    <p><?php p($l->t('View and manage previous imports')); ?></p>
                </div>

                <div class="history-list">
                    <div class="history-table-container">
                        <table id="history-table">
                            <thead>
                                <tr>
                                    <th><?php p($l->t('Date')); ?></th>
                                    <th><?php p($l->t('File Name')); ?></th>
                                    <th><?php p($l->t('Account')); ?></th>
                                    <th><?php p($l->t('Transactions')); ?></th>
                                    <th><?php p($l->t('Status')); ?></th>
                                    <th><?php p($l->t('Actions')); ?></th>
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
                <h2><?php p($l->t('Bills')); ?></h2>
                <div class="view-controls">
                    <button id="detect-bills-btn" class="secondary" title="<?php p($l->t('Auto-detect recurring bills from transactions')); ?>">
                        <span class="icon-search" aria-hidden="true"></span>
                        <?php p($l->t('Detect Bills')); ?>
                    </button>
                    <button id="add-bill-btn" class="primary" aria-label="<?php p($l->t('Add new bill')); ?>">
                        <span class="icon-add" aria-hidden="true"></span>
                        <?php p($l->t('Add Bill')); ?>
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
                        <div class="summary-label"><?php p($l->t('Due This Month')); ?></div>
                    </div>
                </div>
                <div class="summary-card warning">
                    <div class="summary-icon">
                        <span class="icon-alert" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="bills-overdue-count">0</div>
                        <div class="summary-label"><?php p($l->t('Overdue')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-quota" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="bills-monthly-total">$0</div>
                        <div class="summary-label"><?php p($l->t('Monthly Total')); ?></div>
                    </div>
                </div>
                <div class="summary-card success">
                    <div class="summary-icon">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="bills-paid-count">0</div>
                        <div class="summary-label"><?php p($l->t('Paid This Month')); ?></div>
                    </div>
                </div>
            </div>

            <!-- Bills Filter Tabs -->
            <div class="bills-tabs">
                <button class="tab-button active" data-filter="all"><?php p($l->t('All Bills')); ?></button>
                <button class="tab-button" data-filter="due"><?php p($l->t('Due Soon')); ?></button>
                <button class="tab-button" data-filter="overdue"><?php p($l->t('Overdue')); ?></button>
                <button class="tab-button" data-filter="paid"><?php p($l->t('Paid')); ?></button>
            </div>

            <!-- Bills List -->
            <div class="bills-container">
                <div id="bills-list" class="bills-list">
                    <!-- Bills will be rendered here -->
                </div>

                <div class="empty-bills" id="empty-bills" style="display: none;">
                    <div class="empty-content">
                        <span class="icon-calendar" aria-hidden="true"></span>
                        <h3><?php p($l->t('No bills yet')); ?></h3>
                        <p><?php p($l->t('Add your recurring bills to track due dates and never miss a payment.')); ?></p>
                        <button class="primary" id="empty-bills-add-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Add Your First Bill')); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Detected Bills Panel (hidden by default) -->
            <div id="detected-bills-panel" class="detected-bills-panel" style="display: none;">
                <div class="panel-header">
                    <h3><?php p($l->t('Detected Recurring Transactions')); ?></h3>
                    <p><?php p($l->t('We found these potential recurring bills in your transaction history')); ?></p>
                    <button id="close-detected-panel" class="icon-button" title="<?php p($l->t('Close')); ?>">
                        <span class="icon-close" aria-hidden="true"></span>
                    </button>
                </div>
                <div class="detected-bills-list" id="detected-bills-list">
                    <!-- Detected bills will be rendered here -->
                </div>
                <div class="panel-actions">
                    <button id="add-selected-bills-btn" class="primary"><?php p($l->t('Add Selected Bills')); ?></button>
                    <button id="cancel-detected-btn" class="secondary"><?php p($l->t('Cancel')); ?></button>
                </div>
            </div>
        </div>

        <!-- Transfers View -->
        <div id="transfers-view" class="view">
            <!-- Content will be rendered by TransfersModule -->
        </div>

        <!-- Rules View -->
        <div id="rules-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Rules')); ?></h2>
                <div class="view-controls">
                    <button id="apply-rules-btn" class="secondary" aria-label="<?php p($l->t('Apply rules to transactions')); ?>">
                        <span class="icon-play" aria-hidden="true"></span>
                        <?php p($l->t('Apply Rules')); ?>
                    </button>
                    <button id="rules-add-btn" class="primary" aria-label="<?php p($l->t('Add new rule')); ?>">
                        <span class="icon-add" aria-hidden="true"></span>
                        <?php p($l->t('Add Rule')); ?>
                    </button>
                </div>
            </div>

            <!-- Rules Summary Cards -->
            <div class="rules-summary">
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-category-monitoring" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="rules-total-count">0</div>
                        <div class="summary-label"><?php p($l->t('Total Rules')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="rules-active-count">0</div>
                        <div class="summary-label"><?php p($l->t('Active')); ?></div>
                    </div>
                </div>
            </div>

            <!-- Rules Table -->
            <div class="rules-table-wrapper">
                <table id="rules-table" class="rules-table">
                    <thead>
                        <tr>
                            <th class="rules-col-priority"><?php p($l->t('Pri')); ?></th>
                            <th class="rules-col-name"><?php p($l->t('Name')); ?></th>
                            <th class="rules-col-status"><?php p($l->t('Status')); ?></th>
                            <th class="rules-col-criteria"><?php p($l->t('Criteria')); ?></th>
                            <th class="rules-col-actions"><?php p($l->t('Actions')); ?></th>
                            <th class="rules-col-buttons"></th>
                        </tr>
                    </thead>
                    <tbody id="rules-list">
                        <!-- Rules rendered here by JavaScript -->
                    </tbody>
                </table>

                <div class="empty-rules" id="empty-rules" style="display: none;">
                    <div class="empty-content">
                        <span class="icon-category-monitoring empty-icon" aria-hidden="true"></span>
                        <h3><?php p($l->t('No rules yet')); ?></h3>
                        <p><?php p($l->t('Create rules to automatically categorize your transactions based on description, vendor, or other fields.')); ?></p>
                        <button class="primary" id="empty-rules-add-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Create Your First Rule')); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recurring Income View -->
        <div id="income-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Recurring Income')); ?></h2>
                <div class="view-controls">
                    <button id="detect-income-btn" class="secondary" title="<?php p($l->t('Auto-detect recurring income from transactions')); ?>">
                        <span class="icon-search" aria-hidden="true"></span>
                        <?php p($l->t('Detect Income')); ?>
                    </button>
                    <button id="add-income-btn" class="primary" aria-label="<?php p($l->t('Add recurring income')); ?>">
                        <span class="icon-add" aria-hidden="true"></span>
                        <?php p($l->t('Add Income')); ?>
                    </button>
                </div>
            </div>

            <!-- Income Summary Cards -->
            <div class="income-summary">
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-calendar" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="income-expected-count">0</div>
                        <div class="summary-label"><?php p($l->t('Expected This Month')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-quota" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="income-monthly-total">£0</div>
                        <div class="summary-label"><?php p($l->t('Monthly Total')); ?></div>
                    </div>
                </div>
                <div class="summary-card success">
                    <div class="summary-icon">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="income-received-count">0</div>
                        <div class="summary-label"><?php p($l->t('Received This Month')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-history" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="income-active-count">0</div>
                        <div class="summary-label"><?php p($l->t('Active Sources')); ?></div>
                    </div>
                </div>
            </div>

            <!-- Income Filter Tabs -->
            <div class="income-tabs">
                <button class="tab-button active" data-filter="all"><?php p($l->t('All Income')); ?></button>
                <button class="tab-button" data-filter="expected"><?php p($l->t('Expected Soon')); ?></button>
                <button class="tab-button" data-filter="received"><?php p($l->t('Received')); ?></button>
            </div>

            <!-- Income List -->
            <div class="income-container">
                <div id="income-list" class="income-list">
                    <!-- Income entries will be rendered here -->
                </div>

                <div class="empty-income" id="empty-income" style="display: none;">
                    <div class="empty-content">
                        <span class="icon-quota" aria-hidden="true"></span>
                        <h3><?php p($l->t('No recurring income yet')); ?></h3>
                        <p><?php p($l->t('Track your expected income sources like salary, dividends, or rental income.')); ?></p>
                        <button class="primary" id="empty-income-add-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Add Your First Income Source')); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Detected Income Panel (hidden by default) -->
            <div id="detected-income-panel" class="detected-bills-panel" style="display: none;">
                <div class="panel-header">
                    <h3><?php p($l->t('Detected Recurring Income')); ?></h3>
                    <p><?php p($l->t('We found these potential recurring income sources in your transaction history')); ?></p>
                    <button id="close-detected-income-panel" class="icon-button" title="<?php p($l->t('Close')); ?>">
                        <span class="icon-close" aria-hidden="true"></span>
                    </button>
                </div>
                <div class="detected-bills-list" id="detected-income-list">
                    <!-- Detected income will be rendered here -->
                </div>
                <div class="panel-actions">
                    <button id="add-selected-income-btn" class="primary"><?php p($l->t('Add Selected Income')); ?></button>
                    <button id="cancel-detected-income-btn" class="secondary"><?php p($l->t('Cancel')); ?></button>
                </div>
            </div>
        </div>

        <!-- Savings Goals View -->
        <div id="savings-goals-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Savings Goals')); ?></h2>
                <button id="add-goal-btn" class="primary" aria-label="<?php p($l->t('Add new savings goal')); ?>">
                    <span class="icon-add" aria-hidden="true"></span>
                    <?php p($l->t('Add Goal')); ?>
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
                        <div class="summary-label"><?php p($l->t('Active Goals')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-quota" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="goals-total-saved">$0</div>
                        <div class="summary-label"><?php p($l->t('Total Saved')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <span class="icon-category-office" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="goals-total-target">$0</div>
                        <div class="summary-label"><?php p($l->t('Total Target')); ?></div>
                    </div>
                </div>
                <div class="summary-card success">
                    <div class="summary-icon">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="goals-completed-count">0</div>
                        <div class="summary-label"><?php p($l->t('Completed')); ?></div>
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
                        <h3><?php p($l->t('No savings goals yet')); ?></h3>
                        <p><?php p($l->t('Set up savings goals to track your progress towards financial milestones like an emergency fund, vacation, or new car.')); ?></p>
                        <button class="primary" id="empty-goals-add-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Create Your First Goal')); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Goal Modal -->
        <div id="goal-modal" class="modal" style="display: none;">
            <div class="modal-content modal-medium">
                <div class="modal-header">
                    <h3 id="goal-modal-title"><?php p($l->t('Add Savings Goal')); ?></h3>
                </div>
                <form id="goal-form" class="modal-form">
                    <div class="form-group">
                        <label for="goal-name"><?php p($l->t('Goal Name')); ?> *</label>
                        <input type="text" id="goal-name" name="name" required placeholder="<?php p($l->t('e.g., Emergency Fund, Vacation, New Car')); ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="goal-target"><?php p($l->t('Target Amount')); ?> *</label>
                            <input type="number" id="goal-target" name="targetAmount" min="0" step="0.01" required placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label for="goal-current"><?php p($l->t('Current Amount')); ?></label>
                            <input type="number" id="goal-current" name="currentAmount" min="0" step="0.01" value="0" placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="goal-account"><?php p($l->t('Linked Account (optional)')); ?></label>
                            <select id="goal-account" name="accountId">
                                <option value=""><?php p($l->t('No linked account')); ?></option>
                                <!-- Account options populated dynamically -->
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="goal-target-date"><?php p($l->t('Target Date (optional)')); ?></label>
                            <input type="date" id="goal-target-date" name="targetDate">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="goal-tag"><?php p($l->t('Linked Tag (optional)')); ?></label>
                        <select id="goal-tag" name="tagId">
                            <option value=""><?php p($l->t('No linked tag')); ?></option>
                            <!-- Tag options populated dynamically -->
                        </select>
                        <p class="form-hint" id="goal-tag-hint" style="display: none; margin-top: 4px; font-size: 12px; color: #888;">
                            <?php p($l->t('Current amount will be automatically calculated from tagged transactions.')); ?>
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="goal-color"><?php p($l->t('Color')); ?></label>
                        <div class="color-picker-row">
                            <input type="color" id="goal-color" name="color" value="#0082c9">
                            <span class="color-preview" id="goal-color-preview"></span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="goal-notes"><?php p($l->t('Notes')); ?></label>
                        <textarea id="goal-notes" name="notes" rows="3" placeholder="<?php p($l->t('Any notes about this goal...')); ?>"></textarea>
                    </div>

                    <input type="hidden" id="goal-id" name="id" value="">

                    <div class="modal-actions">
                        <button type="button" class="cancel-btn"><?php p($l->t('Cancel')); ?></button>
                        <button type="submit" class="primary" id="save-goal-btn"><?php p($l->t('Save Goal')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Money to Goal Modal -->
        <div id="add-to-goal-modal" class="modal" style="display: none;">
            <div class="modal-content modal-small">
                <div class="modal-header">
                    <h3><?php p($l->t('Add to')); ?> <span id="add-to-goal-name"><?php p($l->t('Goal')); ?></span></h3>
                    <button class="modal-close cancel-btn" aria-label="<?php p($l->t('Close')); ?>">&times;</button>
                </div>
                <form id="add-to-goal-form" class="modal-form">
                    <div class="form-group">
                        <label for="add-amount"><?php p($l->t('Amount to Add')); ?></label>
                        <input type="number" id="add-amount" name="amount" min="0.01" step="0.01" required placeholder="0.00">
                    </div>
                    <input type="hidden" id="add-to-goal-id" name="goalId" value="">
                    <div class="modal-actions">
                        <button type="button" class="cancel-btn"><?php p($l->t('Cancel')); ?></button>
                        <button type="submit" class="primary"><?php p($l->t('Add')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Forecast View -->
        <div id="forecast-view" class="view">
            <div class="forecast-header">
                <h2><?php p($l->t('Financial Forecast')); ?></h2>
                <div class="forecast-controls">
                    <label for="forecast-horizon"><?php p($l->t('Forecast Period:')); ?></label>
                    <select id="forecast-horizon">
                        <option value="3"><?php p($l->t('3 months')); ?></option>
                        <option value="6" selected><?php p($l->t('6 months')); ?></option>
                        <option value="12"><?php p($l->t('12 months')); ?></option>
                        <option value="24"><?php p($l->t('24 months')); ?></option>
                    </select>
                </div>
            </div>

            <!-- Info Notice -->
            <div class="forecast-notice" style="background-color: rgba(100, 116, 139, 0.08); border: 1px solid rgba(100, 116, 139, 0.15); border-radius: 4px; padding: 12px 16px; margin: 16px 0; display: flex; align-items: flex-start; gap: 12px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="rgba(100, 116, 139, 0.6)" style="flex-shrink: 0; margin-top: 2px;">
                    <path d="M11,9H13V7H11M12,20C7.59,20 4,16.41 4,12C4,7.59 7.59,4 12,4C16.41,4 20,7.59 20,12C20,16.41 16.41,20 12,20M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M11,17H13V11H11V17Z"/>
                </svg>
                <div style="color: rgba(100, 116, 139, 0.85); font-size: 14px;">
                    <strong><?php p($l->t('Note:')); ?></strong> <?php p($l->t('Forecasts are estimates based on historical spending patterns and trends. Actual results may vary due to unexpected expenses, income changes, or shifts in spending behavior. Use these projections as guidance rather than guarantees.')); ?>
                </div>
            </div>

            <!-- Loading State -->
            <div id="forecast-loading" class="forecast-loading">
                <div class="loading-spinner"></div>
                <p><?php p($l->t('Calculating forecast...')); ?></p>
            </div>

            <!-- Empty State -->
            <div id="forecast-empty" class="forecast-empty" style="display: none;">
                <div class="empty-icon">📊</div>
                <h3><?php p($l->t('Not Enough Data')); ?></h3>
                <p><?php p($l->t('Add more transactions to generate an accurate forecast. We need at least 1 month of transaction history.')); ?></p>
            </div>

            <!-- Balance Overview Cards -->
            <div id="forecast-overview" class="forecast-section" style="display: none;">
                <div class="overview-cards">
                    <div class="overview-card current-balance">
                        <span class="card-label"><?php p($l->t('Current Balance')); ?></span>
                        <span class="card-value" id="current-balance">--</span>
                    </div>
                    <div class="overview-card projected-balance">
                        <span class="card-label"><?php p($l->t('Projected Balance')); ?></span>
                        <span class="card-value" id="projected-balance">--</span>
                        <span class="card-change" id="balance-change">--</span>
                    </div>
                </div>
            </div>

            <!-- Trends Summary -->
            <div id="forecast-trends" class="forecast-section" style="display: none;">
                <h3><?php p($l->t('Monthly Trends')); ?></h3>
                <div class="trends-grid">
                    <div class="trend-card income">
                        <span class="trend-label"><?php p($l->t('Avg Monthly Income')); ?></span>
                        <span class="trend-value" id="avg-income">--</span>
                        <span class="trend-direction" id="income-direction"></span>
                    </div>
                    <div class="trend-card expenses">
                        <span class="trend-label"><?php p($l->t('Avg Monthly Expenses')); ?></span>
                        <span class="trend-value" id="avg-expenses">--</span>
                        <span class="trend-direction" id="expense-direction"></span>
                    </div>
                    <div class="trend-card savings">
                        <span class="trend-label"><?php p($l->t('Avg Monthly Savings')); ?></span>
                        <span class="trend-value" id="avg-savings">--</span>
                        <span class="trend-direction" id="savings-direction"></span>
                    </div>
                </div>
            </div>

            <!-- Savings Projection (Chart + Numbers) -->
            <div id="forecast-savings" class="forecast-section" style="display: none;">
                <h3><?php p($l->t('Savings Projection')); ?></h3>
                <div class="savings-container">
                    <div class="savings-chart">
                        <canvas id="savings-chart"></canvas>
                    </div>
                    <div class="savings-summary">
                        <div class="savings-stat">
                            <span class="stat-label"><?php p($l->t('Current Monthly Savings')); ?></span>
                            <span class="stat-value" id="current-monthly-savings">--</span>
                        </div>
                        <div class="savings-stat">
                            <span class="stat-label"><?php p($l->t('Projected Total Savings')); ?></span>
                            <span class="stat-value" id="projected-total-savings">--</span>
                        </div>
                        <div class="savings-stat">
                            <span class="stat-label"><?php p($l->t('Savings Rate')); ?></span>
                            <span class="stat-value" id="savings-rate">--</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Balance Projection Chart -->
            <div id="forecast-chart" class="forecast-section" style="display: none;">
                <h3><?php p($l->t('Balance Projection')); ?></h3>
                <div class="chart-container">
                    <canvas id="balance-projection-chart"></canvas>
                </div>
            </div>

            <!-- Category Spending Trends -->
            <div id="forecast-categories" class="forecast-section" style="display: none;">
                <h3><?php p($l->t('Spending by Category')); ?></h3>
                <div id="category-trends-list" class="category-trends"></div>
            </div>

            <!-- Data Quality Indicator -->
            <div id="forecast-quality" class="forecast-section" style="display: none;">
                <div class="quality-indicator">
                    <span class="quality-label"><?php p($l->t('Forecast Confidence:')); ?></span>
                    <span class="quality-value" id="forecast-confidence">--</span>
                    <span class="quality-info" id="data-info">--</span>
                </div>
            </div>
        </div>
        
        <!-- Reports View -->
        <div id="reports-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Financial Reports')); ?></h2>
                <div class="view-controls">
                    <button id="export-csv-btn" class="secondary" title="<?php p($l->t('Export as CSV')); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/></svg>
                        CSV
                    </button>
                    <button id="export-pdf-btn" class="secondary" title="<?php p($l->t('Export as PDF')); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/></svg>
                        PDF
                    </button>
                </div>
            </div>

            <!-- Report Controls -->
            <div class="reports-controls">
                <div class="control-group">
                    <label for="report-type"><?php p($l->t('Report Type')); ?></label>
                    <select id="report-type" class="report-select">
                        <option value="summary"><?php p($l->t('Summary Dashboard')); ?></option>
                        <option value="spending"><?php p($l->t('Spending by Category')); ?></option>
                        <option value="cashflow"><?php p($l->t('Cash Flow')); ?></option>
                        <option value="yoy"><?php p($l->t('Year over Year')); ?></option>
                        <option value="bills-calendar"><?php p($l->t('Bills Calendar')); ?></option>
                    </select>
                </div>

                <div class="control-group">
                    <label for="report-period-preset"><?php p($l->t('Period')); ?></label>
                    <select id="report-period-preset" class="report-select">
                        <option value="this-month"><?php p($l->t('This Month')); ?></option>
                        <option value="last-3-months" selected><?php p($l->t('Last 3 Months')); ?></option>
                        <option value="ytd"><?php p($l->t('Year to Date')); ?></option>
                        <option value="last-year"><?php p($l->t('Last Year')); ?></option>
                        <option value="custom"><?php p($l->t('Custom Range')); ?></option>
                    </select>
                </div>

                <div id="custom-date-range" class="control-group custom-range" style="display: none;">
                    <label><?php p($l->t('Custom Range')); ?></label>
                    <div class="date-range-inputs">
                        <input type="date" id="report-start-date" aria-label="<?php p($l->t('Start date')); ?>">
                        <span class="date-separator"><?php p($l->t('to')); ?></span>
                        <input type="date" id="report-end-date" aria-label="<?php p($l->t('End date')); ?>">
                    </div>
                </div>

                <div class="control-group">
                    <label for="report-account"><?php p($l->t('Account')); ?></label>
                    <select id="report-account" class="report-select">
                        <option value=""><?php p($l->t('All Accounts')); ?></option>
                    </select>
                </div>

                <div class="control-group">
                    <label for="report-tags-input"><?php p($l->t('Filter by Tags')); ?></label>
                    <div id="report-tags-filter" class="tags-autocomplete">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>

                <div class="control-group">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="report-include-untagged">
                        <span><?php p($l->t('Include untagged transactions')); ?></span>
                    </label>
                </div>

                <button id="generate-report-btn" class="primary"><?php p($l->t('Generate Report')); ?></button>
            </div>

            <!-- Report Loading State -->
            <div id="report-loading" class="loading-state" style="display: none;">
                <div class="loading-spinner"></div>
                <p><?php p($l->t('Generating report...')); ?></p>
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
                                <span class="summary-label"><?php p($l->t('Total Income')); ?></span>
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
                                <span class="summary-label"><?php p($l->t('Total Expenses')); ?></span>
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
                                <span class="summary-label"><?php p($l->t('Net Income')); ?></span>
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
                                <span class="summary-label"><?php p($l->t('Savings Rate')); ?></span>
                                <span id="report-savings-rate" class="summary-value">--</span>
                            </div>
                        </div>
                    </div>

                    <!-- Trend Chart -->
                    <div class="dashboard-card dashboard-card-large">
                        <div class="card-header">
                            <h3><?php p($l->t('Income vs Expenses Trend')); ?></h3>
                        </div>
                        <div class="chart-container chart-container-large">
                            <canvas id="report-trend-chart"></canvas>
                        </div>
                    </div>

                    <!-- Account Breakdown Table -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3><?php p($l->t('Account Breakdown')); ?></h3>
                        </div>
                        <div class="table-responsive">
                            <table id="report-accounts-table" class="data-table">
                                <thead>
                                    <tr>
                                        <th><?php p($l->t('Account')); ?></th>
                                        <th class="text-right"><?php p($l->t('Income')); ?></th>
                                        <th class="text-right"><?php p($l->t('Expenses')); ?></th>
                                        <th class="text-right"><?php p($l->t('Net')); ?></th>
                                        <th class="text-right"><?php p($l->t('Balance')); ?></th>
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
                                <h3><?php p($l->t('Spending by Category')); ?></h3>
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
                                <h3><?php p($l->t('Category Breakdown')); ?></h3>
                            </div>
                            <div class="table-responsive">
                                <table id="report-categories-table" class="data-table">
                                    <thead>
                                        <tr>
                                            <th><?php p($l->t('Category')); ?></th>
                                            <th class="text-right"><?php p($l->t('Amount')); ?></th>
                                            <th class="text-right"><?php p($l->t('%% of Total')); ?></th>
                                            <th class="text-right"><?php p($l->t('Transactions')); ?></th>
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
                            <h3><?php p($l->t('Top Vendors')); ?></h3>
                        </div>
                        <div class="table-responsive">
                            <table id="report-vendors-table" class="data-table">
                                <thead>
                                    <tr>
                                        <th><?php p($l->t('Vendor')); ?></th>
                                        <th class="text-right"><?php p($l->t('Amount')); ?></th>
                                        <th class="text-right"><?php p($l->t('Transactions')); ?></th>
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
                                <span class="summary-label"><?php p($l->t('Avg Monthly Income')); ?></span>
                                <span id="report-avg-income" class="summary-value">--</span>
                            </div>
                        </div>
                        <div class="summary-card summary-card-expenses">
                            <div class="summary-content">
                                <span class="summary-label"><?php p($l->t('Avg Monthly Expenses')); ?></span>
                                <span id="report-avg-expenses" class="summary-value">--</span>
                            </div>
                        </div>
                        <div class="summary-card summary-card-net">
                            <div class="summary-content">
                                <span class="summary-label"><?php p($l->t('Avg Monthly Savings')); ?></span>
                                <span id="report-avg-net" class="summary-value">--</span>
                            </div>
                        </div>
                    </div>

                    <!-- Cash Flow Chart -->
                    <div class="dashboard-card dashboard-card-large">
                        <div class="card-header">
                            <h3><?php p($l->t('Monthly Cash Flow')); ?></h3>
                        </div>
                        <div class="chart-container chart-container-large">
                            <canvas id="report-cashflow-chart"></canvas>
                        </div>
                    </div>

                    <!-- Cash Flow Table -->
                    <div class="dashboard-card">
                        <div class="card-header">
                            <h3><?php p($l->t('Monthly Breakdown')); ?></h3>
                        </div>
                        <div class="table-responsive">
                            <table id="report-cashflow-table" class="data-table">
                                <thead>
                                    <tr>
                                        <th><?php p($l->t('Month')); ?></th>
                                        <th class="text-right"><?php p($l->t('Income')); ?></th>
                                        <th class="text-right"><?php p($l->t('Expenses')); ?></th>
                                        <th class="text-right"><?php p($l->t('Net')); ?></th>
                                        <th class="text-right"><?php p($l->t('Cumulative')); ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Year over Year Report -->
                <div id="report-yoy" class="report-section" style="display: none;">
                    <!-- YoY Controls -->
                    <div class="yoy-controls">
                        <div class="control-group">
                            <label for="yoy-comparison-type"><?php p($l->t('Comparison Type')); ?></label>
                            <select id="yoy-comparison-type" class="report-select">
                                <option value="years"><?php p($l->t('Full Year Comparison')); ?></option>
                                <option value="month"><?php p($l->t('Same Month Comparison')); ?></option>
                                <option value="categories"><?php p($l->t('Category Spending')); ?></option>
                            </select>
                        </div>
                        <div class="control-group">
                            <label for="yoy-years"><?php p($l->t('Years to Compare')); ?></label>
                            <select id="yoy-years" class="report-select">
                                <option value="2"><?php p($l->t('2 Years')); ?></option>
                                <option value="3" selected><?php p($l->t('3 Years')); ?></option>
                                <option value="5"><?php p($l->t('5 Years')); ?></option>
                            </select>
                        </div>
                        <div class="control-group yoy-month-select" style="display: none;">
                            <label for="yoy-month"><?php p($l->t('Month')); ?></label>
                            <select id="yoy-month" class="report-select">
                                <option value="1"><?php p($l->t('January')); ?></option>
                                <option value="2"><?php p($l->t('February')); ?></option>
                                <option value="3"><?php p($l->t('March')); ?></option>
                                <option value="4"><?php p($l->t('April')); ?></option>
                                <option value="5"><?php p($l->t('May')); ?></option>
                                <option value="6"><?php p($l->t('June')); ?></option>
                                <option value="7"><?php p($l->t('July')); ?></option>
                                <option value="8"><?php p($l->t('August')); ?></option>
                                <option value="9"><?php p($l->t('September')); ?></option>
                                <option value="10"><?php p($l->t('October')); ?></option>
                                <option value="11"><?php p($l->t('November')); ?></option>
                                <option value="12"><?php p($l->t('December')); ?></option>
                            </select>
                        </div>
                        <button id="generate-yoy-btn" class="primary"><?php p($l->t('Compare')); ?></button>
                    </div>

                    <!-- YoY Summary Cards -->
                    <div id="yoy-summary" class="yoy-summary" style="display: none;">
                        <div class="yoy-year-cards" id="yoy-year-cards">
                            <!-- Year comparison cards will be inserted here -->
                        </div>
                    </div>

                    <!-- YoY Chart -->
                    <div id="yoy-chart-container" class="dashboard-card" style="display: none;">
                        <div class="card-header">
                            <h3 id="yoy-chart-title"><?php p($l->t('Income & Expenses by Year')); ?></h3>
                        </div>
                        <div class="chart-container chart-container-large">
                            <canvas id="yoy-chart"></canvas>
                        </div>
                    </div>

                    <!-- YoY Category Table -->
                    <div id="yoy-category-table-container" class="dashboard-card" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Category Spending by Year')); ?></h3>
                        </div>
                        <div class="table-responsive">
                            <table id="yoy-category-table" class="data-table">
                                <thead>
                                    <tr id="yoy-category-header">
                                        <th><?php p($l->t('Category')); ?></th>
                                        <!-- Year columns will be added dynamically -->
                                    </tr>
                                </thead>
                                <tbody id="yoy-category-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Bills Calendar Report -->
                <div id="report-bills-calendar" class="report-section" style="display: none;">
                    <!-- Bills Calendar Controls -->
                    <div class="bills-calendar-controls">
                        <div class="control-group">
                            <label for="bills-calendar-year"><?php p($l->t('Year')); ?></label>
                            <select id="bills-calendar-year" class="report-select">
                                <!-- Years will be populated by JavaScript -->
                            </select>
                        </div>
                        <div class="control-group">
                            <label for="bills-calendar-status"><?php p($l->t('Bill Status')); ?></label>
                            <select id="bills-calendar-status" class="report-select">
                                <option value="active" selected><?php p($l->t('Active Only')); ?></option>
                                <option value="inactive"><?php p($l->t('Inactive Only')); ?></option>
                                <option value="all"><?php p($l->t('All Bills')); ?></option>
                            </select>
                        </div>
                        <div class="control-group">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="bills-calendar-include-transfers" checked>
                                <span><?php p($l->t('Include Recurring Transfers')); ?></span>
                            </label>
                        </div>
                        <div class="control-group">
                            <label for="bills-calendar-account"><?php p($l->t('Account')); ?></label>
                            <select id="bills-calendar-account" class="report-select">
                                <option value=""><?php p($l->t('All Accounts')); ?></option>
                            </select>
                        </div>
                        <div class="control-group">
                            <label for="bills-calendar-view"><?php p($l->t('View')); ?></label>
                            <select id="bills-calendar-view" class="report-select">
                                <option value="table" selected><?php p($l->t('Table View')); ?></option>
                                <option value="heatmap"><?php p($l->t('Calendar Heatmap')); ?></option>
                            </select>
                        </div>
                        <button id="generate-bills-calendar-btn" class="primary"><?php p($l->t('Generate')); ?></button>
                    </div>

                    <!-- Monthly Totals Chart -->
                    <div id="bills-calendar-chart-container" class="dashboard-card" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Monthly Bill Totals')); ?></h3>
                        </div>
                        <div class="chart-container chart-container-large">
                            <canvas id="bills-calendar-chart"></canvas>
                        </div>
                    </div>

                    <!-- Table View -->
                    <div id="bills-calendar-table-container" class="dashboard-card" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Bills by Month')); ?></h3>
                        </div>
                        <div class="table-responsive">
                            <table id="bills-calendar-table" class="data-table bills-calendar-table">
                                <thead>
                                    <tr>
                                        <th class="bill-name-col"><?php p($l->t('Bill')); ?></th>
                                        <th><?php p($l->t('Jan')); ?></th>
                                        <th><?php p($l->t('Feb')); ?></th>
                                        <th><?php p($l->t('Mar')); ?></th>
                                        <th><?php p($l->t('Apr')); ?></th>
                                        <th><?php p($l->t('May')); ?></th>
                                        <th><?php p($l->t('Jun')); ?></th>
                                        <th><?php p($l->t('Jul')); ?></th>
                                        <th><?php p($l->t('Aug')); ?></th>
                                        <th><?php p($l->t('Sep')); ?></th>
                                        <th><?php p($l->t('Oct')); ?></th>
                                        <th><?php p($l->t('Nov')); ?></th>
                                        <th><?php p($l->t('Dec')); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="bills-calendar-table-body"></tbody>
                                <tfoot id="bills-calendar-table-footer">
                                    <!-- Monthly totals will be added here -->
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Heatmap View -->
                    <div id="bills-calendar-heatmap-container" class="dashboard-card" style="display: none;">
                        <div class="card-header">
                            <h3><?php p($l->t('Bills Calendar Heatmap')); ?></h3>
                        </div>
                        <div id="bills-calendar-heatmap" class="bills-calendar-heatmap">
                            <!-- Heatmap will be rendered here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pensions View -->
        <div id="pensions-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Pensions')); ?></h2>
                <button id="add-pension-btn" class="primary" aria-label="<?php p($l->t('Add new pension')); ?>">
                    <span class="icon-add" aria-hidden="true"></span>
                    <?php p($l->t('Add Pension')); ?>
                </button>
            </div>

            <!-- Info Notice -->
            <div class="pensions-notice" style="background-color: rgba(100, 116, 139, 0.08); border: 1px solid rgba(100, 116, 139, 0.15); border-radius: 4px; padding: 12px 16px; margin: 16px 0; display: flex; align-items: flex-start; gap: 12px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="rgba(100, 116, 139, 0.6)" style="flex-shrink: 0; margin-top: 2px;">
                    <path d="M11,9H13V7H11M12,20C7.59,20 4,16.41 4,12C4,7.59 7.59,4 12,4C16.41,4 20,7.59 20,12C20,16.41 16.41,20 12,20M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M11,17H13V11H11V17Z"/>
                </svg>
                <div style="color: rgba(100, 116, 139, 0.85); font-size: 14px;">
                    <strong><?php p($l->t('Note:')); ?></strong> <?php p($l->t('Pension projections and figures are estimates based on the information provided and assumed growth rates. Actual pension values may vary depending on market performance, contribution changes, and other factors. Please consult with a financial advisor for accurate retirement planning.')); ?>
                </div>
            </div>

            <!-- Pensions Summary Cards -->
            <div class="pensions-summary">
                <div class="summary-card">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4Z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="pensions-total-worth">--</div>
                        <div class="summary-label"><?php p($l->t('Total Pension Worth')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M16,6L18.29,8.29L13.41,13.17L9.41,9.17L2,16.59L3.41,18L9.41,12L13.41,16L19.71,9.71L22,12V6H16Z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="pensions-projected-value">--</div>
                        <div class="summary-label"><?php p($l->t('Projected at Retirement')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12,2A10,10 0 0,1 22,12A10,10 0 0,1 12,22A10,10 0 0,1 2,12A10,10 0 0,1 12,2M12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20A8,8 0 0,0 20,12A8,8 0 0,0 12,4M12,6A6,6 0 0,1 18,12A6,6 0 0,1 12,18A6,6 0 0,1 6,12A6,6 0 0,1 12,6M12,8A4,4 0 0,0 8,12A4,4 0 0,0 12,16A4,4 0 0,0 16,12A4,4 0 0,0 12,8Z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="pensions-projected-income">--</div>
                        <div class="summary-label"><?php p($l->t('Projected Annual Income')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19,3H14.82C14.4,1.84 13.3,1 12,1C10.7,1 9.6,1.84 9.18,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M12,3A1,1 0 0,1 13,4A1,1 0 0,1 12,5A1,1 0 0,1 11,4A1,1 0 0,1 12,3"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="pensions-count">0</div>
                        <div class="summary-label"><?php p($l->t('Pension Accounts')); ?></div>
                    </div>
                </div>
            </div>

            <!-- Pensions List -->
            <div class="pensions-container">
                <div id="pensions-list" class="pensions-list">
                    <!-- Pension cards will be rendered here -->
                </div>

                <div class="empty-pensions" id="empty-pensions" style="display: none;">
                    <div class="empty-content">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="currentColor" style="opacity: 0.5;">
                            <path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4M12,6A6,6 0 0,0 6,12A6,6 0 0,0 12,18A6,6 0 0,0 18,12A6,6 0 0,0 12,6M12,8A4,4 0 0,1 16,12A4,4 0 0,1 12,16A4,4 0 0,1 8,12A4,4 0 0,1 12,8Z"/>
                        </svg>
                        <h3><?php p($l->t('No pensions yet')); ?></h3>
                        <p><?php p($l->t('Track your pension accounts to visualize your retirement savings and projections.')); ?></p>
                        <button class="primary" id="empty-pensions-add-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Add Your First Pension')); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Pension Detail Panel (shown when a pension is selected) -->
            <div id="pension-detail-panel" class="pension-detail-panel" style="display: none;">
                <div class="panel-header">
                    <h3 id="pension-detail-name"><?php p($l->t('Pension Details')); ?></h3>
                    <div class="panel-actions">
                        <button id="pension-edit-btn" class="icon-button" title="<?php p($l->t('Edit pension')); ?>">
                            <span class="icon-rename" aria-hidden="true"></span>
                        </button>
                        <button id="pension-close-btn" class="icon-button" title="<?php p($l->t('Close')); ?>">
                            <span class="icon-close" aria-hidden="true"></span>
                        </button>
                    </div>
                </div>
                <div class="panel-content">
                    <div class="pension-detail-summary">
                        <div class="detail-item">
                            <span class="detail-label"><?php p($l->t('Current Balance')); ?></span>
                            <span class="detail-value" id="pension-detail-balance">--</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label"><?php p($l->t('Monthly Contribution')); ?></span>
                            <span class="detail-value" id="pension-detail-contribution">--</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label"><?php p($l->t('Expected Return')); ?></span>
                            <span class="detail-value" id="pension-detail-return">--</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label"><?php p($l->t('Retirement Age')); ?></span>
                            <span class="detail-value" id="pension-detail-age">--</span>
                        </div>
                    </div>

                    <div class="pension-detail-actions">
                        <button id="update-balance-btn" class="secondary">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Update Balance')); ?>
                        </button>
                        <button id="add-contribution-btn" class="secondary">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Log Contribution')); ?>
                        </button>
                    </div>

                    <!-- Balance History Chart -->
                    <div class="pension-chart-section">
                        <h4><?php p($l->t('Balance History')); ?></h4>
                        <div class="chart-container">
                            <canvas id="pension-balance-chart"></canvas>
                        </div>
                    </div>

                    <!-- Projection Chart -->
                    <div class="pension-chart-section">
                        <h4><?php p($l->t('Projected Growth')); ?></h4>
                        <div class="chart-container">
                            <canvas id="pension-projection-chart"></canvas>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="pension-activity-section">
                        <h4><?php p($l->t('Recent Activity')); ?></h4>
                        <div id="pension-activity-list" class="activity-list">
                            <!-- Activity items rendered here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pension Modal (Add/Edit) -->
        <div id="pension-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="pension-modal-title" aria-hidden="true">
            <div class="modal-content">
                <div class="pension-modal-header">
                    <div class="pension-modal-title-row">
                        <div class="pension-modal-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4M12,6A6,6 0 0,0 6,12A6,6 0 0,0 12,18A6,6 0 0,0 18,12A6,6 0 0,0 12,6M12,8A4,4 0 0,1 16,12A4,4 0 0,1 12,16A4,4 0 0,1 8,12A4,4 0 0,1 12,8Z"/>
                            </svg>
                        </div>
                        <h3 id="pension-modal-title"><?php p($l->t('Add Pension')); ?></h3>
                    </div>
                    <button class="modal-close cancel-btn" aria-label="<?php p($l->t('Close')); ?>">&times;</button>
                </div>
                <form id="pension-form">
                    <input type="hidden" id="pension-id" name="id" value="">

                    <!-- Basic Information -->
                    <div class="form-section">
                        <h4><?php p($l->t('Basic Information')); ?></h4>

                        <div class="form-group">
                            <label for="pension-name"><?php p($l->t('Pension Name')); ?> <span class="required">*</span></label>
                            <input type="text" id="pension-name" name="name" required placeholder="<?php p($l->t('e.g., Company Pension, Vanguard SIPP')); ?>" maxlength="255">
                            <small class="form-text"><?php p($l->t('A descriptive name for this pension')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="pension-type"><?php p($l->t('Pension Type')); ?> <span class="required">*</span></label>
                            <select id="pension-type" name="type" required>
                                <option value="workplace"><?php p($l->t('Workplace Pension')); ?></option>
                                <option value="personal"><?php p($l->t('Personal Pension')); ?></option>
                                <option value="sipp"><?php p($l->t('SIPP')); ?></option>
                                <option value="defined_benefit"><?php p($l->t('Defined Benefit')); ?></option>
                                <option value="state"><?php p($l->t('State Pension')); ?></option>
                            </select>
                            <small class="form-text"><?php p($l->t('Determines which fields are available below')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="pension-provider"><?php p($l->t('Provider')); ?></label>
                            <input type="text" id="pension-provider" name="provider" placeholder="<?php p($l->t('e.g., Scottish Widows, Aviva')); ?>" maxlength="255">
                            <small class="form-text"><?php p($l->t('Pension provider or scheme administrator')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="pension-currency"><?php p($l->t('Currency')); ?></label>
                            <select id="pension-currency" name="currency">
                                <!-- Populated dynamically from /api/settings/options -->
                            </select>
                        </div>
                    </div>

                    <!-- DC Pension Fields -->
                    <div id="dc-pension-fields" class="form-section pension-fields-section">
                        <h4><?php p($l->t('Financial Details')); ?></h4>

                        <div class="form-group">
                            <label for="pension-balance"><?php p($l->t('Current Balance')); ?></label>
                            <input type="number" id="pension-balance" name="currentBalance" min="0" step="0.01" placeholder="0.00">
                            <small class="form-text"><?php p($l->t('Current total value of the pension pot')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="pension-monthly"><?php p($l->t('Monthly Contribution')); ?></label>
                            <input type="number" id="pension-monthly" name="monthlyContribution" min="0" step="0.01" placeholder="0.00">
                            <small class="form-text"><?php p($l->t('Combined employee and employer contribution')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="pension-return"><?php p($l->t('Expected Annual Return (%%)')); ?></label>
                            <input type="number" id="pension-return" name="expectedReturnRate" min="0" max="100" step="0.1" placeholder="5.0">
                            <small class="form-text"><?php p($l->t('Estimated yearly growth rate for projections')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="pension-retirement-age"><?php p($l->t('Retirement Age')); ?></label>
                            <input type="number" id="pension-retirement-age" name="retirementAge" min="18" max="100" placeholder="65">
                            <small class="form-text"><?php p($l->t('Age you plan to start drawing this pension')); ?></small>
                        </div>
                    </div>

                    <!-- DB/State Pension Fields -->
                    <div id="db-pension-fields" class="form-section pension-fields-section" style="display: none;">
                        <h4><?php p($l->t('Income Details')); ?></h4>

                        <div class="form-group">
                            <label for="pension-income"><?php p($l->t('Projected Annual Income')); ?></label>
                            <input type="number" id="pension-income" name="annualIncome" min="0" step="0.01" placeholder="0.00">
                            <small class="form-text"><?php p($l->t('Expected yearly income at retirement')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="pension-transfer"><?php p($l->t('Transfer Value')); ?></label>
                            <input type="number" id="pension-transfer" name="transferValue" min="0" step="0.01" placeholder="0.00">
                            <small class="form-text"><?php p($l->t('Cash equivalent transfer value (CETV) if known')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="pension-db-retirement-age"><?php p($l->t('Retirement Age')); ?></label>
                            <input type="number" id="pension-db-retirement-age" name="retirementAge" min="18" max="100" placeholder="65">
                            <small class="form-text"><?php p($l->t('Normal retirement age for this scheme')); ?></small>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="cancel-btn"><?php p($l->t('Cancel')); ?></button>
                        <button type="submit" class="primary" id="save-pension-btn"><?php p($l->t('Save Pension')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Update Balance Modal -->
        <div id="pension-balance-modal" class="modal" style="display: none;">
            <div class="modal-content modal-small">
                <div class="modal-header">
                    <h3><?php p($l->t('Update Balance')); ?></h3>
                    <button class="modal-close cancel-btn" aria-label="<?php p($l->t('Close')); ?>">&times;</button>
                </div>
                <form id="pension-balance-form" class="modal-form">
                    <div class="form-group">
                        <label for="snapshot-balance"><?php p($l->t('Current Balance')); ?> *</label>
                        <input type="number" id="snapshot-balance" name="balance" min="0" step="0.01" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label for="snapshot-date"><?php p($l->t('Date')); ?> *</label>
                        <input type="date" id="snapshot-date" name="date" required>
                    </div>
                    <input type="hidden" id="snapshot-pension-id" name="pensionId" value="">
                    <div class="modal-actions">
                        <button type="button" class="cancel-btn"><?php p($l->t('Cancel')); ?></button>
                        <button type="submit" class="primary"><?php p($l->t('Update')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Log Contribution Modal -->
        <div id="pension-contribution-modal" class="modal" style="display: none;">
            <div class="modal-content modal-small">
                <div class="modal-header">
                    <h3><?php p($l->t('Log Contribution')); ?></h3>
                    <button class="modal-close cancel-btn" aria-label="<?php p($l->t('Close')); ?>">&times;</button>
                </div>
                <form id="pension-contribution-form" class="modal-form">
                    <div class="form-group">
                        <label for="contribution-amount"><?php p($l->t('Amount')); ?> *</label>
                        <input type="number" id="contribution-amount" name="amount" min="0.01" step="0.01" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label for="contribution-date"><?php p($l->t('Date')); ?> *</label>
                        <input type="date" id="contribution-date" name="date" required>
                    </div>
                    <div class="form-group">
                        <label for="contribution-note"><?php p($l->t('Note')); ?></label>
                        <input type="text" id="contribution-note" name="note" placeholder="<?php p($l->t('e.g., Bonus top-up')); ?>">
                    </div>
                    <input type="hidden" id="contribution-pension-id" name="pensionId" value="">
                    <div class="modal-actions">
                        <button type="button" class="cancel-btn"><?php p($l->t('Cancel')); ?></button>
                        <button type="submit" class="primary"><?php p($l->t('Log')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Assets View -->
        <div id="assets-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Assets')); ?></h2>
                <button id="add-asset-btn" class="primary" aria-label="<?php p($l->t('Add new asset')); ?>">
                    <span class="icon-add" aria-hidden="true"></span>
                    <?php p($l->t('Add Asset')); ?>
                </button>
            </div>

            <!-- Info Notice -->
            <div class="assets-notice">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M11,9H13V7H11M12,20C7.59,20 4,16.41 4,12C4,7.59 7.59,4 12,4C16.41,4 20,7.59 20,12C20,16.41 16.41,20 12,20M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M11,17H13V11H11V17Z"/>
                </svg>
                <span><?php p($l->t('Track non-cash assets like property, vehicles, and collectibles. Values and projections are estimates based on annual appreciation/depreciation rates.')); ?></span>
            </div>

            <!-- Assets Summary Cards -->
            <div class="assets-summary">
                <div class="summary-card">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M10,2V4.26L12,5.59V4H22V19H17V21H24V2H10M7.5,5L0,10V21H15V10L7.5,5M14,6V6.93L15.61,8H16V6H14M18,6V8H20V6H18M7.5,7.5L13,11V19H10V13H5V19H2V11L7.5,7.5M18,10V12H20V10H18M18,14V16H20V14H18Z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="assets-total-worth">--</div>
                        <div class="summary-label"><?php p($l->t('Total Asset Worth')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M16,6L18.29,8.29L13.41,13.17L9.41,9.17L2,16.59L3.41,18L9.41,12L13.41,16L19.71,9.71L22,12V6H16Z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="assets-projected-value">--</div>
                        <div class="summary-label"><?php p($l->t('Projected Value (10yr)')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19,3H14.82C14.4,1.84 13.3,1 12,1C10.7,1 9.6,1.84 9.18,3H5A2,2 0 0,0 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5A2,2 0 0,0 19,3M12,3A1,1 0 0,1 13,4A1,1 0 0,1 12,5A1,1 0 0,1 11,4A1,1 0 0,1 12,3"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="assets-count">0</div>
                        <div class="summary-label"><?php p($l->t('Total Assets')); ?></div>
                    </div>
                </div>
            </div>

            <div id="assets-conversion-warning" class="conversion-warning" style="display: none;"></div>

            <!-- Assets List -->
            <div class="assets-container">
                <div id="assets-list" class="assets-list">
                    <!-- Asset cards will be rendered here -->
                </div>

                <div class="empty-assets" id="empty-assets" style="display: none;">
                    <div class="empty-content">
                        <svg width="64" height="64" viewBox="0 0 24 24" fill="currentColor" style="opacity: 0.5;">
                            <path d="M10,2V4.26L12,5.59V4H22V19H17V21H24V2H10M7.5,5L0,10V21H15V10L7.5,5M14,6V6.93L15.61,8H16V6H14M18,6V8H20V6H18M7.5,7.5L13,11V19H10V13H5V19H2V11L7.5,7.5M18,10V12H20V10H18M18,14V16H20V14H18Z"/>
                        </svg>
                        <h3><?php p($l->t('No assets yet')); ?></h3>
                        <p><?php p($l->t('Track your non-cash assets like property, vehicles, and collectibles to see your full net worth.')); ?></p>
                        <button class="primary" id="empty-assets-add-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Add Your First Asset')); ?>
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- Asset Details View (separate full-page view, like account-details-view) -->
        <div id="asset-details-view" class="view" style="display: none;">
            <div class="view-header">
                <div class="breadcrumb">
                    <button id="back-to-assets-btn" class="breadcrumb-back">
                        <span class="icon-arrow-left" aria-hidden="true"></span>
                        <?php p($l->t('Assets')); ?>
                    </button>
                    <span class="breadcrumb-separator">/</span>
                    <h2 id="asset-detail-name"><?php p($l->t('Asset Details')); ?></h2>
                </div>
                <div class="view-controls">
                    <button id="update-value-btn" class="secondary" title="<?php p($l->t('Update value')); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="margin-right: 4px;"><path d="M16,6L18.29,8.29L13.41,13.17L9.41,9.17L2,16.59L3.41,18L9.41,12L13.41,16L19.71,9.71L22,12V6H16Z"/></svg>
                        <?php p($l->t('Update Value')); ?>
                    </button>
                    <button id="asset-edit-detail-btn" class="secondary" title="<?php p($l->t('Edit asset')); ?>">
                        <span class="icon-rename" aria-hidden="true"></span>
                        <?php p($l->t('Edit')); ?>
                    </button>
                </div>
            </div>

            <div class="asset-details-container">
                <!-- Asset Overview Card -->
                <div class="asset-overview-section">
                    <div class="asset-overview-card">
                        <div class="asset-overview-header">
                            <div id="asset-detail-icon" class="asset-type-icon-lg"></div>
                            <div class="asset-overview-info">
                                <h3 id="asset-detail-display-name"><?php p($l->t('Asset Name')); ?></h3>
                                <div class="asset-overview-meta">
                                    <span id="asset-detail-type-label" class="asset-type-badge-lg"></span>
                                    <span id="asset-detail-description" class="asset-overview-description"></span>
                                </div>
                            </div>
                        </div>
                        <div class="asset-balance-section">
                            <div class="balance-primary">
                                <label><?php p($l->t('Current Value')); ?></label>
                                <div id="asset-detail-value" class="balance-amount">--</div>
                            </div>
                            <div class="balance-secondary">
                                <div class="balance-item">
                                    <label><?php p($l->t('Purchase Price')); ?></label>
                                    <div id="asset-detail-purchase-price" class="balance-amount">--</div>
                                </div>
                                <div class="balance-item">
                                    <label><?php p($l->t('Gain / Loss')); ?></label>
                                    <div id="asset-detail-gain-loss" class="balance-amount">--</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Asset Metrics -->
                    <div class="asset-metrics-grid">
                        <div class="metric-card">
                            <div class="metric-icon" style="background: rgba(46, 125, 50, 0.1); color: #2e7d32;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M16,6L18.29,8.29L13.41,13.17L9.41,9.17L2,16.59L3.41,18L9.41,12L13.41,16L19.71,9.71L22,12V6H16Z"/></svg>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="asset-detail-rate">--</div>
                                <div class="metric-label"><?php p($l->t('Annual Change Rate')); ?></div>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-icon" style="background: rgba(21, 101, 192, 0.1); color: #1565c0;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M9,10V12H7V10H9M13,10V12H11V10H13M17,10V12H15V10H17M19,3A2,2 0 0,1 21,5V19A2,2 0 0,1 19,21H5C3.89,21 3,20.1 3,19V5A2,2 0 0,1 5,3H6V1H8V3H16V1H18V3H19M19,19V8H5V19H19M9,14V16H7V14H9M13,14V16H11V14H13M17,14V16H15V14H17Z"/></svg>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="asset-detail-purchase-date">--</div>
                                <div class="metric-label"><?php p($l->t('Purchase Date')); ?></div>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-icon" style="background: rgba(123, 31, 162, 0.1); color: #7b1fa2;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20M12,6A6,6 0 0,0 6,12A6,6 0 0,0 12,18A6,6 0 0,0 18,12A6,6 0 0,0 12,6M12,16A4,4 0 0,1 8,12A4,4 0 0,1 12,8A4,4 0 0,1 16,12A4,4 0 0,1 12,16Z"/></svg>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="asset-detail-projected">--</div>
                                <div class="metric-label"><?php p($l->t('10yr Projected Value')); ?></div>
                            </div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-icon" style="background: rgba(245, 127, 23, 0.1); color: #f57f17;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M11,17H13V11H11V17M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20M11,9H13V7H11V9Z"/></svg>
                            </div>
                            <div class="metric-content">
                                <div class="metric-value" id="asset-detail-snapshots">0</div>
                                <div class="metric-label"><?php p($l->t('Value Snapshots')); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="asset-charts-row">
                    <div class="asset-chart-section">
                        <h4><?php p($l->t('Value History')); ?></h4>
                        <div class="chart-container">
                            <canvas id="asset-value-chart"></canvas>
                        </div>
                    </div>
                    <div class="asset-chart-section">
                        <h4><?php p($l->t('Projected Value')); ?></h4>
                        <div class="chart-container">
                            <canvas id="asset-projection-chart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Asset Modal (Add/Edit) -->
        <div id="asset-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="asset-modal-title" aria-hidden="true">
            <div class="modal-content">
                <div class="asset-modal-header">
                    <div class="asset-modal-title-row">
                        <div class="asset-modal-icon">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M10,2V4.26L12,5.59V4H22V19H17V21H24V2H10M7.5,5L0,10V21H15V10L7.5,5M14,6V6.93L15.61,8H16V6H14M18,6V8H20V6H18M7.5,7.5L13,11V19H10V13H5V19H2V11L7.5,7.5M18,10V12H20V10H18M18,14V16H20V14H18Z"/>
                            </svg>
                        </div>
                        <h3 id="asset-modal-title"><?php p($l->t('Add Asset')); ?></h3>
                    </div>
                    <button class="modal-close cancel-btn" aria-label="<?php p($l->t('Close')); ?>">&times;</button>
                </div>
                <form id="asset-form">
                    <input type="hidden" id="asset-id" name="id" value="">

                    <!-- Asset Identity -->
                    <div class="form-section">
                        <h4><?php p($l->t('Asset Details')); ?></h4>

                        <div class="form-group">
                            <label for="asset-name"><?php p($l->t('Name')); ?> <span class="required">*</span></label>
                            <input type="text" id="asset-name" name="name" required placeholder="<?php p($l->t('e.g., 42 Maple Street, Tesla Model 3')); ?>" maxlength="255">
                            <small class="form-text"><?php p($l->t('A descriptive name for this asset')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="asset-type"><?php p($l->t('Type')); ?> <span class="required">*</span></label>
                            <select id="asset-type" name="type" required>
                                <option value="real_estate"><?php p($l->t('Real Estate')); ?></option>
                                <option value="vehicle"><?php p($l->t('Vehicle')); ?></option>
                                <option value="jewelry"><?php p($l->t('Jewelry')); ?></option>
                                <option value="collectibles"><?php p($l->t('Collectibles')); ?></option>
                                <option value="other"><?php p($l->t('Other')); ?></option>
                            </select>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="asset-description"><?php p($l->t('Description')); ?></label>
                            <textarea id="asset-description" name="description" rows="2" placeholder="<?php p($l->t('Optional notes about this asset')); ?>"></textarea>
                        </div>
                    </div>

                    <!-- Valuation -->
                    <div class="form-section">
                        <h4><?php p($l->t('Valuation')); ?></h4>

                        <div class="form-group">
                            <label for="asset-current-value"><?php p($l->t('Current Value')); ?></label>
                            <input type="number" id="asset-current-value" name="currentValue" step="0.01" min="0" placeholder="0.00">
                            <small class="form-text"><?php p($l->t("Today's estimated market value")); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="asset-currency"><?php p($l->t('Currency')); ?></label>
                            <select id="asset-currency" name="currency">
                                <!-- Populated dynamically from /api/settings/options -->
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="asset-purchase-price"><?php p($l->t('Purchase Price')); ?></label>
                            <input type="number" id="asset-purchase-price" name="purchasePrice" step="0.01" min="0" placeholder="0.00">
                            <small class="form-text"><?php p($l->t('Original acquisition cost')); ?></small>
                        </div>

                        <div class="form-group">
                            <label for="asset-purchase-date"><?php p($l->t('Purchase Date')); ?></label>
                            <input type="date" id="asset-purchase-date" name="purchaseDate">
                        </div>
                    </div>

                    <!-- Growth -->
                    <div class="form-section">
                        <h4><?php p($l->t('Growth / Depreciation')); ?></h4>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="asset-annual-change-rate"><?php p($l->t('Annual Change Rate (%%)')); ?></label>
                            <input type="number" id="asset-annual-change-rate" name="annualChangeRate" step="0.1" placeholder="<?php p($l->t('e.g., 3.5')); ?>">
                            <small class="form-text"><?php p($l->t('Positive for appreciation (e.g., 3.5), negative for depreciation (e.g., -15). Used for value projections.')); ?></small>
                        </div>
                    </div>

                    <div class="modal-actions">
                        <button type="button" class="cancel-btn"><?php p($l->t('Cancel')); ?></button>
                        <button type="submit" class="primary" id="save-asset-btn"><?php p($l->t('Save Asset')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Asset Value Update Modal -->
        <div id="asset-value-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="asset-value-modal-title" aria-hidden="true">
            <div class="modal-content">
                <div class="asset-modal-header">
                    <div class="asset-modal-title-row">
                        <div class="asset-modal-icon asset-modal-icon-update">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M16,6L18.29,8.29L13.41,13.17L9.41,9.17L2,16.59L3.41,18L9.41,12L13.41,16L19.71,9.71L22,12V6H16Z"/>
                            </svg>
                        </div>
                        <h3 id="asset-value-modal-title"><?php p($l->t('Update Value')); ?></h3>
                    </div>
                    <button class="modal-close cancel-btn" aria-label="<?php p($l->t('Close')); ?>">&times;</button>
                </div>
                <form id="asset-value-form">
                    <div class="asset-value-form-body">
                        <p class="asset-value-hint"><?php p($l->t('Record a new valuation for this asset. This creates a snapshot in the value history.')); ?></p>
                        <div class="form-group">
                            <label for="asset-value-date"><?php p($l->t('Date')); ?> <span class="required">*</span></label>
                            <input type="date" id="asset-value-date" name="date" required>
                        </div>
                        <div class="form-group">
                            <label for="asset-value-amount"><?php p($l->t('Value')); ?> <span class="required">*</span></label>
                            <input type="number" id="asset-value-amount" name="value" step="0.01" min="0" required placeholder="0.00">
                        </div>
                        <input type="hidden" id="asset-value-asset-id" name="assetId" value="">
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="cancel-btn"><?php p($l->t('Cancel')); ?></button>
                        <button type="submit" class="primary"><?php p($l->t('Update Value')); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Debt Payoff View -->
        <div id="debt-payoff-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Debt Payoff Planner')); ?></h2>
            </div>

            <!-- Debt Summary Cards -->
            <div class="debt-summary-header">
                <div class="summary-card summary-card-debt">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <span class="summary-label"><?php p($l->t('Total Debt')); ?></span>
                        <span id="debt-view-total" class="summary-value">--</span>
                    </div>
                </div>
                <div class="summary-card summary-card-rate">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.31-8.86c-1.77-.45-2.34-.94-2.34-1.67 0-.84.79-1.43 2.1-1.43 1.38 0 1.9.66 1.94 1.64h1.71c-.05-1.34-.87-2.57-2.49-2.97V5H10.9v1.69c-1.51.32-2.72 1.3-2.72 2.81 0 1.79 1.49 2.69 3.66 3.21 1.95.46 2.34 1.15 2.34 1.87 0 .53-.39 1.39-2.1 1.39-1.6 0-2.23-.72-2.32-1.64H8.04c.1 1.7 1.36 2.66 2.86 2.97V19h2.34v-1.67c1.52-.29 2.72-1.16 2.73-2.77-.01-2.2-1.9-2.96-3.66-3.42z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <span class="summary-label"><?php p($l->t('Highest Rate')); ?></span>
                        <span id="debt-view-highest-rate" class="summary-value">--</span>
                    </div>
                </div>
                <div class="summary-card summary-card-payment">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19 14V6c0-1.1-.9-2-2-2H3c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zm-2 0H3V6h14v8zm-7-7c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3zm13 0v11c0 1.1-.9 2-2 2H4v-2h17V7h2z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <span class="summary-label"><?php p($l->t('Monthly Minimum')); ?></span>
                        <span id="debt-view-minimum" class="summary-value">--</span>
                    </div>
                </div>
                <div class="summary-card summary-card-count">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <span class="summary-label"><?php p($l->t('Debt Accounts')); ?></span>
                        <span id="debt-view-count" class="summary-value">--</span>
                    </div>
                </div>
            </div>

            <!-- Strategy Selection -->
            <div class="debt-strategy-section">
                <div class="debt-controls">
                    <div class="debt-control-group">
                        <label for="debt-strategy-select"><?php p($l->t('Payoff Strategy')); ?></label>
                        <select id="debt-strategy-select" class="debt-select">
                            <option value="avalanche"><?php p($l->t('Avalanche (Highest Interest First)')); ?></option>
                            <option value="snowball"><?php p($l->t('Snowball (Smallest Balance First)')); ?></option>
                        </select>
                    </div>
                    <div class="debt-control-group">
                        <label for="debt-extra-payment"><?php p($l->t('Extra Monthly Payment')); ?></label>
                        <div class="input-with-prefix">
                            <span class="input-prefix">£</span>
                            <input type="number" id="debt-extra-payment" min="0" step="10" value="0" placeholder="0">
                        </div>
                    </div>
                    <button id="calculate-payoff-btn" class="primary"><?php p($l->t('Calculate Plan')); ?></button>
                    <button id="compare-strategies-btn" class="secondary"><?php p($l->t('Compare Strategies')); ?></button>
                </div>
            </div>

            <!-- Payoff Plan Results -->
            <div id="debt-payoff-results" class="debt-payoff-results" style="display: none;">
                <div class="payoff-summary-cards">
                    <div class="payoff-card payoff-months">
                        <span class="payoff-card-label"><?php p($l->t('Time to Debt Free')); ?></span>
                        <span id="payoff-months" class="payoff-card-value">--</span>
                        <span id="payoff-date" class="payoff-card-date"></span>
                    </div>
                    <div class="payoff-card payoff-interest">
                        <span class="payoff-card-label"><?php p($l->t('Total Interest')); ?></span>
                        <span id="payoff-total-interest" class="payoff-card-value">--</span>
                    </div>
                    <div class="payoff-card payoff-total">
                        <span class="payoff-card-label"><?php p($l->t('Total Paid')); ?></span>
                        <span id="payoff-total-paid" class="payoff-card-value">--</span>
                    </div>
                </div>

                <div class="payoff-details">
                    <h3><?php p($l->t('Payoff Order')); ?></h3>
                    <div id="debt-payoff-order" class="debt-payoff-order"></div>
                </div>
            </div>

            <!-- Strategy Comparison -->
            <div id="debt-comparison-results" class="debt-comparison-results" style="display: none;">
                <h3><?php p($l->t('Strategy Comparison')); ?></h3>
                <div class="comparison-cards">
                    <div class="comparison-card" id="avalanche-comparison">
                        <h4><?php p($l->t('Debt Avalanche')); ?></h4>
                        <p class="strategy-desc"><?php p($l->t('Pay highest interest rates first')); ?></p>
                        <div class="comparison-stats">
                            <div class="comparison-stat">
                                <span class="stat-label"><?php p($l->t('Months')); ?></span>
                                <span id="avalanche-months" class="stat-value">--</span>
                            </div>
                            <div class="comparison-stat">
                                <span class="stat-label"><?php p($l->t('Interest')); ?></span>
                                <span id="avalanche-interest" class="stat-value">--</span>
                            </div>
                        </div>
                    </div>
                    <div class="comparison-card" id="snowball-comparison">
                        <h4><?php p($l->t('Debt Snowball')); ?></h4>
                        <p class="strategy-desc"><?php p($l->t('Pay smallest balances first')); ?></p>
                        <div class="comparison-stats">
                            <div class="comparison-stat">
                                <span class="stat-label"><?php p($l->t('Months')); ?></span>
                                <span id="snowball-months" class="stat-value">--</span>
                            </div>
                            <div class="comparison-stat">
                                <span class="stat-label"><?php p($l->t('Interest')); ?></span>
                                <span id="snowball-interest" class="stat-value">--</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="comparison-recommendation" class="comparison-recommendation"></div>
            </div>

            <!-- Debt List -->
            <div class="debt-list-section">
                <h3><?php p($l->t('Your Debts')); ?></h3>
                <p class="section-hint"><?php p($l->t('Debts are pulled from your liability accounts. Edit minimum payments in account settings.')); ?></p>
                <div id="debt-list" class="debt-list">
                    <div class="empty-state"><?php p($l->t('No debt accounts found')); ?></div>
                </div>
            </div>
        </div>

        <!-- Shared Expenses View -->
        <div id="shared-expenses-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Shared Expenses')); ?></h2>
                <div class="view-controls">
                    <button id="add-contact-btn" class="primary">
                        <span class="icon-add" aria-hidden="true"></span>
                        <?php p($l->t('Add Contact')); ?>
                    </button>
                </div>
            </div>

            <!-- Compact Balance Bar -->
            <div class="split-balance-bar">
                <div class="split-balance-item">
                    <span class="split-balance-label"><?php p($l->t('Owed to you')); ?></span>
                    <span id="split-total-owed" class="split-balance-value positive">£0.00</span>
                </div>
                <div class="split-balance-divider"></div>
                <div class="split-balance-item">
                    <span class="split-balance-label"><?php p($l->t('You owe')); ?></span>
                    <span id="split-total-owing" class="split-balance-value negative">£0.00</span>
                </div>
                <div class="split-balance-divider"></div>
                <div class="split-balance-item">
                    <span class="split-balance-label"><?php p($l->t('Net')); ?></span>
                    <span id="split-net-balance" class="split-balance-value">£0.00</span>
                </div>
            </div>

            <!-- Contact Grid -->
            <div id="contacts-list" class="contacts-grid">
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor" opacity="0.3">
                            <path d="M16,13C15.71,13 15.38,13 15.03,13.05C16.19,13.89 17,15 17,16.5V19H23V16.5C23,14.17 18.33,13 16,13M8,13C5.67,13 1,14.17 1,16.5V19H15V16.5C15,14.17 10.33,13 8,13M8,11A3,3 0 0,0 11,8A3,3 0 0,0 8,5A3,3 0 0,0 5,8A3,3 0 0,0 8,11M16,11A3,3 0 0,0 19,8A3,3 0 0,0 16,5A3,3 0 0,0 13,8A3,3 0 0,0 16,11Z"/>
                        </svg>
                    </div>
                    <p><?php p($l->t('Add contacts to start splitting expenses')); ?></p>
                </div>
            </div>
        </div>

        <!-- Exchange Rates View -->
        <div id="exchange-rates-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Exchange Rates')); ?></h2>
                <div class="view-controls">
                    <button id="refresh-rates-btn" class="secondary" title="<?php p($l->t('Refresh rates from online sources')); ?>">
                        <span class="icon-play" aria-hidden="true"></span>
                        <?php p($l->t('Refresh Rates')); ?>
                    </button>
                </div>
            </div>

            <!-- Info Notice -->
            <div class="exchange-rates-notice" style="background-color: rgba(100, 116, 139, 0.08); border: 1px solid rgba(100, 116, 139, 0.15); border-radius: 4px; padding: 12px 16px; margin: 16px 0; display: flex; align-items: flex-start; gap: 12px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="rgba(100, 116, 139, 0.6)" style="flex-shrink: 0; margin-top: 2px;">
                    <path d="M11,9H13V7H11M12,20C7.59,20 4,16.41 4,12C4,7.59 7.59,4 12,4C16.41,4 20,7.59 20,12C20,16.41 16.41,20 12,20M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M11,17H13V11H11V17Z"/>
                </svg>
                <div style="color: rgba(100, 116, 139, 0.85); font-size: 14px;">
                    <?php p($l->t('Rates shown as 1 base currency = X target currency.')); ?>
                    <?php p($l->t('Fiat rates from')); ?> <a href="https://www.floatrates.com" target="_blank" rel="noopener" style="color: var(--color-primary);">FloatRates</a>,
                    <?php p($l->t('crypto from')); ?> <a href="https://www.coingecko.com" target="_blank" rel="noopener" style="color: var(--color-primary);">CoinGecko</a>.
                    <?php p($l->t('Manual overrides take priority over automatic rates.')); ?>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="exchange-rates-summary">
                <div class="summary-card">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12.89,3L14.85,3.4L11.11,21L9.15,20.6L12.89,3M19.59,12L16,8.41V5.58L22.42,12L16,18.41V15.58L19.59,12M1.58,12L8,5.58V8.41L4.41,12L8,15.58V18.41L1.58,12Z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="rates-total-count">0</div>
                        <div class="summary-label"><?php p($l->t('Total Currencies')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12,2A10,10 0 0,1 22,12A10,10 0 0,1 12,22A10,10 0 0,1 2,12A10,10 0 0,1 12,2M12,4A8,8 0 0,0 4,12A8,8 0 0,0 12,20A8,8 0 0,0 20,12A8,8 0 0,0 12,4M11,16.5L6.5,12L7.91,10.59L11,13.67L16.59,8.09L18,9.5L11,16.5Z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="rates-auto-count">0</div>
                        <div class="summary-label"><?php p($l->t('Auto Rates')); ?></div>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20.71,7.04C21.1,6.65 21.1,6 20.71,5.63L18.37,3.29C18,2.9 17.35,2.9 16.96,3.29L15.12,5.12L18.87,8.87M3,17.25V21H6.75L17.81,9.93L14.06,6.18L3,17.25Z"/>
                        </svg>
                    </div>
                    <div class="summary-content">
                        <div class="summary-value" id="rates-manual-count">0</div>
                        <div class="summary-label"><?php p($l->t('Manual Overrides')); ?></div>
                    </div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="exchange-rates-tabs">
                <button class="tab-button active" data-filter="all"><?php p($l->t('All')); ?></button>
                <button class="tab-button" data-filter="fiat"><?php p($l->t('Fiat')); ?></button>
                <button class="tab-button" data-filter="crypto"><?php p($l->t('Crypto')); ?></button>
                <button class="tab-button" data-filter="manual"><?php p($l->t('Manual Only')); ?></button>
                <button class="tab-button" data-filter="no-rate"><?php p($l->t('No Rate')); ?></button>
            </div>

            <!-- Rates List -->
            <div class="exchange-rates-container">
                <div id="exchange-rates-list" class="exchange-rates-list">
                    <!-- Rate cards rendered by ExchangeRatesModule -->
                </div>
            </div>

            <!-- Manual Rate Modal -->
            <div id="manual-rate-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="manual-rate-modal-title" aria-hidden="true">
                <div class="modal-content manual-rate-modal-content">
                    <div class="manual-rate-modal-header">
                        <div class="manual-rate-title-row">
                            <div class="manual-rate-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M7.5,21.5C4.25,19.94 2,16.76 2,13.5C2,9.08 5.58,5.5 10,5.5C13.27,5.5 16.44,7.75 18,11H22L18.5,14.5L15,11H17.03C15.6,8.87 13,7.5 10,7.5C6.69,7.5 4,10.19 4,13.5C4,16 5.56,18.13 7.76,19.15L7.5,21.5M16.5,2.5C19.75,4.06 22,7.24 22,10.5C22,14.92 18.42,18.5 14,18.5C10.73,18.5 7.56,16.25 6,13H2L5.5,9.5L9,13H6.97C8.4,15.13 11,16.5 14,16.5C17.31,16.5 20,13.81 20,10.5C20,8 18.44,5.87 16.24,4.85L16.5,2.5Z"/>
                                </svg>
                            </div>
                            <h3 id="manual-rate-modal-title"><?php p($l->t('Set Manual Rate')); ?></h3>
                        </div>
                        <button class="modal-close cancel-btn" id="manual-rate-close-btn" aria-label="<?php p($l->t('Close')); ?>">&times;</button>
                    </div>
                    <div class="manual-rate-body">
                        <p id="manual-rate-currency" class="manual-rate-currency-name"></p>
                        <div class="manual-rate-equation">
                            <span id="manual-rate-base-label" class="manual-rate-eq-label"></span>
                            <input type="number" id="manual-rate-value" step="any" min="0" class="manual-rate-eq-input" placeholder="0.00" />
                            <span id="manual-rate-target-label" class="manual-rate-eq-label"></span>
                        </div>
                        <input type="hidden" id="manual-rate-currency-input" />
                        <p class="manual-rate-hint"><?php p($l->t('This overrides the automatic rate for your account only.')); ?></p>
                    </div>
                    <div class="manual-rate-footer">
                        <button id="manual-rate-cancel-btn" class="cancel-btn"><?php p($l->t('Cancel')); ?></button>
                        <button id="manual-rate-save-btn" class="primary"><?php p($l->t('Save Rate')); ?></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings View -->
        <div id="sharing-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Budget Sharing')); ?></h2>
            </div>
            <div id="sharing-content"></div>
        </div>

        <!-- Bank Sync View -->
        <div id="bank-sync-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Bank Sync')); ?></h2>
            </div>

            <div id="bank-sync-disabled-notice" class="settings-section" style="display: none;">
                <div class="empty-state">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor" style="opacity: 0.3;">
                        <path d="M11.5,1L2,6V8H21V6M16,10V17H19V10M2,22H21V19H2M10,10V17H13V10M4,10V17H7V10H4Z"/>
                    </svg>
                    <h3><?php p($l->t('Bank Sync is disabled')); ?></h3>
                    <p><?php p($l->t('An administrator must enable bank sync before it can be used.')); ?></p>
                </div>
            </div>

            <div id="bank-sync-content" style="display: none;">
                <!-- Connections List -->
                <div class="settings-section">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3><?php p($l->t('Bank Connections')); ?></h3>
                        <button class="btn btn-primary" id="add-bank-connection-btn">
                            <span class="icon-add" aria-hidden="true"></span>
                            <?php p($l->t('Add Connection')); ?>
                        </button>
                    </div>
                    <div id="bank-connections-list">
                        <div class="empty-state-small"><?php p($l->t('No bank connections yet. Click "Add Connection" to get started.')); ?></div>
                    </div>
                </div>

                <!-- Account Mappings (shown when a connection is selected) -->
                <div class="settings-section" id="bank-mappings-section" style="display: none;">
                    <h3 id="bank-mappings-title"><?php p($l->t('Account Mappings')); ?></h3>
                    <p class="section-description"><?php p($l->t('Map your bank accounts to Budget accounts to enable automatic transaction import.')); ?></p>
                    <div id="bank-mappings-list"></div>
                </div>
            </div>
        </div>

        <div id="settings-view" class="view">
            <div class="view-header">
                <h2><?php p($l->t('Settings')); ?></h2>
                <div class="view-controls">
                    <button id="reset-settings-btn" class="secondary" title="<?php p($l->t('Reset all settings to defaults')); ?>">
                        <span class="icon-history" aria-hidden="true"></span>
                        <?php p($l->t('Reset All')); ?>
                    </button>
                    <button id="save-settings-btn" class="primary" title="<?php p($l->t('Save settings')); ?>">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                        <?php p($l->t('Save Changes')); ?>
                    </button>
                </div>
            </div>

            <div class="settings-container">
                <!-- General Settings Section -->
                <div class="settings-section">
                    <h3><?php p($l->t('General Settings')); ?></h3>

                    <div class="settings-group">
                        <div class="setting-item">
                            <label for="setting-default-currency">
                                <strong><?php p($l->t('Default Currency')); ?></strong>
                                <small><?php p($l->t('Default currency for new accounts and transactions')); ?></small>
                            </label>
                            <select id="setting-default-currency" class="setting-input">
                                <!-- Populated dynamically from /api/settings/options -->
                            </select>
                        </div>

                        <div class="setting-item">
                            <label for="setting-budget-period">
                                <strong><?php p($l->t('Budget Period')); ?></strong>
                                <small><?php p($l->t('Default period for budget tracking')); ?></small>
                            </label>
                            <select id="setting-budget-period" class="setting-input">
                                <option value="weekly"><?php p($l->t('Weekly')); ?></option>
                                <option value="monthly"><?php p($l->t('Monthly')); ?></option>
                                <option value="quarterly"><?php p($l->t('Quarterly')); ?></option>
                                <option value="yearly"><?php p($l->t('Yearly')); ?></option>
                            </select>
                        </div>

                        <div class="setting-item">
                            <label for="setting-budget-start-day">
                                <strong><?php p($l->t('Budget Cycle Start Day')); ?></strong>
                                <small><?php p($l->t('Day of the month when your budget cycle resets (1 = first of month, 31 = last day). Useful for aligning budgets with payday.')); ?></small>
                            </label>
                            <input type="number" id="setting-budget-start-day" class="setting-input"
                                   min="1" max="31" step="1" value="1">
                        </div>
                    </div>
                </div>

                <!-- Display Settings Section -->
                <div class="settings-section">
                    <h3><?php p($l->t('Display Settings')); ?></h3>

                    <div class="settings-group">
                        <div class="setting-item">
                            <label for="setting-date-format">
                                <strong><?php p($l->t('Date Format')); ?></strong>
                                <small><?php p($l->t('How dates are displayed throughout the app')); ?></small>
                            </label>
                            <select id="setting-date-format" class="setting-input">
                                <option value="Y-m-d"><?php p($l->t('YYYY-MM-DD (2025-10-12)')); ?></option>
                                <option value="m/d/Y"><?php p($l->t('MM/DD/YYYY (10/12/2025)')); ?></option>
                                <option value="d/m/Y"><?php p($l->t('DD/MM/YYYY (12/10/2025)')); ?></option>
                                <option value="d.m.Y"><?php p($l->t('DD.MM.YYYY (12.10.2025)')); ?></option>
                                <option value="M j, Y"><?php p($l->t('Mon D, YYYY (Oct 12, 2025)')); ?></option>
                            </select>
                        </div>

                        <div class="setting-item">
                            <label for="setting-first-day-of-week">
                                <strong><?php p($l->t('First Day of Week')); ?></strong>
                                <small><?php p($l->t('Starting day for calendars and weekly reports')); ?></small>
                            </label>
                            <select id="setting-first-day-of-week" class="setting-input">
                                <option value="0"><?php p($l->t('Sunday')); ?></option>
                                <option value="1"><?php p($l->t('Monday')); ?></option>
                            </select>
                        </div>

                    </div>
                </div>

                <!-- Number Format Settings Section -->
                <div class="settings-section">
                    <h3><?php p($l->t('Number Format')); ?></h3>

                    <div class="settings-group">
                        <div class="setting-item">
                            <label for="setting-number-format-decimals">
                                <strong><?php p($l->t('Decimal Places')); ?></strong>
                                <small><?php p($l->t('Number of decimal places to display')); ?></small>
                            </label>
                            <select id="setting-number-format-decimals" class="setting-input">
                                <option value="0">0 (1234)</option>
                                <option value="2">2 (1234.56)</option>
                                <option value="3">3 (1234.567)</option>
                            </select>
                        </div>

                        <div class="setting-item">
                            <label for="setting-number-format-decimal-sep">
                                <strong><?php p($l->t('Decimal Separator')); ?></strong>
                                <small><?php p($l->t('Character used for decimal separation')); ?></small>
                            </label>
                            <select id="setting-number-format-decimal-sep" class="setting-input">
                                <option value="."><?php p($l->t('Period (.)')); ?></option>
                                <option value=","><?php p($l->t('Comma (,)')); ?></option>
                            </select>
                        </div>

                        <div class="setting-item">
                            <label for="setting-number-format-thousands-sep">
                                <strong><?php p($l->t('Thousands Separator')); ?></strong>
                                <small><?php p($l->t('Character used for thousands separation')); ?></small>
                            </label>
                            <select id="setting-number-format-thousands-sep" class="setting-input">
                                <option value=","><?php p($l->t('Comma (,)')); ?></option>
                                <option value="."><?php p($l->t('Period (.)')); ?></option>
                                <option value=" "><?php p($l->t('Space ( )')); ?></option>
                                <option value=""><?php p($l->t('None')); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="setting-preview">
                        <strong><?php p($l->t('Preview:')); ?></strong>
                        <span id="number-format-preview">$1,234.56</span>
                    </div>
                </div>

                <!-- Notification Settings Section -->
                <div class="settings-section">
                    <h3><?php p($l->t('Notifications')); ?></h3>

                    <div class="settings-group">
                        <div class="setting-item checkbox-setting">
                            <label>
                                <input type="checkbox" id="setting-notification-budget-alert" class="setting-input">
                                <div>
                                    <strong><?php p($l->t('Budget Alerts')); ?></strong>
                                    <small><?php p($l->t('Notify when approaching or exceeding budget limits')); ?></small>
                                </div>
                            </label>
                        </div>

                        <div class="setting-item checkbox-setting">
                            <label>
                                <input type="checkbox" id="setting-notification-forecast-warning" class="setting-input">
                                <div>
                                    <strong><?php p($l->t('Forecast Warnings')); ?></strong>
                                    <small><?php p($l->t('Notify about negative cash flow predictions')); ?></small>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Import/Export Settings Section -->
                <div class="settings-section">
                    <h3><?php p($l->t('Import & Export')); ?></h3>

                    <div class="settings-group">
                        <div class="setting-item checkbox-setting">
                            <label>
                                <input type="checkbox" id="setting-import-auto-apply-rules" class="setting-input">
                                <div>
                                    <strong><?php p($l->t('Auto-apply Import Rules')); ?></strong>
                                    <small><?php p($l->t('Automatically categorize transactions when importing')); ?></small>
                                </div>
                            </label>
                        </div>

                        <div class="setting-item checkbox-setting">
                            <label>
                                <input type="checkbox" id="setting-import-skip-duplicates" class="setting-input">
                                <div>
                                    <strong><?php p($l->t('Skip Duplicate Transactions')); ?></strong>
                                    <small><?php p($l->t('Automatically skip duplicate transactions during import')); ?></small>
                                </div>
                            </label>
                        </div>

                        <div class="setting-item">
                            <label for="setting-export-default-format">
                                <strong><?php p($l->t('Default Export Format')); ?></strong>
                                <small><?php p($l->t('Preferred format for data exports')); ?></small>
                            </label>
                            <select id="setting-export-default-format" class="setting-input">
                                <option value="csv"><?php p($l->t('CSV')); ?></option>
                                <option value="json"><?php p($l->t('JSON')); ?></option>
                                <option value="pdf"><?php p($l->t('PDF')); ?></option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Security Settings Section -->
                <div class="settings-section">
                    <h3><?php p($l->t('Security')); ?></h3>

                    <div class="settings-group">
                        <div class="setting-item checkbox-setting">
                            <label>
                                <input type="checkbox" id="setting-password-protection-enabled" class="setting-input">
                                <div>
                                    <strong><?php p($l->t('Password Protection')); ?></strong>
                                    <small><?php p($l->t('Require password to access the budget app')); ?></small>
                                </div>
                            </label>
                        </div>

                        <div id="password-protection-config" style="display: none; margin-top: 16px; padding: 16px; background: var(--color-background-dark); border-radius: 3px;">
                            <div class="setting-item" style="margin-bottom: 12px;">
                                <label for="setting-session-timeout-minutes">
                                    <strong><?php p($l->t('Session Timeout')); ?></strong>
                                    <small><?php p($l->t('Automatically lock after this period of inactivity')); ?></small>
                                </label>
                                <select id="setting-session-timeout-minutes" class="setting-input">
                                    <option value="15"><?php p($l->t('15 minutes')); ?></option>
                                    <option value="30"><?php p($l->t('30 minutes')); ?></option>
                                    <option value="60"><?php p($l->t('1 hour')); ?></option>
                                </select>
                            </div>

                            <div class="password-management-buttons" style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <button id="setup-password-btn" class="secondary" style="display: none;">
                                    <span class="icon-password" aria-hidden="true"></span>
                                    <?php p($l->t('Set Password')); ?>
                                </button>
                                <button id="change-password-btn" class="secondary" style="display: none;">
                                    <span class="icon-password" aria-hidden="true"></span>
                                    <?php p($l->t('Change Password')); ?>
                                </button>
                                <button id="disable-password-btn" class="secondary" style="display: none;">
                                    <span class="icon-delete" aria-hidden="true"></span>
                                    <?php p($l->t('Remove Password')); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Data Migration Section -->
                <div class="settings-section">
                    <h3><?php p($l->t('Data Migration')); ?></h3>
                    <p class="settings-description"><?php p($l->t('Export all your data for backup or migration to another Nextcloud instance. Import to restore or migrate data.')); ?></p>

                    <div class="settings-group">
                        <!-- Export -->
                        <div class="migration-subsection">
                            <h4><?php p($l->t('Export Data')); ?></h4>
                            <p class="migration-info"><?php p($l->t('Download all your accounts, transactions, categories, bills, import rules, and settings as a ZIP file.')); ?></p>
                            <div class="migration-warning">
                                <span class="icon-password" aria-hidden="true"></span>
                                <strong><?php p($l->t('Security Notice:')); ?></strong> <?php p($l->t('The export file contains sensitive data including decrypted banking details. Store it securely and delete after use.')); ?>
                            </div>
                            <button id="migration-export-btn" class="primary">
                                <span class="icon-download" aria-hidden="true"></span>
                                <?php p($l->t('Export All Data')); ?>
                            </button>
                        </div>

                        <!-- Import -->
                        <div class="migration-subsection">
                            <h4><?php p($l->t('Import Data')); ?></h4>
                            <p class="migration-info"><?php p($l->t('Import data from a previously exported ZIP file. This will replace all existing data.')); ?></p>
                            <div class="migration-warning warning-danger">
                                <span class="icon-error" aria-hidden="true"></span>
                                <strong><?php p($l->t('Warning:')); ?></strong> <?php p($l->t('Importing will permanently delete all your current data and replace it with the imported data. This cannot be undone.')); ?>
                            </div>

                            <div id="migration-import-dropzone" class="migration-dropzone">
                                <div class="dropzone-content">
                                    <span class="icon-upload" aria-hidden="true"></span>
                                    <p><?php p($l->t('Drag and drop your export file here')); ?></p>
                                    <p class="dropzone-hint"><?php p($l->t('or')); ?></p>
                                    <button type="button" id="migration-browse-btn" class="secondary"><?php p($l->t('Browse Files')); ?></button>
                                    <input type="file" id="migration-file-input" accept=".zip" style="display: none;">
                                    <p class="dropzone-formats"><?php p($l->t('Supported format: ZIP (exported from Budget app)')); ?></p>
                                </div>
                            </div>

                            <!-- Preview Section (hidden by default) -->
                            <div id="migration-preview" class="migration-preview" style="display: none;">
                                <h5><?php p($l->t('Import Preview')); ?></h5>
                                <div id="migration-preview-content">
                                    <div class="preview-info">
                                        <div class="preview-row">
                                            <span class="preview-label"><?php p($l->t('Export Version:')); ?></span>
                                            <span id="preview-version">-</span>
                                        </div>
                                        <div class="preview-row">
                                            <span class="preview-label"><?php p($l->t('Exported At:')); ?></span>
                                            <span id="preview-date">-</span>
                                        </div>
                                    </div>
                                    <div class="preview-counts">
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-categories">0</span>
                                            <span class="count-label"><?php p($l->t('Categories')); ?></span>
                                        </div>
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-accounts">0</span>
                                            <span class="count-label"><?php p($l->t('Accounts')); ?></span>
                                        </div>
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-transactions">0</span>
                                            <span class="count-label"><?php p($l->t('Transactions')); ?></span>
                                        </div>
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-bills">0</span>
                                            <span class="count-label"><?php p($l->t('Bills')); ?></span>
                                        </div>
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-rules">0</span>
                                            <span class="count-label"><?php p($l->t('Import Rules')); ?></span>
                                        </div>
                                        <div class="preview-count-item">
                                            <span class="count-value" id="preview-settings">0</span>
                                            <span class="count-label"><?php p($l->t('Settings')); ?></span>
                                        </div>
                                    </div>
                                    <div id="migration-warnings" class="migration-warnings" style="display: none;"></div>
                                </div>
                                <div class="preview-actions">
                                    <button type="button" id="migration-cancel-btn" class="secondary"><?php p($l->t('Cancel')); ?></button>
                                    <button type="button" id="migration-confirm-btn" class="primary danger">
                                        <span class="icon-confirm" aria-hidden="true"></span>
                                        <?php p($l->t('Confirm Import')); ?>
                                    </button>
                                </div>
                            </div>

                            <!-- Progress Section (hidden by default) -->
                            <div id="migration-progress" class="migration-progress" style="display: none;">
                                <div class="progress-spinner"></div>
                                <p id="migration-progress-text"><?php p($l->t('Processing import...')); ?></p>
                            </div>

                            <!-- Result Section (hidden by default) -->
                            <div id="migration-result" class="migration-result" style="display: none;">
                                <div id="migration-result-content"></div>
                                <button type="button" id="migration-done-btn" class="primary"><?php p($l->t('Done')); ?></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Maintenance Section -->
                <div class="settings-section">
                    <h3><?php p($l->t('Maintenance')); ?></h3>
                    <div class="settings-group">
                        <div class="danger-zone-item">
                            <div class="danger-zone-info">
                                <h4><?php p($l->t('Recalculate Account Balances')); ?></h4>
                                <p><?php p($l->t('Recalculates all account balances from their opening balance and transaction history. Use this if account balances appear incorrect.')); ?></p>
                            </div>
                            <button id="recalculate-balances-btn" class="secondary" type="button">
                                <span class="icon-history" aria-hidden="true"></span>
                                <?php p($l->t('Recalculate')); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Admin Settings Section (visible to admins only) -->
                <div class="settings-section" id="admin-settings-section" style="display: none;">
                    <h3><?php p($l->t('Admin Settings')); ?></h3>
                    <div class="settings-group">
                        <div class="bank-sync-warning">
                            <span class="icon-error" aria-hidden="true"></span>
                            <div>
                                <strong><?php p($l->t('Experimental Feature')); ?></strong>
                                <p><?php p($l->t('Bank Sync is a new feature that is still being tested and may be unreliable. If you encounter any issues, please submit a report on GitHub so we can resolve them.')); ?></p>
                                <a href="https://github.com/otherworld-dev/budget/issues" target="_blank" rel="noopener"><?php p($l->t('Report an issue on GitHub')); ?> &rarr;</a>
                            </div>
                        </div>
                        <div class="setting-item checkbox-setting">
                            <label>
                                <input type="checkbox" id="setting-bank-sync-enabled">
                                <div>
                                    <strong><?php p($l->t('Enable Bank Sync')); ?></strong>
                                    <small><?php p($l->t('Allow users to connect external bank accounts for automatic transaction imports. Supports GoCardless (UK/Europe) and SimpleFIN (US). Disabled by default for security.')); ?></small>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Danger Zone Section -->
                <div class="settings-section danger-zone">
                    <h3><?php p($l->t('Danger Zone')); ?></h3>
                    <p class="settings-description danger-zone-description">
                        <span class="icon-error" aria-hidden="true"></span>
                        <strong><?php p($l->t('Warning:')); ?></strong> <?php p($l->t('Actions in this section are destructive and cannot be undone.')); ?>
                    </p>

                    <div class="settings-group">
                        <div class="danger-zone-item">
                            <div class="danger-zone-info">
                                <h4><?php p($l->t('Factory Reset')); ?></h4>
                                <p><?php p($l->t('Permanently delete ALL your data including accounts, transactions, bills, categories, settings, and more. Only audit logs will be preserved for compliance. This action cannot be undone.')); ?></p>
                            </div>
                            <button id="factory-reset-btn" class="danger-btn" type="button">
                                <span class="icon-delete" aria-hidden="true"></span>
                                <?php p($l->t('Factory Reset')); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Settings Actions -->
                <div class="settings-actions">
                    <button id="save-settings-btn-bottom" class="primary">
                        <span class="icon-checkmark" aria-hidden="true"></span>
                        <?php p($l->t('Save Changes')); ?>
                    </button>
                    <button id="reset-settings-btn-bottom" class="secondary">
                        <span class="icon-history" aria-hidden="true"></span>
                        <?php p($l->t('Reset All to Defaults')); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->
<div id="transaction-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="transaction-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="transaction-modal-title"><?php p($l->t('Add/Edit Transaction')); ?></h3>
        <form id="transaction-form">
            <input type="hidden" id="transaction-id">
            <div class="form-group">
                <label for="transaction-date"><?php p($l->t('Date')); ?></label>
                <input type="date" id="transaction-date" required aria-describedby="transaction-date-help">
                <small id="transaction-date-help" class="form-text"><?php p($l->t('Select the transaction date')); ?></small>
            </div>
            <div class="form-group">
                <label for="transaction-account"><?php p($l->t('Account')); ?></label>
                <select id="transaction-account" required aria-describedby="transaction-account-help">
                    <option value=""><?php p($l->t('Choose an account')); ?></option>
                </select>
                <small id="transaction-account-help" class="form-text"><?php p($l->t('Select which account this transaction belongs to')); ?></small>
            </div>
            <div class="form-group">
                <label for="transaction-type"><?php p($l->t('Type')); ?></label>
                <select id="transaction-type" required aria-describedby="transaction-type-help">
                    <option value=""><?php p($l->t('Choose transaction type')); ?></option>
                    <option value="debit"><?php p($l->t('Expense')); ?></option>
                    <option value="credit"><?php p($l->t('Income')); ?></option>
                    <option value="transfer"><?php p($l->t('Transfer')); ?></option>
                </select>
                <small id="transaction-type-help" class="form-text"><?php p($l->t('Whether this is money coming in or going out')); ?></small>
            </div>
            <div id="transfer-to-account-wrapper" class="form-group" style="display: none;">
                <label for="transfer-to-account"><?php p($l->t('To Account')); ?></label>
                <select id="transfer-to-account" aria-describedby="transfer-to-account-help">
                    <option value=""><?php p($l->t('Choose destination account')); ?></option>
                </select>
                <small id="transfer-to-account-help" class="form-text"><?php p($l->t('Select the account to transfer money to')); ?></small>
            </div>
            <div class="form-group">
                <label for="transaction-amount"><?php p($l->t('Amount')); ?></label>
                <input type="number" id="transaction-amount" step="0.01" required aria-describedby="transaction-amount-help" min="0">
                <small id="transaction-amount-help" class="form-text"><?php p($l->t('Enter the transaction amount (positive number)')); ?></small>
            </div>
            <div class="form-group">
                <label for="transaction-description"><?php p($l->t('Description')); ?></label>
                <input type="text" id="transaction-description" required aria-describedby="transaction-description-help" maxlength="255">
                <small id="transaction-description-help" class="form-text"><?php p($l->t('Brief description of the transaction')); ?></small>
            </div>
            <div class="form-group">
                <label for="transaction-vendor"><?php p($l->t('Vendor')); ?></label>
                <input type="text" id="transaction-vendor" aria-describedby="transaction-vendor-help" maxlength="255">
                <small id="transaction-vendor-help" class="form-text"><?php p($l->t('Name of the merchant or person (optional)')); ?></small>
            </div>
            <div class="form-group">
                <label for="transaction-category"><?php p($l->t('Category')); ?></label>
                <select id="transaction-category" aria-describedby="transaction-category-help">
                    <option value=""><?php p($l->t('No category')); ?></option>
                </select>
                <small id="transaction-category-help" class="form-text"><?php p($l->t('Organize this transaction by category (optional)')); ?></small>
            </div>

            <!-- Transaction Tags Container -->
            <div id="transaction-tags-container"></div>

            <div class="form-group">
                <label for="transaction-notes"><?php p($l->t('Notes')); ?></label>
                <textarea id="transaction-notes" aria-describedby="transaction-notes-help" maxlength="500" rows="3"></textarea>
                <small id="transaction-notes-help" class="form-text"><?php p($l->t('Additional notes or details (optional)')); ?></small>
            </div>
            <div class="modal-buttons">
                <button type="submit" class="primary" aria-label="<?php p($l->t('Save transaction')); ?>"><?php p($l->t('Save')); ?></button>
                <button type="button" class="secondary cancel-btn" aria-label="<?php p($l->t('Cancel and close dialog')); ?>"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Account Modal -->
<div id="account-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="account-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="account-modal-title"><?php p($l->t('Add/Edit Account')); ?></h3>
        <form id="account-form">
            <input type="hidden" id="account-id">

            <!-- Basic Account Information -->
            <div class="form-section">
                <h4><?php p($l->t('Basic Information')); ?></h4>

                <div class="form-group">
                    <label for="account-name"><?php p($l->t('Account Name')); ?> <span class="required">*</span></label>
                    <input type="text" id="account-name" required aria-describedby="account-name-help" maxlength="255">
                    <small id="account-name-help" class="form-text"><?php p($l->t('Enter a descriptive name for this account')); ?></small>
                </div>

                <div class="form-group">
                    <label for="account-type"><?php p($l->t('Account Type')); ?> <span class="required">*</span></label>
                    <select id="account-type" required aria-describedby="account-type-help">
                        <option value=""><?php p($l->t('Choose account type')); ?></option>
                        <option value="checking"><?php p($l->t('Checking Account')); ?></option>
                        <option value="savings"><?php p($l->t('Savings Account')); ?></option>
                        <option value="credit_card"><?php p($l->t('Credit Card')); ?></option>
                        <option value="investment"><?php p($l->t('Investment Account')); ?></option>
                        <option value="loan"><?php p($l->t('Loan Account')); ?></option>
                        <option value="cash"><?php p($l->t('Cash')); ?></option>
                        <option value="cryptocurrency"><?php p($l->t('Cryptocurrency')); ?></option>
                    </select>
                    <small id="account-type-help" class="form-text"><?php p($l->t('Select the type of account')); ?></small>
                </div>

                <div class="form-group" id="opening-balance-group" style="display: none;">
                    <label for="account-opening-balance"><?php p($l->t('Opening Balance')); ?></label>
                    <input type="number" id="account-opening-balance" step="0.01" aria-describedby="account-opening-balance-help">
                    <small id="account-opening-balance-help" class="form-text"><?php p($l->t('The starting balance when this account was created')); ?></small>
                </div>

                <div class="form-group">
                    <label for="account-balance" id="account-balance-label"><?php p($l->t('Starting Balance')); ?></label>
                    <input type="number" id="account-balance" step="0.01" aria-describedby="account-balance-help">
                    <small id="account-balance-help" class="form-text"><?php p($l->t('The balance this account starts with')); ?></small>
                </div>

                <div class="form-group">
                    <label for="account-currency"><?php p($l->t('Currency')); ?></label>
                    <select id="account-currency" aria-describedby="account-currency-help">
                        <!-- Populated dynamically from /api/settings/options -->
                    </select>
                    <small id="account-currency-help" class="form-text"><?php p($l->t('Select the account currency')); ?></small>
                </div>
            </div>

            <!-- Bank Information -->
            <div class="form-section">
                <h4><?php p($l->t('Bank Information')); ?></h4>

                <div class="form-group">
                    <label for="form-institution"><?php p($l->t('Institution')); ?></label>
                    <input type="text" id="form-institution" aria-describedby="form-institution-help" maxlength="255" autocomplete="off">
                    <div id="institution-suggestions" class="autocomplete-dropdown" style="display: none;"></div>
                    <small id="form-institution-help" class="form-text"><?php p($l->t('Bank or financial institution name')); ?></small>
                </div>

                <div class="form-group">
                    <label for="account-holder-name"><?php p($l->t('Account Holder Name')); ?></label>
                    <input type="text" id="account-holder-name" aria-describedby="account-holder-name-help" maxlength="255">
                    <small id="account-holder-name-help" class="form-text"><?php p($l->t('Name on the account')); ?></small>
                </div>

                <div class="form-group">
                    <label for="form-account-number"><?php p($l->t('Account Number')); ?></label>
                    <input type="text" id="form-account-number" aria-describedby="form-account-number-help" maxlength="100">
                    <small id="form-account-number-help" class="form-text"><?php p($l->t('Your account number')); ?></small>
                </div>

                <div class="form-group">
                    <label for="account-opening-date"><?php p($l->t('Opening Date')); ?></label>
                    <input type="date" id="account-opening-date" aria-describedby="account-opening-date-help">
                    <small id="account-opening-date-help" class="form-text"><?php p($l->t('When the account was opened')); ?></small>
                </div>
            </div>

            <!-- Banking Details (conditional) -->
            <div class="form-section" id="banking-details-section">
                <h4><?php p($l->t('Banking Details')); ?></h4>

                <div class="form-group conditional" id="routing-number-group">
                    <label for="form-routing-number"><?php p($l->t('Routing Number')); ?></label>
                    <input type="text" id="form-routing-number" aria-describedby="form-routing-number-help" maxlength="20">
                    <small id="form-routing-number-help" class="form-text"><?php p($l->t('9-digit routing number (US banks)')); ?></small>
                </div>

                <div class="form-group conditional" id="sort-code-group">
                    <label for="form-sort-code"><?php p($l->t('Sort Code')); ?></label>
                    <input type="text" id="form-sort-code" aria-describedby="form-sort-code-help" maxlength="10">
                    <small id="form-sort-code-help" class="form-text"><?php p($l->t('6-digit sort code (UK banks)')); ?></small>
                </div>

                <div class="form-group conditional" id="iban-group">
                    <label for="form-iban"><?php p($l->t('IBAN')); ?></label>
                    <input type="text" id="form-iban" aria-describedby="form-iban-help" maxlength="34">
                    <small id="form-iban-help" class="form-text"><?php p($l->t('International Bank Account Number')); ?></small>
                </div>

                <div class="form-group conditional" id="swift-bic-group">
                    <label for="form-swift-bic"><?php p($l->t('SWIFT/BIC Code')); ?></label>
                    <input type="text" id="form-swift-bic" aria-describedby="form-swift-bic-help" maxlength="11">
                    <small id="form-swift-bic-help" class="form-text"><?php p($l->t('SWIFT/BIC code for international transfers')); ?></small>
                </div>

                <div class="form-group conditional" id="wallet-address-group">
                    <label for="form-wallet-address"><?php p($l->t('Wallet Address')); ?></label>
                    <input type="text" id="form-wallet-address" aria-describedby="form-wallet-address-help" maxlength="255">
                    <small id="form-wallet-address-help" class="form-text"><?php p($l->t('Your wallet or exchange address (stored encrypted)')); ?></small>
                </div>
            </div>

            <!-- Account Limits (conditional) -->
            <div class="form-section" id="limits-section">
                <h4><?php p($l->t('Account Limits & Rates')); ?></h4>

                <div class="form-group conditional" id="interest-rate-group">
                    <label for="account-interest-rate"><?php p($l->t('Interest Rate (%%)')); ?></label>
                    <input type="number" id="account-interest-rate" step="0.0001" min="0" max="100" aria-describedby="account-interest-rate-help">
                    <small id="account-interest-rate-help" class="form-text"><?php p($l->t('Annual interest rate percentage')); ?></small>
                </div>

                <div class="form-group conditional" id="interest-enabled-group">
                    <label for="account-interest-enabled"><?php p($l->t('Interest Tracking')); ?></label>
                    <label style="display: flex; align-items: center; gap: 8px; font-weight: normal; cursor: pointer;">
                        <input type="checkbox" id="account-interest-enabled" style="width: 18px; height: 18px; margin: 0;">
                        <?php p($l->t('Enable')); ?>
                    </label>
                    <small class="form-text"><?php p($l->t('Track accrued interest over time with variable rate support')); ?></small>
                </div>

                <div class="form-group conditional" id="compounding-frequency-group">
                    <label for="account-compounding-frequency"><?php p($l->t('Compounding Frequency')); ?></label>
                    <select id="account-compounding-frequency">
                        <option value="daily"><?php p($l->t('Daily')); ?></option>
                        <option value="monthly"><?php p($l->t('Monthly')); ?></option>
                        <option value="yearly"><?php p($l->t('Yearly')); ?></option>
                        <option value="simple"><?php p($l->t('Simple (no compounding)')); ?></option>
                    </select>
                    <small class="form-text"><?php p($l->t('How often interest compounds on this account')); ?></small>
                </div>

                <div class="form-group conditional" id="credit-limit-group">
                    <label for="account-credit-limit"><?php p($l->t('Credit Limit')); ?></label>
                    <input type="number" id="account-credit-limit" step="0.01" min="0" aria-describedby="account-credit-limit-help">
                    <small id="account-credit-limit-help" class="form-text"><?php p($l->t('Maximum credit limit for credit cards')); ?></small>
                </div>

                <div class="form-group conditional" id="overdraft-limit-group">
                    <label for="account-overdraft-limit"><?php p($l->t('Overdraft Limit')); ?></label>
                    <input type="number" id="account-overdraft-limit" step="0.01" min="0" aria-describedby="account-overdraft-limit-help">
                    <small id="account-overdraft-limit-help" class="form-text"><?php p($l->t('Maximum overdraft amount')); ?></small>
                </div>

                <div class="form-group conditional" id="minimum-payment-group">
                    <label for="account-minimum-payment"><?php p($l->t('Minimum Payment')); ?></label>
                    <input type="number" id="account-minimum-payment" step="0.01" min="0" aria-describedby="account-minimum-payment-help">
                    <small id="account-minimum-payment-help" class="form-text"><?php p($l->t('Minimum monthly payment for debt payoff calculations')); ?></small>
                </div>
            </div>

            <div class="modal-buttons">
                <button type="submit" class="primary" aria-label="<?php p($l->t('Save account')); ?>"><?php p($l->t('Save')); ?></button>
                <button type="button" class="secondary cancel-btn" aria-label="<?php p($l->t('Cancel and close dialog')); ?>"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Bill Modal -->
<div id="bill-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="bill-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="bill-modal-title"><?php p($l->t('Add/Edit Bill')); ?></h3>
        <form id="bill-form">
            <input type="hidden" id="bill-id">

            <div class="form-group">
                <label for="bill-name"><?php p($l->t('Bill Name / Vendor')); ?> <span class="required">*</span></label>
                <input type="text" id="bill-name" required aria-describedby="bill-name-help" maxlength="255" placeholder="<?php p($l->t('e.g., Netflix, Rent, Electric Bill')); ?>">
                <small id="bill-name-help" class="form-text"><?php p($l->t('Name of the vendor or recurring bill')); ?></small>
            </div>

            <div class="form-group">
                <label for="bill-amount"><?php p($l->t('Amount')); ?> <span class="required">*</span></label>
                <input type="number" id="bill-amount" step="0.01" required min="0" aria-describedby="bill-amount-help" placeholder="0.00">
                <small id="bill-amount-help" class="form-text"><?php p($l->t('Expected bill amount')); ?></small>
            </div>

            <div class="form-group">
                <label for="bill-frequency"><?php p($l->t('Frequency')); ?> <span class="required">*</span></label>
                <select id="bill-frequency" required aria-describedby="bill-frequency-help">
                    <option value="weekly"><?php p($l->t('Weekly')); ?></option>
                    <option value="biweekly"><?php p($l->t('Bi-Weekly')); ?></option>
                    <option value="monthly" selected><?php p($l->t('Monthly')); ?></option>
                    <option value="quarterly"><?php p($l->t('Quarterly')); ?></option>
                    <option value="semi-annually"><?php p($l->t('Semi-Annually')); ?></option>
                    <option value="yearly"><?php p($l->t('Yearly')); ?></option>
                    <option value="one-time"><?php p($l->t('One-Time')); ?></option>
                    <option value="custom"><?php p($l->t('Custom')); ?></option>
                </select>
                <small id="bill-frequency-help" class="form-text"><?php p($l->t('How often this bill is due')); ?></small>
            </div>

            <div class="form-group" id="custom-months-group" style="display: none;">
                <label><?php p($l->t('Select Months')); ?> <span class="required">*</span></label>
                <div id="bill-custom-months" class="custom-months-selector">
                    <label><input type="checkbox" value="1"> <?php p($l->t('Jan')); ?></label>
                    <label><input type="checkbox" value="2"> <?php p($l->t('Feb')); ?></label>
                    <label><input type="checkbox" value="3"> <?php p($l->t('Mar')); ?></label>
                    <label><input type="checkbox" value="4"> <?php p($l->t('Apr')); ?></label>
                    <label><input type="checkbox" value="5"> <?php p($l->t('May')); ?></label>
                    <label><input type="checkbox" value="6"> <?php p($l->t('Jun')); ?></label>
                    <label><input type="checkbox" value="7"> <?php p($l->t('Jul')); ?></label>
                    <label><input type="checkbox" value="8"> <?php p($l->t('Aug')); ?></label>
                    <label><input type="checkbox" value="9"> <?php p($l->t('Sep')); ?></label>
                    <label><input type="checkbox" value="10"> <?php p($l->t('Oct')); ?></label>
                    <label><input type="checkbox" value="11"> <?php p($l->t('Nov')); ?></label>
                    <label><input type="checkbox" value="12"> <?php p($l->t('Dec')); ?></label>
                </div>
                <small class="form-text"><?php p($l->t('Select which months this bill occurs')); ?></small>
            </div>

            <div class="form-group" id="due-day-group">
                <label for="bill-due-day"><?php p($l->t('Due Day')); ?></label>
                <input type="number" id="bill-due-day" min="1" max="31" aria-describedby="bill-due-day-help" placeholder="1-31">
                <small id="bill-due-day-help" class="form-text"><?php p($l->t('Day of the month when bill is due')); ?></small>
            </div>

            <div class="form-group" id="due-month-group" style="display: none;">
                <label for="bill-due-month"><?php p($l->t('Due Month')); ?></label>
                <select id="bill-due-month" aria-describedby="bill-due-month-help">
                    <option value=""><?php p($l->t('Select month...')); ?></option>
                    <option value="1"><?php p($l->t('January')); ?></option>
                    <option value="2"><?php p($l->t('February')); ?></option>
                    <option value="3"><?php p($l->t('March')); ?></option>
                    <option value="4"><?php p($l->t('April')); ?></option>
                    <option value="5"><?php p($l->t('May')); ?></option>
                    <option value="6"><?php p($l->t('June')); ?></option>
                    <option value="7"><?php p($l->t('July')); ?></option>
                    <option value="8"><?php p($l->t('August')); ?></option>
                    <option value="9"><?php p($l->t('September')); ?></option>
                    <option value="10"><?php p($l->t('October')); ?></option>
                    <option value="11"><?php p($l->t('November')); ?></option>
                    <option value="12"><?php p($l->t('December')); ?></option>
                </select>
                <small id="bill-due-month-help" class="form-text"><?php p($l->t('Month when yearly bill is due')); ?></small>
            </div>

            <div class="form-group">
                <label for="bill-category"><?php p($l->t('Category')); ?></label>
                <select id="bill-category" aria-describedby="bill-category-help">
                    <option value=""><?php p($l->t('No category')); ?></option>
                </select>
                <small id="bill-category-help" class="form-text"><?php p($l->t('Categorize this bill (optional)')); ?></small>
            </div>

            <div id="bill-tags-container"></div>

            <!-- Split Template Section -->
            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" id="bill-split-enabled" style="width: 18px; height: 18px; cursor: pointer;">
                    <span><?php p($l->t('Split across categories')); ?></span>
                </label>
                <small class="form-text"><?php p($l->t('Define how this bill should be split across categories when paid')); ?></small>
            </div>

            <div id="bill-split-container" style="display: none;">
                <div id="bill-split-rows"></div>
                <div style="display: flex; justify-content: space-between; align-items: center; margin: 8px 0;">
                    <button type="button" id="bill-add-split-btn" class="secondary" style="font-size: 0.85em;">+ <?php p($l->t('Add Split')); ?></button>
                    <span id="bill-split-remaining" class="form-text" style="font-weight: 500;"></span>
                </div>
            </div>

            <div class="form-group">
                <label for="bill-account"><?php p($l->t('Pay From Account')); ?></label>
                <select id="bill-account" aria-describedby="bill-account-help">
                    <option value=""><?php p($l->t('No specific account')); ?></option>
                </select>
                <small id="bill-account-help" class="form-text"><?php p($l->t('Account used to pay this bill (optional)')); ?></small>
            </div>

            <div class="form-group">
                <label for="bill-auto-pattern"><?php p($l->t('Auto-detect Pattern')); ?></label>
                <input type="text" id="bill-auto-pattern" aria-describedby="bill-auto-pattern-help" maxlength="255" placeholder="<?php p($l->t('e.g., NETFLIX, SPOTIFY')); ?>">
                <small id="bill-auto-pattern-help" class="form-text"><?php p($l->t('Pattern to match in transaction descriptions for auto-linking')); ?></small>
            </div>

            <div class="form-group">
                <label for="bill-notes"><?php p($l->t('Notes')); ?></label>
                <textarea id="bill-notes" aria-describedby="bill-notes-help" maxlength="500" rows="2" placeholder="<?php p($l->t('Additional notes...')); ?>"></textarea>
                <small id="bill-notes-help" class="form-text"><?php p($l->t('Any additional notes (optional)')); ?></small>
            </div>

            <div class="form-group" id="end-date-group">
                <label for="bill-end-date"><?php p($l->t('End Date')); ?></label>
                <input type="date" id="bill-end-date" aria-describedby="bill-end-date-help">
                <small id="bill-end-date-help" class="form-text"><?php p($l->t('Bill will automatically stop after this date (optional)')); ?></small>
            </div>

            <div class="form-group" id="remaining-payments-group">
                <label for="bill-remaining-payments"><?php p($l->t('Remaining Payments')); ?></label>
                <input type="number" id="bill-remaining-payments" min="1" aria-describedby="bill-remaining-payments-help" placeholder="<?php p($l->t('e.g., 10')); ?>">
                <small id="bill-remaining-payments-help" class="form-text"><?php p($l->t('Number of payments left before bill auto-deactivates (optional)')); ?></small>
            </div>

            <div class="form-group">
                <label for="bill-reminder-days"><?php p($l->t('Reminder')); ?></label>
                <select id="bill-reminder-days" aria-describedby="bill-reminder-help">
                    <option value=""><?php p($l->t('No reminder')); ?></option>
                    <option value="0"><?php p($l->t('On due date')); ?></option>
                    <option value="1"><?php p($l->t('1 day before')); ?></option>
                    <option value="2"><?php p($l->t('2 days before')); ?></option>
                    <option value="3"><?php p($l->t('3 days before')); ?></option>
                    <option value="5"><?php p($l->t('5 days before')); ?></option>
                    <option value="7"><?php p($l->t('1 week before')); ?></option>
                    <option value="14"><?php p($l->t('2 weeks before')); ?></option>
                </select>
                <small id="bill-reminder-help" class="form-text"><?php p($l->t('Receive a notification before bill is due')); ?></small>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" id="bill-create-transaction" style="width: 18px; height: 18px; cursor: pointer;">
                    <span><?php p($l->t('Create future transaction for this bill')); ?></span>
                </label>
                <small class="form-text"><?php p($l->t("Automatically creates a transaction on the bill's due date")); ?></small>
            </div>

            <div class="form-group" id="transaction-date-group" style="display: none;">
                <label for="bill-transaction-date"><?php p($l->t('Transaction Date')); ?></label>
                <input type="date" id="bill-transaction-date" aria-describedby="bill-transaction-date-help">
                <small id="bill-transaction-date-help" class="form-text"><?php p($l->t('Leave empty to use next due date')); ?></small>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" id="bill-auto-pay" style="width: 18px; height: 18px; cursor: pointer;">
                    <span><?php p($l->t('Auto-pay when due')); ?></span>
                </label>
                <small class="form-text"><?php p($l->t('Automatically mark this bill as paid when due date arrives (requires account)')); ?></small>
            </div>

            <div class="form-group" id="auto-pay-failed-warning" style="display: none;">
                <div style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; color: #856404;">
                    <strong><?php p($l->t('Auto-pay failed!')); ?></strong> <?php p($l->t('Auto-pay has been disabled. Please check the bill details and re-enable if needed.')); ?>
                </div>
            </div>

            <div class="modal-buttons">
                <button type="submit" class="primary" aria-label="<?php p($l->t('Save bill')); ?>"><?php p($l->t('Save')); ?></button>
                <button type="button" class="secondary cancel-btn" aria-label="<?php p($l->t('Cancel and close dialog')); ?>"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Rule Modal -->
<div id="rule-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="rule-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="rule-modal-title"><?php p($l->t('Add/Edit Rule')); ?></h3>
        <form id="rule-form">
            <input type="hidden" id="rule-id">

            <!-- Basic Info Section -->
            <div class="form-section" style="background: transparent; border: none; padding: 0 0 20px 0;">
                <div style="display: grid; grid-template-columns: 1fr 80px; gap: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="rule-name"><?php p($l->t('Rule Name')); ?> <span class="required">*</span></label>
                        <input type="text" id="rule-name" required maxlength="255" placeholder="<?php p($l->t('e.g., Amazon Purchases, Grocery Stores')); ?>">
                        <small class="form-text"><?php p($l->t('A descriptive name for this rule')); ?></small>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="rule-priority"><?php p($l->t('Priority')); ?></label>
                        <input type="number" id="rule-priority" min="0" max="100" value="0">
                        <small class="form-text">0-100 (higher first)</small>
                    </div>
                </div>
            </div>

            <!-- Matching Criteria Section -->
            <fieldset class="form-section">
                <legend><?php p($l->t('Matching Criteria')); ?></legend>
                <small class="section-help"><?php p($l->t('Define when this rule should apply to a transaction')); ?></small>

                <!-- v1 Criteria (legacy - hidden for new rules) -->
                <div id="rule-criteria-v1" style="display: none;">
                    <div class="form-group">
                        <label for="rule-field"><?php p($l->t('Match Field')); ?> <span class="required">*</span></label>
                        <select id="rule-field">
                            <option value="description"><?php p($l->t('Description')); ?></option>
                            <option value="vendor"><?php p($l->t('Vendor')); ?></option>
                            <option value="reference"><?php p($l->t('Reference')); ?></option>
                            <option value="notes"><?php p($l->t('Notes')); ?></option>
                            <option value="amount"><?php p($l->t('Amount')); ?></option>
                        </select>
                        <small class="form-text"><?php p($l->t('Which transaction field to match against')); ?></small>
                    </div>

                    <div class="form-group">
                        <label for="rule-match-type"><?php p($l->t('Match Type')); ?> <span class="required">*</span></label>
                        <select id="rule-match-type">
                            <option value="contains"><?php p($l->t('Contains')); ?></option>
                            <option value="exact"><?php p($l->t('Exact Match')); ?></option>
                            <option value="starts_with"><?php p($l->t('Starts With')); ?></option>
                            <option value="ends_with"><?php p($l->t('Ends With')); ?></option>
                            <option value="regex"><?php p($l->t('Regex')); ?></option>
                        </select>
                        <small class="form-text"><?php p($l->t('How to match the pattern')); ?></small>
                    </div>

                    <div class="form-group">
                        <label for="rule-pattern"><?php p($l->t('Pattern')); ?> <span class="required">*</span></label>
                        <input type="text" id="rule-pattern" maxlength="500" placeholder="<?php p($l->t('e.g., AMAZON, grocery|supermarket')); ?>">
                        <small class="form-text"><?php p($l->t('Text or pattern to match (case-insensitive)')); ?></small>
                    </div>
                </div>

                <!-- v2 Criteria (advanced - visual query builder) -->
                <div id="rule-criteria-v2" style="display: block;">
                    <div id="criteria-builder-container"></div>
                </div>
            </fieldset>

            <!-- Actions Section -->
            <fieldset class="form-section">
                <legend><?php p($l->t('Actions')); ?></legend>
                <small class="section-help"><?php p($l->t('What to do when a transaction matches these criteria')); ?></small>

                <!-- ActionBuilder container (v2 advanced actions) -->
                <div id="action-builder-container"></div>

                <!-- Options -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px;">
                    <div class="form-group checkbox-group">
                        <label>
                            <input type="checkbox" id="rule-active" checked>
                            <strong><?php p($l->t('Active')); ?></strong>
                        </label>
                        <small class="form-text"><?php p($l->t('Only active rules are applied')); ?></small>
                    </div>

                    <div class="form-group checkbox-group">
                        <label>
                            <input type="checkbox" id="rule-apply-on-import" checked>
                            <strong><?php p($l->t('Apply during import')); ?></strong>
                        </label>
                        <small class="form-text"><?php p($l->t('Auto-apply when importing transactions')); ?></small>
                    </div>
                </div>
            </fieldset>

            <!-- Preview Section (hidden until preview is run) -->
            <div id="rule-preview-section" class="rule-preview-section" style="display: none;">
                <h4><?php p($l->t('Preview:')); ?> <span id="rule-preview-count">0</span> <?php p($l->t('matching transactions')); ?> <span id="rule-preview-limit-note" style="display: none;">(<?php p($l->t('showing first 50')); ?>)</span></h4>
                <div class="preview-table-container">
                    <table id="rule-preview-table" class="preview-table">
                        <thead>
                            <tr>
                                <th><?php p($l->t('Date')); ?></th>
                                <th><?php p($l->t('Description')); ?></th>
                                <th><?php p($l->t('Amount')); ?></th>
                                <th><?php p($l->t('Current Category')); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <!-- Run Rule Results Section (hidden until run) -->
            <div id="rule-run-results" class="rule-run-results" style="display: none;">
                <div class="results-summary">
                    <div class="result-item success">
                        <span class="result-count" id="rule-run-success-count">0</span>
                        <span class="result-label"><?php p($l->t('Updated')); ?></span>
                    </div>
                    <div class="result-item skipped">
                        <span class="result-count" id="rule-run-skipped-count">0</span>
                        <span class="result-label"><?php p($l->t('Skipped')); ?></span>
                    </div>
                    <div class="result-item failed">
                        <span class="result-count" id="rule-run-failed-count">0</span>
                        <span class="result-label"><?php p($l->t('Failed')); ?></span>
                    </div>
                </div>
            </div>

            <div class="modal-buttons">
                <button type="button" id="preview-rule-btn" class="secondary" aria-label="<?php p($l->t('Preview matching transactions')); ?>"><?php p($l->t('Preview Matches')); ?></button>
                <button type="button" id="run-rule-now-btn" class="secondary" aria-label="<?php p($l->t('Apply rule to uncategorized transactions now')); ?>"><?php p($l->t('Run Rule Now')); ?></button>
                <button type="submit" class="primary" aria-label="<?php p($l->t('Save rule')); ?>"><?php p($l->t('Save')); ?></button>
                <button type="button" class="secondary cancel-btn" aria-label="<?php p($l->t('Cancel and close dialog')); ?>"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Apply Rules Modal -->
<div id="apply-rules-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="apply-rules-modal-title" aria-hidden="true">
    <div class="modal-content modal-large">
        <h3 id="apply-rules-modal-title"><?php p($l->t('Apply Rules to Transactions')); ?></h3>

        <!-- Filters Section -->
        <div class="apply-rules-filters">
            <h4><?php p($l->t('Filter Transactions')); ?></h4>

            <div class="filter-row">
                <div class="form-group">
                    <label for="apply-account-filter"><?php p($l->t('Account')); ?></label>
                    <select id="apply-account-filter">
                        <option value=""><?php p($l->t('All Accounts')); ?></option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="apply-date-start"><?php p($l->t('From Date')); ?></label>
                    <input type="date" id="apply-date-start">
                </div>

                <div class="form-group">
                    <label for="apply-date-end"><?php p($l->t('To Date')); ?></label>
                    <input type="date" id="apply-date-end">
                </div>
            </div>

            <div class="form-group" style="margin-top: 24px; padding: 16px; background-color: var(--color-background-hover); border-radius: 8px; border: 1px solid var(--color-border);">
                <label class="checkbox-label" style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; margin: 0;">
                    <input type="checkbox" id="apply-uncategorized-only" checked style="cursor: pointer; margin-top: 2px; width: 18px; height: 18px; flex-shrink: 0;">
                    <div style="flex: 1;">
                        <span style="font-weight: 600; font-size: 14px; color: var(--color-main-text); display: block; margin-bottom: 4px;"><?php p($l->t('Only apply to uncategorized transactions')); ?></span>
                        <small style="color: var(--color-text-maxcontrast); font-size: 13px; line-height: 1.4;">
                            <?php p($l->t('When enabled, rules will only be applied to transactions that don\'t have a category assigned')); ?>
                        </small>
                    </div>
                </label>
            </div>
        </div>

        <!-- Results (shown after apply) -->
        <div id="apply-rules-results" class="apply-rules-results" style="display: none;">
            <div class="results-summary">
                <div class="result-item success">
                    <span class="result-count" id="result-success-count">0</span>
                    <span class="result-label"><?php p($l->t('Updated')); ?></span>
                </div>
                <div class="result-item skipped">
                    <span class="result-count" id="result-skipped-count">0</span>
                    <span class="result-label"><?php p($l->t('Skipped')); ?></span>
                </div>
                <div class="result-item failed">
                    <span class="result-count" id="result-failed-count">0</span>
                    <span class="result-label"><?php p($l->t('Failed')); ?></span>
                </div>
            </div>
        </div>

        <div class="modal-buttons">
            <button type="button" id="execute-apply-rules-btn" class="primary"><?php p($l->t('Apply Rules')); ?></button>
            <button type="button" class="secondary cancel-btn"><?php p($l->t('Close')); ?></button>
        </div>
    </div>
</div>

<!-- Recurring Income Modal -->
<div id="income-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="income-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="income-modal-title"><?php p($l->t('Add/Edit Recurring Income')); ?></h3>
        <form id="income-form">
            <input type="hidden" id="income-id">

            <div class="form-group">
                <label for="income-name"><?php p($l->t('Income Name')); ?> <span class="required">*</span></label>
                <input type="text" id="income-name" required aria-describedby="income-name-help" maxlength="255" placeholder="<?php p($l->t('e.g., Salary, Dividends, Rental Income')); ?>">
                <small id="income-name-help" class="form-text"><?php p($l->t('Name of the recurring income')); ?></small>
            </div>

            <div class="form-group">
                <label for="income-amount"><?php p($l->t('Expected Amount')); ?> <span class="required">*</span></label>
                <input type="number" id="income-amount" step="0.01" required min="0" aria-describedby="income-amount-help" placeholder="0.00">
                <small id="income-amount-help" class="form-text"><?php p($l->t('Expected amount each period')); ?></small>
            </div>

            <div class="form-group">
                <label for="income-source"><?php p($l->t('Source')); ?></label>
                <input type="text" id="income-source" aria-describedby="income-source-help" maxlength="255" placeholder="<?php p($l->t('e.g., Employer Name, Company')); ?>">
                <small id="income-source-help" class="form-text"><?php p($l->t('Who pays this income (optional)')); ?></small>
            </div>

            <div class="form-group">
                <label for="income-frequency"><?php p($l->t('Frequency')); ?> <span class="required">*</span></label>
                <select id="income-frequency" required aria-describedby="income-frequency-help">
                    <option value="weekly"><?php p($l->t('Weekly')); ?></option>
                    <option value="biweekly"><?php p($l->t('Bi-Weekly')); ?></option>
                    <option value="monthly" selected><?php p($l->t('Monthly')); ?></option>
                    <option value="quarterly"><?php p($l->t('Quarterly')); ?></option>
                    <option value="semi-annually"><?php p($l->t('Semi-Annually')); ?></option>
                    <option value="yearly"><?php p($l->t('Yearly')); ?></option>
                    <option value="one-time"><?php p($l->t('One-Time')); ?></option>
                </select>
                <small id="income-frequency-help" class="form-text"><?php p($l->t('How often you receive this income')); ?></small>
            </div>

            <div class="form-group" id="expected-day-group">
                <label for="income-expected-day"><?php p($l->t('Expected Day')); ?></label>
                <input type="number" id="income-expected-day" min="1" max="31" aria-describedby="income-expected-day-help" placeholder="1-31">
                <small id="income-expected-day-help" class="form-text"><?php p($l->t('Day of the month when income is expected')); ?></small>
            </div>

            <div class="form-group" id="expected-month-group" style="display: none;">
                <label for="income-expected-month"><?php p($l->t('Expected Month')); ?></label>
                <select id="income-expected-month" aria-describedby="income-expected-month-help">
                    <option value=""><?php p($l->t('Select month...')); ?></option>
                    <option value="1"><?php p($l->t('January')); ?></option>
                    <option value="2"><?php p($l->t('February')); ?></option>
                    <option value="3"><?php p($l->t('March')); ?></option>
                    <option value="4"><?php p($l->t('April')); ?></option>
                    <option value="5"><?php p($l->t('May')); ?></option>
                    <option value="6"><?php p($l->t('June')); ?></option>
                    <option value="7"><?php p($l->t('July')); ?></option>
                    <option value="8"><?php p($l->t('August')); ?></option>
                    <option value="9"><?php p($l->t('September')); ?></option>
                    <option value="10"><?php p($l->t('October')); ?></option>
                    <option value="11"><?php p($l->t('November')); ?></option>
                    <option value="12"><?php p($l->t('December')); ?></option>
                </select>
                <small id="income-expected-month-help" class="form-text"><?php p($l->t('Month when yearly income is expected')); ?></small>
            </div>

            <div class="form-group">
                <label for="income-category"><?php p($l->t('Category')); ?></label>
                <select id="income-category" aria-describedby="income-category-help">
                    <option value=""><?php p($l->t('No category')); ?></option>
                </select>
                <small id="income-category-help" class="form-text"><?php p($l->t('Categorize this income (optional)')); ?></small>
            </div>

            <div class="form-group">
                <label for="income-account"><?php p($l->t('Receive To Account')); ?></label>
                <select id="income-account" aria-describedby="income-account-help">
                    <option value=""><?php p($l->t('No specific account')); ?></option>
                </select>
                <small id="income-account-help" class="form-text"><?php p($l->t('Account where income is received (optional)')); ?></small>
            </div>

            <div class="form-group">
                <label for="income-auto-pattern"><?php p($l->t('Auto-detect Pattern')); ?></label>
                <input type="text" id="income-auto-pattern" aria-describedby="income-auto-pattern-help" maxlength="255" placeholder="<?php p($l->t('e.g., PAYROLL, DIVIDEND')); ?>">
                <small id="income-auto-pattern-help" class="form-text"><?php p($l->t('Pattern to match in transaction descriptions for auto-linking')); ?></small>
            </div>

            <div class="form-group">
                <label for="income-notes"><?php p($l->t('Notes')); ?></label>
                <textarea id="income-notes" aria-describedby="income-notes-help" maxlength="500" rows="2" placeholder="<?php p($l->t('Additional notes...')); ?>"></textarea>
                <small id="income-notes-help" class="form-text"><?php p($l->t('Any additional notes (optional)')); ?></small>
            </div>

            <div class="modal-buttons">
                <button type="submit" class="primary" aria-label="<?php p($l->t('Save income')); ?>"><?php p($l->t('Save')); ?></button>
                <button type="button" class="secondary cancel-btn" aria-label="<?php p($l->t('Cancel and close dialog')); ?>"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Category Modal -->
<div id="category-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="category-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="category-modal-title"><?php p($l->t('Add/Edit Category')); ?></h3>
        <form id="category-form">
            <input type="hidden" id="category-id">

            <div class="form-group">
                <label for="category-name"><?php p($l->t('Category Name')); ?> <span class="required">*</span></label>
                <input type="text" id="category-name" required aria-describedby="category-name-help" maxlength="255">
                <small id="category-name-help" class="form-text"><?php p($l->t('Name for this category')); ?></small>
            </div>

            <div class="form-group">
                <label for="category-type"><?php p($l->t('Type')); ?> <span class="required">*</span></label>
                <select id="category-type" required aria-describedby="category-type-help">
                    <option value="expense"><?php p($l->t('Expense')); ?></option>
                    <option value="income"><?php p($l->t('Income')); ?></option>
                </select>
                <small id="category-type-help" class="form-text"><?php p($l->t('Whether this is for income or expenses')); ?></small>
            </div>

            <div class="form-group">
                <label for="category-parent"><?php p($l->t('Parent Category')); ?></label>
                <select id="category-parent" aria-describedby="category-parent-help">
                    <option value=""><?php p($l->t('None (Top Level)')); ?></option>
                </select>
                <small id="category-parent-help" class="form-text"><?php p($l->t('Make this a subcategory (optional)')); ?></small>
            </div>

            <div class="form-group">
                <label for="category-color"><?php p($l->t('Color')); ?></label>
                <input type="color" id="category-color" value="#3b82f6" aria-describedby="category-color-help">
                <small id="category-color-help" class="form-text"><?php p($l->t('Color for charts and display')); ?></small>
            </div>

            <!-- Tag Sets Container -->
            <div id="category-tag-sets-container"></div>

            <div class="modal-buttons">
                <button type="submit" class="primary" aria-label="<?php p($l->t('Save category')); ?>"><?php p($l->t('Save')); ?></button>
                <button type="button" class="secondary cancel-btn" aria-label="<?php p($l->t('Cancel and close dialog')); ?>"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Add Tag Set Modal -->
<div id="add-tag-set-modal" class="modal" style="display: none;">
    <div class="modal-content modal-small">
        <div class="modal-header">
            <h3><?php p($l->t('Add Tag Set')); ?></h3>
        </div>
        <form id="add-tag-set-form" class="modal-form">
            <input type="hidden" id="tag-set-category-id" name="categoryId">
            <div class="form-group">
                <label for="tag-set-name"><?php p($l->t('Tag Set Name')); ?> *</label>
                <input type="text" id="tag-set-name" name="name" required placeholder="<?php p($l->t('e.g., Activity, Equipment, Location')); ?>">
            </div>
            <div class="form-group">
                <label for="tag-set-description"><?php p($l->t('Description')); ?></label>
                <input type="text" id="tag-set-description" name="description" placeholder="<?php p($l->t('Optional description')); ?>">
            </div>
            <div class="modal-actions">
                <button type="submit" class="primary"><?php p($l->t('Add Tag Set')); ?></button>
                <button type="button" class="secondary cancel-tag-set-btn"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Add Tag Modal -->
<div id="add-tag-modal" class="modal" style="display: none;">
    <div class="modal-content modal-small">
        <div class="modal-header">
            <h3><?php p($l->t('Add Tag')); ?></h3>
        </div>
        <form id="add-tag-form" class="modal-form">
            <input type="hidden" id="tag-set-id" name="tagSetId">
            <input type="hidden" id="tag-category-id" name="categoryId">
            <div class="form-group">
                <label for="tag-name"><?php p($l->t('Tag Name')); ?> *</label>
                <input type="text" id="tag-name" name="name" required placeholder="<?php p($l->t('e.g., Fishing, Rods, Online')); ?>">
            </div>
            <div class="form-group">
                <label for="tag-color"><?php p($l->t('Color')); ?></label>
                <input type="color" id="tag-color" name="color" value="#4A90E2">
            </div>
            <div class="modal-actions">
                <button type="submit" class="primary"><?php p($l->t('Add Tag')); ?></button>
                <button type="button" class="secondary cancel-tag-btn"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Tag Modal -->
<div id="edit-tag-modal" class="modal" style="display: none;">
    <div class="modal-content modal-small">
        <div class="modal-header">
            <h3><?php p($l->t('Edit Tag')); ?></h3>
        </div>
        <form id="edit-tag-form" class="modal-form">
            <input type="hidden" id="edit-tag-id" name="tagId">
            <input type="hidden" id="edit-tag-tag-set-id" name="tagSetId">
            <input type="hidden" id="edit-tag-category-id" name="categoryId">
            <div class="form-group">
                <label for="edit-tag-name"><?php p($l->t('Tag Name')); ?> *</label>
                <input type="text" id="edit-tag-name" name="name" required placeholder="<?php p($l->t('e.g., Fishing, Rods, Online')); ?>">
            </div>
            <div class="form-group">
                <label for="edit-tag-color"><?php p($l->t('Color')); ?></label>
                <input type="color" id="edit-tag-color" name="color" value="#4A90E2">
            </div>
            <div class="modal-actions">
                <button type="submit" class="primary"><?php p($l->t('Save Changes')); ?></button>
                <button type="button" class="secondary cancel-tag-btn"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Tag Set Modal -->
<div id="edit-tag-set-modal" class="modal" style="display: none;">
    <div class="modal-content modal-small">
        <div class="modal-header">
            <h3><?php p($l->t('Edit Tag Set')); ?></h3>
        </div>
        <form id="edit-tag-set-form" class="modal-form">
            <input type="hidden" id="edit-tag-set-id" name="tagSetId">
            <input type="hidden" id="edit-tag-set-category-id" name="categoryId">
            <div class="form-group">
                <label for="edit-tag-set-name"><?php p($l->t('Tag Set Name')); ?> *</label>
                <input type="text" id="edit-tag-set-name" name="name" required placeholder="<?php p($l->t('e.g., Activity, Equipment, Location')); ?>">
            </div>
            <div class="form-group">
                <label for="edit-tag-set-description"><?php p($l->t('Description')); ?></label>
                <input type="text" id="edit-tag-set-description" name="description" placeholder="<?php p($l->t('Optional description')); ?>">
            </div>
            <div class="modal-actions">
                <button type="submit" class="primary"><?php p($l->t('Save Changes')); ?></button>
                <button type="button" class="secondary cancel-tag-set-btn"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Transaction Matching Modal -->
<div id="matching-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="matching-modal-title" aria-hidden="true">
    <div class="modal-content modal-wide">
        <h3 id="matching-modal-title"><?php p($l->t('Find Transfer Matches')); ?></h3>
        <div id="matching-source-transaction" class="matching-source">
            <h4><?php p($l->t('Source Transaction')); ?></h4>
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
                <p><?php p($l->t('Searching for matches...')); ?></p>
            </div>
            <div id="matching-empty" class="matching-empty" style="display: none;">
                <p><?php p($l->t('No matching transactions found within the date range.')); ?></p>
                <p class="hint"><?php p($l->t('Matches must have the same amount, opposite type (income/expense), and be within 3 days.')); ?></p>
            </div>
            <div id="matching-list" class="matching-list"></div>
        </div>
        <div class="modal-buttons">
            <button type="button" class="secondary cancel-btn" aria-label="<?php p($l->t('Close dialog')); ?>"><?php p($l->t('Close')); ?></button>
        </div>
    </div>
</div>

<!-- Bulk Match Modal -->
<div id="bulk-match-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="bulk-match-modal-title" aria-hidden="true">
    <div class="modal-content modal-wide">
        <h3 id="bulk-match-modal-title"><?php p($l->t('Match Transfers')); ?></h3>

        <!-- Config Section (rendered by JS) -->
        <div id="bulk-match-config" class="bulk-match-config"></div>

        <!-- Loading State -->
        <div id="bulk-match-loading" class="bulk-match-loading" style="display: none;">
            <div class="loading-spinner"></div>
            <p><?php p($l->t('Scanning for matching transactions...')); ?></p>
        </div>

        <!-- Results Content -->
        <div id="bulk-match-results" style="display: none;">
            <!-- Summary Stats -->
            <div id="bulk-match-summary" class="bulk-match-summary">
                <div class="summary-item success">
                    <span class="summary-count" id="auto-matched-count">0</span>
                    <span class="summary-label"><?php p($l->t('Matched Pairs')); ?></span>
                </div>
                <div class="summary-item warning">
                    <span class="summary-count" id="needs-review-count">0</span>
                    <span class="summary-label"><?php p($l->t('Need Review')); ?></span>
                </div>
            </div>

            <!-- Auto-Matched Section -->
            <div id="auto-matched-section" class="bulk-match-section" style="display: none;">
                <h4><?php p($l->t('Auto-Matched Pairs')); ?></h4>
                <p class="section-hint"><?php p($l->t('These transactions were automatically linked. Click Undo to unlink a pair.')); ?></p>
                <div id="auto-matched-list" class="bulk-match-list"></div>
            </div>

            <!-- Needs Review Section -->
            <div id="needs-review-section" class="bulk-match-section" style="display: none;">
                <h4><?php p($l->t('Needs Manual Review')); ?></h4>
                <p class="section-hint"><?php p($l->t('These transactions have multiple potential matches. Select the correct match for each.')); ?></p>
                <div id="needs-review-list" class="bulk-match-list"></div>
            </div>

            <!-- No Results State -->
            <div id="bulk-match-empty" class="bulk-match-empty" style="display: none;">
                <p><?php p($l->t('No matching transactions found.')); ?></p>
                <p class="hint"><?php p($l->t('Matches require: same amount, same currency, opposite type (income/expense), different accounts, within your configured date window.')); ?></p>
            </div>
        </div>

        <div class="modal-buttons">
            <button id="start-scan-btn" class="primary"><?php p($l->t('Scan for Matches')); ?></button>
            <button id="confirm-selected-btn" class="primary" style="display: none;"><?php p($l->t('Confirm Selected')); ?></button>
            <button type="button" class="secondary cancel-btn" id="bulk-match-close-btn" aria-label="<?php p($l->t('Close dialog')); ?>"><?php p($l->t('Cancel')); ?></button>
        </div>
    </div>
</div>

<!-- Split Transaction Modal -->
<div id="split-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="split-modal-title" aria-hidden="true">
    <div class="modal-content modal-wide">
        <h3 id="split-modal-title"><?php p($l->t('Split Transaction')); ?></h3>

        <!-- Transaction Info -->
        <div id="split-transaction-info" class="split-transaction-info">
            <div class="split-info-row">
                <span class="split-info-label"><?php p($l->t('Description:')); ?></span>
                <span id="split-tx-description" class="split-info-value"></span>
            </div>
            <div class="split-info-row">
                <span class="split-info-label"><?php p($l->t('Amount:')); ?></span>
                <span id="split-tx-amount" class="split-info-value"></span>
            </div>
            <div class="split-info-row">
                <span class="split-info-label"><?php p($l->t('Date:')); ?></span>
                <span id="split-tx-date" class="split-info-value"></span>
            </div>
        </div>

        <!-- Splits Container -->
        <div class="splits-header">
            <span><?php p($l->t('Category')); ?></span>
            <span><?php p($l->t('Amount')); ?></span>
            <span><?php p($l->t('Description (optional)')); ?></span>
            <span></span>
        </div>
        <div id="splits-container" class="splits-container">
            <!-- Split rows will be added dynamically -->
        </div>

        <!-- Add Row Button -->
        <button type="button" id="add-split-row-btn" class="add-split-row-btn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            <?php p($l->t('Add Split')); ?>
        </button>

        <!-- Remaining Amount -->
        <div id="split-remaining" class="split-remaining">
            <span class="split-remaining-label"><?php p($l->t('Remaining to allocate:')); ?></span>
            <span id="split-remaining-amount" class="split-remaining-amount">0.00</span>
        </div>

        <div class="modal-buttons">
            <button type="button" id="split-unsplit-btn" class="danger unsplit-btn" style="display: none;"><?php p($l->t('Unsplit Transaction')); ?></button>
            <div class="modal-buttons-right">
                <button type="button" id="split-save-btn" class="primary"><?php p($l->t('Save Splits')); ?></button>
                <button type="button" class="secondary cancel-btn" aria-label="<?php p($l->t('Cancel and close dialog')); ?>"><?php p($l->t('Cancel')); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Edit Modal -->
<div id="bulk-edit-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="bulk-edit-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="bulk-edit-modal-title"><?php p($l->t('Bulk Edit Transactions')); ?></h3>
        <p class="modal-description"><?php p($l->t('Edit fields for')); ?> <span id="bulk-edit-count">0</span> <?php p($l->t('selected transactions. Leave fields empty to keep existing values.')); ?></p>
        <form id="bulk-edit-form" aria-label="<?php p($l->t('Bulk edit form')); ?>">
            <div class="form-group">
                <label for="bulk-edit-category"><?php p($l->t('Category')); ?></label>
                <select id="bulk-edit-category" name="categoryId" aria-describedby="bulk-edit-category-help">
                    <option value=""><?php p($l->t('Don\'t change')); ?></option>
                </select>
                <small id="bulk-edit-category-help" class="form-text"><?php p($l->t('Update category for all selected transactions (optional)')); ?></small>
            </div>

            <div class="form-group">
                <label for="bulk-edit-vendor"><?php p($l->t('Vendor')); ?></label>
                <input type="text" id="bulk-edit-vendor" name="vendor" aria-describedby="bulk-edit-vendor-help" maxlength="255">
                <small id="bulk-edit-vendor-help" class="form-text"><?php p($l->t('Update vendor name for all selected transactions (optional)')); ?></small>
            </div>

            <div class="form-group">
                <label for="bulk-edit-reference"><?php p($l->t('Reference')); ?></label>
                <input type="text" id="bulk-edit-reference" name="reference" aria-describedby="bulk-edit-reference-help" maxlength="255">
                <small id="bulk-edit-reference-help" class="form-text"><?php p($l->t('Update reference number for all selected transactions (optional)')); ?></small>
            </div>

            <div class="form-group">
                <label for="bulk-edit-notes"><?php p($l->t('Notes')); ?></label>
                <textarea id="bulk-edit-notes" name="notes" rows="3" aria-describedby="bulk-edit-notes-help" maxlength="500"></textarea>
                <small id="bulk-edit-notes-help" class="form-text"><?php p($l->t('Update notes for all selected transactions (optional)')); ?></small>
            </div>

            <div class="modal-buttons">
                <button type="button" class="primary" id="bulk-edit-submit-btn" aria-label="<?php p($l->t('Update selected transactions')); ?>"><?php p($l->t('Update Transactions')); ?></button>
                <button type="button" class="secondary cancel-bulk-edit-btn" aria-label="<?php p($l->t('Cancel and close dialog')); ?>"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Contact Modal -->
<div id="contact-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="contact-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="contact-modal-title"><?php p($l->t('Add Contact')); ?></h3>
        <form id="contact-form" aria-label="<?php p($l->t('Contact form')); ?>">
            <input type="hidden" id="contact-id" name="id">
            <input type="hidden" id="contact-nextcloud-user-id" name="nextcloudUserId">

            <div class="form-group">
                <label for="contact-user-select"><?php p($l->t('Nextcloud User')); ?></label>
                <select id="contact-user-select">
                    <option value=""><?php p($l->t('— None (enter details manually) —')); ?></option>
                </select>
                <small class="form-text"><?php p($l->t('Select a Nextcloud user to link this contact, or choose manual for non-Nextcloud contacts')); ?></small>
            </div>

            <div class="form-group">
                <label for="contact-name"><?php p($l->t('Name')); ?> <span class="required">*</span></label>
                <input type="text" id="contact-name" name="name" required maxlength="255" placeholder="<?php p($l->t('e.g., John, Roommate, Partner')); ?>">
            </div>

            <div class="form-group">
                <label for="contact-email"><?php p($l->t('Email')); ?></label>
                <input type="email" id="contact-email" name="email" maxlength="255" placeholder="<?php p($l->t('Optional email address')); ?>">
            </div>

            <div class="modal-buttons">
                <button type="submit" class="primary"><?php p($l->t('Save')); ?></button>
                <button type="button" class="secondary cancel-btn"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Share Expense Modal -->
<div id="share-expense-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="share-expense-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="share-expense-modal-title"><?php p($l->t('Share Expense')); ?></h3>
        <form id="share-expense-form" aria-label="<?php p($l->t('Share expense form')); ?>">
            <input type="hidden" id="share-transaction-id" name="transactionId">

            <div class="share-transaction-info">
                <span id="share-transaction-date" class="share-date"></span>
                <span id="share-transaction-desc" class="share-desc"></span>
                <span id="share-transaction-amount" class="share-amount"></span>
            </div>

            <div class="form-group">
                <label for="share-contact"><?php p($l->t('Split with')); ?> <span class="required">*</span></label>
                <select id="share-contact" name="contactId" required>
                    <option value=""><?php p($l->t('Select a contact...')); ?></option>
                </select>
            </div>

            <div class="form-group">
                <label for="share-split-type"><?php p($l->t('Split Method')); ?></label>
                <select id="share-split-type" name="splitType">
                    <option value="50-50"><?php p($l->t('50/50 Split')); ?></option>
                    <option value="custom"><?php p($l->t('Custom Amount')); ?></option>
                </select>
            </div>

            <div class="form-group" id="share-custom-amount-group" style="display: none;">
                <label for="share-amount"><?php p($l->t('Amount They Owe You')); ?></label>
                <input type="number" id="share-amount" name="amount" step="0.01" placeholder="0.00">
                <small class="form-text"><?php p($l->t('Positive = they owe you, negative = you owe them')); ?></small>
            </div>

            <div class="form-group">
                <label for="share-notes"><?php p($l->t('Notes')); ?></label>
                <input type="text" id="share-notes" name="notes" maxlength="255" placeholder="<?php p($l->t('Optional notes about this split')); ?>">
            </div>

            <div class="modal-buttons">
                <button type="submit" class="primary"><?php p($l->t('Share Expense')); ?></button>
                <button type="button" class="secondary cancel-btn"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Settlement Modal -->
<div id="settlement-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="settlement-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="settlement-modal-title"><?php p($l->t('Settle Expenses')); ?></h3>
        <form id="settlement-form" aria-label="<?php p($l->t('Settlement form')); ?>">
            <input type="hidden" id="settlement-contact-id" name="contactId">

            <div class="settlement-contact-info">
                <span id="settlement-contact-name" class="contact-name"></span>
                <span id="settlement-balance" class="balance-amount"></span>
            </div>

            <div class="form-group">
                <label><?php p($l->t('Select expenses to settle')); ?></label>
                <div class="settlement-select-all">
                    <label>
                        <input type="checkbox" id="settlement-select-all">
                        <?php p($l->t('Select all')); ?>
                    </label>
                </div>
                <div id="settlement-shares-list" class="settlement-shares-list">
                </div>
                <div class="settlement-total">
                    <span><?php p($l->t('Total')); ?></span>
                    <span id="settlement-total-amount">0.00</span>
                </div>
            </div>

            <div class="form-group">
                <label for="settlement-date"><?php p($l->t('Date')); ?> <span class="required">*</span></label>
                <input type="date" id="settlement-date" name="date" required>
            </div>

            <div class="form-group">
                <label for="settlement-notes"><?php p($l->t('Notes')); ?></label>
                <input type="text" id="settlement-notes" name="notes" maxlength="255" placeholder="<?php p($l->t('Optional notes')); ?>">
            </div>

            <div class="modal-buttons">
                <button type="submit" class="primary"><?php p($l->t('Settle Selected')); ?></button>
                <button type="button" class="secondary cancel-btn"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Contact Details Modal -->
<div id="contact-details-modal" class="modal" style="display: none;" role="dialog" aria-labelledby="contact-details-modal-title" aria-hidden="true">
    <div class="modal-content modal-wide">
        <h3 id="contact-details-modal-title"><?php p($l->t('Contact Details')); ?></h3>
        <div class="contact-details-header">
            <div class="contact-info">
                <span id="contact-details-name" class="contact-name"></span>
                <span id="contact-details-email" class="contact-email"></span>
            </div>
            <div class="contact-balance">
                <span class="balance-label"><?php p($l->t('Balance:')); ?></span>
                <span id="contact-details-balance" class="balance-value"></span>
            </div>
        </div>

        <div class="contact-actions">
            <button id="settle-all-btn" class="primary"><?php p($l->t('Settle All')); ?></button>
            <button id="record-settlement-btn" class="secondary"><?php p($l->t('Record Payment')); ?></button>
        </div>

        <div class="contact-tabs">
            <button class="tab-button active" data-tab="shares"><?php p($l->t('Shared Expenses')); ?></button>
            <button class="tab-button" data-tab="settlements"><?php p($l->t('Settlement History')); ?></button>
        </div>

        <div id="contact-shares-tab" class="tab-content active">
            <div id="contact-shares-list" class="shares-list">
                <div class="empty-state-small"><?php p($l->t('No shared expenses')); ?></div>
            </div>
        </div>

        <div id="contact-settlements-tab" class="tab-content" style="display: none;">
            <div id="contact-settlements-list" class="settlements-list">
                <div class="empty-state-small"><?php p($l->t('No settlements yet')); ?></div>
            </div>
        </div>

        <div class="modal-buttons">
            <button type="button" class="secondary close-btn"><?php p($l->t('Close')); ?></button>
        </div>
    </div>
</div>

<!-- Factory Reset Confirmation Modal -->
<div id="factory-reset-modal" class="modal danger-modal" style="display: none;" role="dialog" aria-labelledby="factory-reset-modal-title" aria-hidden="true">
    <div class="modal-content">
        <h3 id="factory-reset-modal-title">
            <span class="icon-error" aria-hidden="true"></span>
            <?php p($l->t('Confirm Factory Reset')); ?>
        </h3>

        <div class="danger-warning">
            <p><strong><?php p($l->t('WARNING: This action cannot be undone!')); ?></strong></p>
            <p><?php p($l->t('A factory reset will permanently delete ALL of your data including:')); ?></p>
            <ul>
                <li><?php p($l->t('All accounts and their balances')); ?></li>
                <li><?php p($l->t('All transactions and transaction history')); ?></li>
                <li><?php p($l->t('All bills and recurring income')); ?></li>
                <li><?php p($l->t('All categories and budget settings')); ?></li>
                <li><?php p($l->t('All savings goals and pension accounts')); ?></li>
                <li><?php p($l->t('All import rules and settings')); ?></li>
                <li><?php p($l->t('All shared expenses and contacts')); ?></li>
            </ul>
            <p><strong><?php p($l->t('Only audit logs will be preserved for compliance purposes.')); ?></strong></p>
            <p><?php p($l->t('You will need to set up everything from scratch after this operation.')); ?></p>
        </div>

        <div class="form-group">
            <label for="factory-reset-confirm-input">
                <strong><?php p($l->t('Type DELETE to confirm:')); ?></strong>
            </label>
            <input type="text"
                   id="factory-reset-confirm-input"
                   class="factory-reset-confirm-input"
                   placeholder="<?php p($l->t('Type DELETE to confirm')); ?>"
                   autocomplete="off"
                   spellcheck="false">
            <small class="form-text"><?php p($l->t('You must type DELETE (all caps) exactly to proceed')); ?></small>
        </div>

        <div class="modal-buttons">
            <button type="button" class="secondary close-btn"><?php p($l->t('Cancel')); ?></button>
            <button type="button" id="factory-reset-confirm-btn" class="danger-btn" disabled>
                <span class="icon-delete" aria-hidden="true"></span>
                <?php p($l->t('Delete Everything')); ?>
            </button>
        </div>
    </div>
</div>

<!-- Bank Sync Connection Modal -->
<div id="bank-sync-modal" class="modal" style="display: none;" role="dialog" aria-label="<?php p($l->t('Add Bank Connection')); ?>">
    <div class="modal-content" style="max-width: 500px;">
        <h3><?php p($l->t('Add Bank Connection')); ?></h3>

        <div class="form-group">
            <label for="bank-sync-provider"><?php p($l->t('Provider')); ?></label>
            <select id="bank-sync-provider">
                <option value=""><?php p($l->t('Select a provider...')); ?></option>
                <option value="gocardless"><?php p($l->t('GoCardless (UK/Europe)')); ?></option>
                <option value="simplefin"><?php p($l->t('SimpleFIN Bridge (US)')); ?></option>
            </select>
        </div>

        <div class="form-group">
            <label for="bank-sync-name"><?php p($l->t('Connection Name')); ?></label>
            <input type="text" id="bank-sync-name" placeholder="<?php p($l->t('e.g. My Bank')); ?>" maxlength="255">
        </div>

        <!-- SimpleFIN fields -->
        <div id="simplefin-fields" style="display: none;">
            <div class="form-group">
                <label for="bank-sync-setup-token"><?php p($l->t('Setup Token')); ?></label>
                <input type="text" id="bank-sync-setup-token" placeholder="<?php p($l->t('Paste your SimpleFIN setup token')); ?>">
                <small class="form-text"><?php p($l->t('Get a token from beta-bridge.simplefin.org')); ?></small>
            </div>
        </div>

        <!-- GoCardless fields -->
        <div id="gocardless-fields" style="display: none;">
            <div class="form-group">
                <label for="bank-sync-secret-id"><?php p($l->t('Secret ID')); ?></label>
                <input type="text" id="bank-sync-secret-id" placeholder="<?php p($l->t('Your GoCardless Secret ID')); ?>">
            </div>
            <div class="form-group">
                <label for="bank-sync-secret-key"><?php p($l->t('Secret Key')); ?></label>
                <input type="password" id="bank-sync-secret-key" placeholder="<?php p($l->t('Your GoCardless Secret Key')); ?>">
            </div>
            <small class="form-text"><?php p($l->t('Get API keys from bankaccountdata.gocardless.com')); ?></small>
        </div>

        <div class="modal-buttons">
            <button type="button" class="primary" id="bank-sync-connect-btn"><?php p($l->t('Connect')); ?></button>
            <button type="button" class="secondary cancel-btn"><?php p($l->t('Cancel')); ?></button>
        </div>
    </div>
</div>
</div>