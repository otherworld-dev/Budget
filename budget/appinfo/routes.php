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
        
        // Category routes - specific paths before {id} wildcard
        ['name' => 'category#index', 'url' => '/api/categories', 'verb' => 'GET'],
        ['name' => 'category#tree', 'url' => '/api/categories/tree', 'verb' => 'GET'],
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
        
        // Import rules routes
        ['name' => 'importRule#index', 'url' => '/api/import-rules', 'verb' => 'GET'],
        ['name' => 'importRule#show', 'url' => '/api/import-rules/{id}', 'verb' => 'GET'],
        ['name' => 'importRule#create', 'url' => '/api/import-rules', 'verb' => 'POST'],
        ['name' => 'importRule#update', 'url' => '/api/import-rules/{id}', 'verb' => 'PUT'],
        ['name' => 'importRule#destroy', 'url' => '/api/import-rules/{id}', 'verb' => 'DELETE'],
        ['name' => 'importRule#test', 'url' => '/api/import-rules/test', 'verb' => 'POST'],
        
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

        // Settings routes
        ['name' => 'setting#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'setting#update', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'setting#show', 'url' => '/api/settings/{key}', 'verb' => 'GET'],
        ['name' => 'setting#updateKey', 'url' => '/api/settings/{key}', 'verb' => 'PUT'],
        ['name' => 'setting#destroy', 'url' => '/api/settings/{key}', 'verb' => 'DELETE'],
        ['name' => 'setting#reset', 'url' => '/api/settings/reset', 'verb' => 'POST'],
        ['name' => 'setting#options', 'url' => '/api/settings/options', 'verb' => 'GET'],

        // Migration routes (data export/import)
        ['name' => 'migration#export', 'url' => '/api/migration/export', 'verb' => 'GET'],
        ['name' => 'migration#preview', 'url' => '/api/migration/preview', 'verb' => 'POST'],
        ['name' => 'migration#import', 'url' => '/api/migration/import', 'verb' => 'POST'],
    ],
];