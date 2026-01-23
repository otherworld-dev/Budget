<?php

declare(strict_types=1);

return [
    'routes' => [
        // Page routes
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        
        // Account routes
        ['name' => 'account#index', 'url' => '/api/accounts', 'verb' => 'GET'],
        ['name' => 'account#show', 'url' => '/api/accounts/{id}', 'verb' => 'GET'],
        ['name' => 'account#create', 'url' => '/api/accounts', 'verb' => 'POST'],
        ['name' => 'account#update', 'url' => '/api/accounts/{id}', 'verb' => 'PUT'],
        ['name' => 'account#destroy', 'url' => '/api/accounts/{id}', 'verb' => 'DELETE'],
        ['name' => 'account#summary', 'url' => '/api/accounts/summary', 'verb' => 'GET'],
        ['name' => 'account#getBalanceHistory', 'url' => '/api/accounts/{id}/balance-history', 'verb' => 'GET'],
        ['name' => 'account#reconcile', 'url' => '/api/accounts/{id}/reconcile', 'verb' => 'POST'],
        ['name' => 'account#reveal', 'url' => '/api/accounts/{id}/reveal', 'verb' => 'GET'],

        // Account validation routes
        ['name' => 'account#validateIban', 'url' => '/api/accounts/validate/iban', 'verb' => 'POST'],
        ['name' => 'account#validateRoutingNumber', 'url' => '/api/accounts/validate/routing-number', 'verb' => 'POST'],
        ['name' => 'account#validateSortCode', 'url' => '/api/accounts/validate/sort-code', 'verb' => 'POST'],
        ['name' => 'account#validateSwiftBic', 'url' => '/api/accounts/validate/swift-bic', 'verb' => 'POST'],
        ['name' => 'account#getBankingInstitutions', 'url' => '/api/accounts/banking-institutions', 'verb' => 'GET'],
        ['name' => 'account#getBankingFieldRequirements', 'url' => '/api/accounts/banking-requirements/{currency}', 'verb' => 'GET'],
        
        // Transaction routes
        ['name' => 'transaction#index', 'url' => '/api/transactions', 'verb' => 'GET'],
        ['name' => 'transaction#show', 'url' => '/api/transactions/{id}', 'verb' => 'GET'],
        ['name' => 'transaction#create', 'url' => '/api/transactions', 'verb' => 'POST'],
        ['name' => 'transaction#update', 'url' => '/api/transactions/{id}', 'verb' => 'PUT'],
        ['name' => 'transaction#destroy', 'url' => '/api/transactions/{id}', 'verb' => 'DELETE'],
        ['name' => 'transaction#search', 'url' => '/api/transactions/search', 'verb' => 'GET'],
        ['name' => 'transaction#uncategorized', 'url' => '/api/transactions/uncategorized', 'verb' => 'GET'],
        ['name' => 'transaction#bulkCategorize', 'url' => '/api/transactions/bulk-categorize', 'verb' => 'POST'],
        ['name' => 'transaction#getMatches', 'url' => '/api/transactions/{id}/matches', 'verb' => 'GET'],
        ['name' => 'transaction#link', 'url' => '/api/transactions/{id}/link/{targetId}', 'verb' => 'POST'],
        ['name' => 'transaction#unlink', 'url' => '/api/transactions/{id}/link', 'verb' => 'DELETE'],
        ['name' => 'transaction#bulkMatch', 'url' => '/api/transactions/bulk-match', 'verb' => 'POST'],
        ['name' => 'transaction#bulkDelete', 'url' => '/api/transactions/bulk-delete', 'verb' => 'POST'],
        ['name' => 'transaction#bulkReconcile', 'url' => '/api/transactions/bulk-reconcile', 'verb' => 'POST'],
        ['name' => 'transaction#bulkEdit', 'url' => '/api/transactions/bulk-edit', 'verb' => 'POST'],
        ['name' => 'transaction#getSplits', 'url' => '/api/transactions/{id}/splits', 'verb' => 'GET'],
        ['name' => 'transaction#split', 'url' => '/api/transactions/{id}/splits', 'verb' => 'POST'],
        ['name' => 'transaction#unsplit', 'url' => '/api/transactions/{id}/splits', 'verb' => 'DELETE'],
        ['name' => 'transaction#updateSplit', 'url' => '/api/transactions/{id}/splits/{splitId}', 'verb' => 'PUT'],

        // Category routes - specific paths before {id} wildcard
        ['name' => 'category#index', 'url' => '/api/categories', 'verb' => 'GET'],
        ['name' => 'category#tree', 'url' => '/api/categories/tree', 'verb' => 'GET'],
        ['name' => 'category#allSpending', 'url' => '/api/categories/spending', 'verb' => 'GET'],
        ['name' => 'category#create', 'url' => '/api/categories', 'verb' => 'POST'],
        ['name' => 'category#show', 'url' => '/api/categories/{id}', 'verb' => 'GET'],
        ['name' => 'category#update', 'url' => '/api/categories/{id}', 'verb' => 'PUT'],
        ['name' => 'category#destroy', 'url' => '/api/categories/{id}', 'verb' => 'DELETE'],
        
        // Import routes
        ['name' => 'import#upload', 'url' => '/api/import/upload', 'verb' => 'POST'],
        ['name' => 'import#preview', 'url' => '/api/import/preview', 'verb' => 'POST'],
        ['name' => 'import#process', 'url' => '/api/import/process', 'verb' => 'POST'],
        ['name' => 'import#execute', 'url' => '/api/import/execute', 'verb' => 'POST'],
        ['name' => 'import#rollback', 'url' => '/api/import/rollback/{importId}', 'verb' => 'POST'],
        ['name' => 'import#history', 'url' => '/api/import/history', 'verb' => 'GET'],
        ['name' => 'import#templates', 'url' => '/api/import/templates', 'verb' => 'GET'],
        
        // Import rules routes (also used as general categorization rules)
        ['name' => 'importRule#index', 'url' => '/api/import-rules', 'verb' => 'GET'],
        ['name' => 'importRule#show', 'url' => '/api/import-rules/{id}', 'verb' => 'GET'],
        ['name' => 'importRule#create', 'url' => '/api/import-rules', 'verb' => 'POST'],
        ['name' => 'importRule#update', 'url' => '/api/import-rules/{id}', 'verb' => 'PUT'],
        ['name' => 'importRule#destroy', 'url' => '/api/import-rules/{id}', 'verb' => 'DELETE'],
        ['name' => 'importRule#test', 'url' => '/api/import-rules/test', 'verb' => 'POST'],
        ['name' => 'importRule#preview', 'url' => '/api/import-rules/preview', 'verb' => 'POST'],
        ['name' => 'importRule#apply', 'url' => '/api/import-rules/apply', 'verb' => 'POST'],
        
        // Forecast routes
        ['name' => 'forecast#live', 'url' => '/api/forecast/live', 'verb' => 'GET'],
        ['name' => 'forecast#generate', 'url' => '/api/forecast/generate', 'verb' => 'POST'],
        ['name' => 'forecast#enhanced', 'url' => '/api/forecast/enhanced', 'verb' => 'POST'],
        ['name' => 'forecast#export', 'url' => '/api/forecast/export', 'verb' => 'POST'],
        ['name' => 'forecast#cashflow', 'url' => '/api/forecast/cashflow', 'verb' => 'GET'],
        ['name' => 'forecast#trends', 'url' => '/api/forecast/trends', 'verb' => 'GET'],

        // Bills routes - specific paths before {id} wildcard
        ['name' => 'bill#index', 'url' => '/api/bills', 'verb' => 'GET'],
        ['name' => 'bill#create', 'url' => '/api/bills', 'verb' => 'POST'],
        ['name' => 'bill#upcoming', 'url' => '/api/bills/upcoming', 'verb' => 'GET'],
        ['name' => 'bill#dueThisMonth', 'url' => '/api/bills/due-this-month', 'verb' => 'GET'],
        ['name' => 'bill#overdue', 'url' => '/api/bills/overdue', 'verb' => 'GET'],
        ['name' => 'bill#summary', 'url' => '/api/bills/summary', 'verb' => 'GET'],
        ['name' => 'bill#statusForMonth', 'url' => '/api/bills/status', 'verb' => 'GET'],
        ['name' => 'bill#detect', 'url' => '/api/bills/detect', 'verb' => 'GET'],
        ['name' => 'bill#createFromDetected', 'url' => '/api/bills/create-from-detected', 'verb' => 'POST'],
        ['name' => 'bill#show', 'url' => '/api/bills/{id}', 'verb' => 'GET'],
        ['name' => 'bill#update', 'url' => '/api/bills/{id}', 'verb' => 'PUT'],
        ['name' => 'bill#destroy', 'url' => '/api/bills/{id}', 'verb' => 'DELETE'],
        ['name' => 'bill#markPaid', 'url' => '/api/bills/{id}/paid', 'verb' => 'POST'],

        // Goals routes (Savings Goals)
        ['name' => 'goals#index', 'url' => '/api/goals', 'verb' => 'GET'],
        ['name' => 'goals#index', 'url' => '/api/savings-goals', 'verb' => 'GET'],
        ['name' => 'goals#show', 'url' => '/api/goals/{id}', 'verb' => 'GET'],
        ['name' => 'goals#show', 'url' => '/api/savings-goals/{id}', 'verb' => 'GET'],
        ['name' => 'goals#create', 'url' => '/api/goals', 'verb' => 'POST'],
        ['name' => 'goals#create', 'url' => '/api/savings-goals', 'verb' => 'POST'],
        ['name' => 'goals#update', 'url' => '/api/goals/{id}', 'verb' => 'PUT'],
        ['name' => 'goals#update', 'url' => '/api/savings-goals/{id}', 'verb' => 'PUT'],
        ['name' => 'goals#destroy', 'url' => '/api/goals/{id}', 'verb' => 'DELETE'],
        ['name' => 'goals#destroy', 'url' => '/api/savings-goals/{id}', 'verb' => 'DELETE'],
        ['name' => 'goals#progress', 'url' => '/api/goals/{id}/progress', 'verb' => 'GET'],
        ['name' => 'goals#forecast', 'url' => '/api/goals/{id}/forecast', 'verb' => 'GET'],

        // Net Worth routes
        ['name' => 'netWorth#current', 'url' => '/api/net-worth/current', 'verb' => 'GET'],
        ['name' => 'netWorth#snapshots', 'url' => '/api/net-worth/snapshots', 'verb' => 'GET'],
        ['name' => 'netWorth#createSnapshot', 'url' => '/api/net-worth/snapshots', 'verb' => 'POST'],
        ['name' => 'netWorth#destroySnapshot', 'url' => '/api/net-worth/snapshots/{id}', 'verb' => 'DELETE'],

        // Recurring Income routes - specific paths before {id} wildcard
        ['name' => 'recurringIncome#index', 'url' => '/api/recurring-income', 'verb' => 'GET'],
        ['name' => 'recurringIncome#create', 'url' => '/api/recurring-income', 'verb' => 'POST'],
        ['name' => 'recurringIncome#upcoming', 'url' => '/api/recurring-income/upcoming', 'verb' => 'GET'],
        ['name' => 'recurringIncome#expectedThisMonth', 'url' => '/api/recurring-income/this-month', 'verb' => 'GET'],
        ['name' => 'recurringIncome#summary', 'url' => '/api/recurring-income/summary', 'verb' => 'GET'],
        ['name' => 'recurringIncome#show', 'url' => '/api/recurring-income/{id}', 'verb' => 'GET'],
        ['name' => 'recurringIncome#update', 'url' => '/api/recurring-income/{id}', 'verb' => 'PUT'],
        ['name' => 'recurringIncome#destroy', 'url' => '/api/recurring-income/{id}', 'verb' => 'DELETE'],
        ['name' => 'recurringIncome#markReceived', 'url' => '/api/recurring-income/{id}/received', 'verb' => 'POST'],

        // Pension routes - specific paths before {id} wildcard
        ['name' => 'pension#index', 'url' => '/api/pensions', 'verb' => 'GET'],
        ['name' => 'pension#create', 'url' => '/api/pensions', 'verb' => 'POST'],
        ['name' => 'pension#summary', 'url' => '/api/pensions/summary', 'verb' => 'GET'],
        ['name' => 'pension#combinedProjection', 'url' => '/api/pensions/projection', 'verb' => 'GET'],
        ['name' => 'pension#show', 'url' => '/api/pensions/{id}', 'verb' => 'GET'],
        ['name' => 'pension#update', 'url' => '/api/pensions/{id}', 'verb' => 'PUT'],
        ['name' => 'pension#destroy', 'url' => '/api/pensions/{id}', 'verb' => 'DELETE'],
        ['name' => 'pension#snapshots', 'url' => '/api/pensions/{id}/snapshots', 'verb' => 'GET'],
        ['name' => 'pension#createSnapshot', 'url' => '/api/pensions/{id}/snapshots', 'verb' => 'POST'],
        ['name' => 'pension#contributions', 'url' => '/api/pensions/{id}/contributions', 'verb' => 'GET'],
        ['name' => 'pension#createContribution', 'url' => '/api/pensions/{id}/contributions', 'verb' => 'POST'],
        ['name' => 'pension#projection', 'url' => '/api/pensions/{id}/projection', 'verb' => 'GET'],
        ['name' => 'pension#destroySnapshot', 'url' => '/api/pensions/snapshots/{snapshotId}', 'verb' => 'DELETE'],
        ['name' => 'pension#destroyContribution', 'url' => '/api/pensions/contributions/{contributionId}', 'verb' => 'DELETE'],

        // Budget Alert routes
        ['name' => 'alert#index', 'url' => '/api/alerts', 'verb' => 'GET'],
        ['name' => 'alert#status', 'url' => '/api/alerts/status', 'verb' => 'GET'],
        ['name' => 'alert#summary', 'url' => '/api/alerts/summary', 'verb' => 'GET'],

        // Debt Payoff routes
        ['name' => 'debt#index', 'url' => '/api/debts', 'verb' => 'GET'],
        ['name' => 'debt#summary', 'url' => '/api/debts/summary', 'verb' => 'GET'],
        ['name' => 'debt#payoffPlan', 'url' => '/api/debts/payoff-plan', 'verb' => 'GET'],
        ['name' => 'debt#compare', 'url' => '/api/debts/compare', 'verb' => 'GET'],

        // Year-over-Year Comparison routes
        ['name' => 'yearOverYear#compareMonth', 'url' => '/api/yoy/month', 'verb' => 'GET'],
        ['name' => 'yearOverYear#compareYears', 'url' => '/api/yoy/years', 'verb' => 'GET'],
        ['name' => 'yearOverYear#compareCategories', 'url' => '/api/yoy/categories', 'verb' => 'GET'],
        ['name' => 'yearOverYear#monthlyTrends', 'url' => '/api/yoy/trends', 'verb' => 'GET'],

        // Shared Expense routes - contacts
        ['name' => 'sharedExpense#contacts', 'url' => '/api/shared/contacts', 'verb' => 'GET'],
        ['name' => 'sharedExpense#createContact', 'url' => '/api/shared/contacts', 'verb' => 'POST'],
        ['name' => 'sharedExpense#updateContact', 'url' => '/api/shared/contacts/{id}', 'verb' => 'PUT'],
        ['name' => 'sharedExpense#destroyContact', 'url' => '/api/shared/contacts/{id}', 'verb' => 'DELETE'],
        ['name' => 'sharedExpense#contactDetails', 'url' => '/api/shared/contacts/{id}/details', 'verb' => 'GET'],
        // Shared Expense routes - balances
        ['name' => 'sharedExpense#balances', 'url' => '/api/shared/balances', 'verb' => 'GET'],
        // Shared Expense routes - expense shares
        ['name' => 'sharedExpense#shareExpense', 'url' => '/api/shared/shares', 'verb' => 'POST'],
        ['name' => 'sharedExpense#splitFiftyFifty', 'url' => '/api/shared/shares/split', 'verb' => 'POST'],
        ['name' => 'sharedExpense#transactionShares', 'url' => '/api/shared/transactions/{transactionId}/shares', 'verb' => 'GET'],
        ['name' => 'sharedExpense#updateShare', 'url' => '/api/shared/shares/{id}', 'verb' => 'PUT'],
        ['name' => 'sharedExpense#markSettled', 'url' => '/api/shared/shares/{id}/settle', 'verb' => 'POST'],
        ['name' => 'sharedExpense#destroyShare', 'url' => '/api/shared/shares/{id}', 'verb' => 'DELETE'],
        // Shared Expense routes - settlements
        ['name' => 'sharedExpense#settlements', 'url' => '/api/shared/settlements', 'verb' => 'GET'],
        ['name' => 'sharedExpense#recordSettlement', 'url' => '/api/shared/settlements', 'verb' => 'POST'],
        ['name' => 'sharedExpense#settleWithContact', 'url' => '/api/shared/contacts/{contactId}/settle', 'verb' => 'POST'],
        ['name' => 'sharedExpense#destroySettlement', 'url' => '/api/shared/settlements/{id}', 'verb' => 'DELETE'],

        // Report routes
        ['name' => 'report#summary', 'url' => '/api/reports/summary', 'verb' => 'GET'],
        ['name' => 'report#summaryWithComparison', 'url' => '/api/reports/summary-comparison', 'verb' => 'GET'],
        ['name' => 'report#spending', 'url' => '/api/reports/spending', 'verb' => 'GET'],
        ['name' => 'report#income', 'url' => '/api/reports/income', 'verb' => 'GET'],
        ['name' => 'report#cashflow', 'url' => '/api/reports/cashflow', 'verb' => 'GET'],
        ['name' => 'report#budget', 'url' => '/api/reports/budget', 'verb' => 'GET'],
        ['name' => 'report#export', 'url' => '/api/reports/export', 'verb' => 'POST'],
        
        // Setup routes
        ['name' => 'setup#initialize', 'url' => '/api/setup/initialize', 'verb' => 'POST'],
        ['name' => 'setup#status', 'url' => '/api/setup/status', 'verb' => 'GET'],
        ['name' => 'setup#removeDuplicateCategories', 'url' => '/api/setup/remove-duplicate-categories', 'verb' => 'POST'],
        ['name' => 'setup#resetCategories', 'url' => '/api/setup/reset-categories', 'verb' => 'POST'],
        ['name' => 'setup#factoryReset', 'url' => '/api/setup/factory-reset', 'verb' => 'POST'],

        // Settings routes
        ['name' => 'setting#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'setting#update', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'setting#show', 'url' => '/api/settings/{key}', 'verb' => 'GET'],
        ['name' => 'setting#updateKey', 'url' => '/api/settings/{key}', 'verb' => 'PUT'],
        ['name' => 'setting#destroy', 'url' => '/api/settings/{key}', 'verb' => 'DELETE'],
        ['name' => 'setting#reset', 'url' => '/api/settings/reset', 'verb' => 'POST'],
        ['name' => 'setting#options', 'url' => '/api/settings/options', 'verb' => 'GET'],

        // Auth routes (password protection)
        ['name' => 'auth#status', 'url' => '/api/auth/status', 'verb' => 'GET'],
        ['name' => 'auth#setup', 'url' => '/api/auth/setup', 'verb' => 'POST'],
        ['name' => 'auth#verify', 'url' => '/api/auth/verify', 'verb' => 'POST'],
        ['name' => 'auth#lock', 'url' => '/api/auth/lock', 'verb' => 'POST'],
        ['name' => 'auth#extend', 'url' => '/api/auth/extend', 'verb' => 'POST'],
        ['name' => 'auth#disable', 'url' => '/api/auth/disable', 'verb' => 'DELETE'],
        ['name' => 'auth#changePassword', 'url' => '/api/auth/password', 'verb' => 'PUT'],

        // Migration routes (data export/import)
        ['name' => 'migration#export', 'url' => '/api/migration/export', 'verb' => 'GET'],
        ['name' => 'migration#preview', 'url' => '/api/migration/preview', 'verb' => 'POST'],
        ['name' => 'migration#import', 'url' => '/api/migration/import', 'verb' => 'POST'],
    ],
];