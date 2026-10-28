<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

/**
 * Marks a provider whose reported account balance is a booked or expected
 * ledger balance, comparable to Budget's own balance as of today.
 *
 * Only such a balance may set a new account's opening balance on its first
 * sync (BankSyncService). Other providers can report an available balance
 * that includes a credit line, or none at all; for them the difference is
 * only shown, with the manual Reconcile action.
 */
interface ReliableBalanceProviderInterface {
}
