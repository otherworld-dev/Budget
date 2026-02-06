<?php
require '/var/www/html/lib/base.php';

$db = \OC::$server->getDatabaseConnection();
$result = $db->executeQuery('SELECT id, date, amount, type, description, account_id FROM oc_budget_transactions WHERE amount > 1000000 ORDER BY date DESC LIMIT 5');

echo "Large transactions (> 1,000,000):\n";
echo str_repeat("-", 80) . "\n";
printf("%-6s %-12s %-15s %-10s %-10s %s\n", "ID", "Date", "Amount", "Type", "Account", "Description");
echo str_repeat("-", 80) . "\n";

while ($row = $result->fetch()) {
    printf("%-6s %-12s %-15s %-10s %-10s %s\n",
        $row['id'],
        $row['date'],
        number_format($row['amount'], 2),
        $row['type'],
        $row['account_id'],
        substr($row['description'], 0, 30)
    );
}

// Also check the account summaries
echo "\n\nAccount 4 (Natwest Digital Regular Saver) recent transactions:\n";
echo str_repeat("-", 80) . "\n";
$result2 = $db->executeQuery('SELECT id, date, amount, type, description FROM oc_budget_transactions WHERE account_id = 4 ORDER BY date DESC LIMIT 10');
printf("%-6s %-12s %-15s %-10s %s\n", "ID", "Date", "Amount", "Type", "Description");
echo str_repeat("-", 80) . "\n";
while ($row = $result2->fetch()) {
    printf("%-6s %-12s %-15s %-10s %s\n",
        $row['id'],
        $row['date'],
        number_format($row['amount'], 2),
        $row['type'],
        substr($row['description'], 0, 40)
    );
}
