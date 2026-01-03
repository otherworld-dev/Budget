<?php
// Simple syntax test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing Application class loading...\n";

// Add Nextcloud's autoloader path (adjust if needed)
$nextcloudPath = '/var/www/html'; // This might need adjustment
if (file_exists($nextcloudPath . '/lib/autoload.php')) {
    require_once $nextcloudPath . '/lib/autoload.php';
}

// Try to include our app's autoloader
$vendorPath = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendorPath)) {
    require_once $vendorPath;
}

try {
    // Test basic class loading
    echo "Testing Application class...\n";
    $reflection = new ReflectionClass('OCA\Budget\AppInfo\Application');
    echo "✓ Application class loaded successfully\n";

    echo "Testing AccountMapper class...\n";
    $reflection = new ReflectionClass('OCA\Budget\Db\AccountMapper');
    echo "✓ AccountMapper class loaded successfully\n";

    echo "Testing AccountService class...\n";
    $reflection = new ReflectionClass('OCA\Budget\Service\AccountService');
    echo "✓ AccountService class loaded successfully\n";

    echo "All syntax checks passed!\n";

} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}