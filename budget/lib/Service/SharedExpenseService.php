<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use DateTime;
use OCA\Budget\Db\Contact;
use OCA\Budget\Db\ContactMapper;
use OCA\Budget\Db\ExpenseShare;
use OCA\Budget\Db\ExpenseShareMapper;
use OCA\Budget\Db\Settlement;
use OCA\Budget\Db\SettlementMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class SharedExpenseService {
    private ContactMapper $contactMapper;
    private ExpenseShareMapper $expenseShareMapper;
    private SettlementMapper $settlementMapper;
    private TransactionMapper $transactionMapper;

    public function __construct(
        ContactMapper $contactMapper,
        ExpenseShareMapper $expenseShareMapper,
        SettlementMapper $settlementMapper,
        TransactionMapper $transactionMapper
    ) {
        $this->contactMapper = $contactMapper;
        $this->expenseShareMapper = $expenseShareMapper;
        $this->settlementMapper = $settlementMapper;
        $this->transactionMapper = $transactionMapper;
    }

    // ==================== Contact Methods ====================

    /**
     * Get all contacts for a user.
     *
     * @return Contact[]
     */
    public function getContacts(string $userId): array {
        return $this->contactMapper->findAll($userId);
    }

    /**
     * Get a contact by ID.
     *
     * @throws DoesNotExistException
     */
    public function getContact(int $id, string $userId): Contact {
        return $this->contactMapper->find($id, $userId);
    }

    /**
     * Create a new contact.
     */
    public function createContact(string $userId, string $name, ?string $email = null): Contact {
        $contact = new Contact();
        $contact->setUserId($userId);
        $contact->setName($name);
        $contact->setEmail($email);
        $contact->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

        return $this->contactMapper->insert($contact);
    }

    /**
     * Update a contact.
     *
     * @throws DoesNotExistException
     */
    public function updateContact(int $id, string $userId, string $name, ?string $email = null): Contact {
        $contact = $this->contactMapper->find($id, $userId);
        $contact->setName($name);
        $contact->setEmail($email);

        return $this->contactMapper->update($contact);
    }

    /**
     * Delete a contact.
     *
     * @throws DoesNotExistException
     */
    public function deleteContact(int $id, string $userId): Contact {
        $contact = $this->contactMapper->find($id, $userId);
        return $this->contactMapper->delete($contact);
    }

    // ==================== Expense Share Methods ====================

    /**
     * Share an expense with a contact.
     *
     * @param int $transactionId The transaction to share
     * @param int $contactId The contact who owes/is owed
     * @param float $amount Positive = they owe you, negative = you owe them
     * @param string|null $notes Optional notes
     */
    public function shareExpense(
        string $userId,
        int $transactionId,
        int $contactId,
        float $amount,
        ?string $notes = null
    ): ExpenseShare {
        // Verify the transaction exists and belongs to user
        $this->transactionMapper->find($transactionId, $userId);
        // Verify the contact exists and belongs to user
        $this->contactMapper->find($contactId, $userId);

        $share = new ExpenseShare();
        $share->setUserId($userId);
        $share->setTransactionId($transactionId);
        $share->setContactId($contactId);
        $share->setAmount($amount);
        $share->setIsSettled(false);
        $share->setNotes($notes);
        $share->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

        return $this->expenseShareMapper->insert($share);
    }

    /**
     * Split a transaction 50/50 with a contact.
     */
    public function splitFiftyFifty(
        string $userId,
        int $transactionId,
        int $contactId,
        ?string $notes = null
    ): ExpenseShare {
        $transaction = $this->transactionMapper->find($transactionId, $userId);
        $amount = abs((float) $transaction->getAmount()) / 2;

        // If it's an expense (negative), they owe you half
        // If it's income (positive), you owe them half
        if ($transaction->getAmount() < 0) {
            return $this->shareExpense($userId, $transactionId, $contactId, $amount, $notes);
        } else {
            return $this->shareExpense($userId, $transactionId, $contactId, -$amount, $notes);
        }
    }

    /**
     * Get all expense shares for a user.
     *
     * @return ExpenseShare[]
     */
    public function getExpenseShares(string $userId): array {
        return $this->expenseShareMapper->findAll($userId);
    }

    /**
     * Get shares for a specific transaction.
     *
     * @return ExpenseShare[]
     */
    public function getSharesByTransaction(int $transactionId, string $userId): array {
        return $this->expenseShareMapper->findByTransaction($transactionId, $userId);
    }

    /**
     * Get all unsettled shares.
     *
     * @return ExpenseShare[]
     */
    public function getUnsettledShares(string $userId): array {
        return $this->expenseShareMapper->findUnsettled($userId);
    }

    /**
     * Update an expense share.
     *
     * @throws DoesNotExistException
     */
    public function updateExpenseShare(
        int $id,
        string $userId,
        float $amount,
        ?string $notes = null
    ): ExpenseShare {
        $share = $this->expenseShareMapper->find($id, $userId);
        $share->setAmount($amount);
        $share->setNotes($notes);

        return $this->expenseShareMapper->update($share);
    }

    /**
     * Mark a share as settled.
     *
     * @throws DoesNotExistException
     */
    public function markShareSettled(int $id, string $userId): ExpenseShare {
        $share = $this->expenseShareMapper->find($id, $userId);
        $share->setIsSettled(true);

        return $this->expenseShareMapper->update($share);
    }

    /**
     * Delete an expense share.
     *
     * @throws DoesNotExistException
     */
    public function deleteExpenseShare(int $id, string $userId): ExpenseShare {
        $share = $this->expenseShareMapper->find($id, $userId);
        return $this->expenseShareMapper->delete($share);
    }

    /**
     * Remove all shares for a transaction.
     */
    public function removeTransactionShares(int $transactionId, string $userId): void {
        $this->expenseShareMapper->deleteByTransaction($transactionId, $userId);
    }

    // ==================== Settlement Methods ====================

    /**
     * Record a settlement payment.
     *
     * @param float $amount Positive = they paid you, negative = you paid them
     */
    public function recordSettlement(
        string $userId,
        int $contactId,
        float $amount,
        string $date,
        ?string $notes = null
    ): Settlement {
        // Verify contact exists
        $this->contactMapper->find($contactId, $userId);

        $settlement = new Settlement();
        $settlement->setUserId($userId);
        $settlement->setContactId($contactId);
        $settlement->setAmount($amount);
        $settlement->setDate($date);
        $settlement->setNotes($notes);
        $settlement->setCreatedAt((new DateTime())->format('Y-m-d H:i:s'));

        return $this->settlementMapper->insert($settlement);
    }

    /**
     * Settle all unsettled shares with a contact.
     */
    public function settleWithContact(
        string $userId,
        int $contactId,
        string $date,
        ?string $notes = null
    ): Settlement {
        $shares = $this->expenseShareMapper->findUnsettledByContact($contactId, $userId);

        $totalAmount = 0.0;
        foreach ($shares as $share) {
            $totalAmount += $share->getAmount();
            $share->setIsSettled(true);
            $this->expenseShareMapper->update($share);
        }

        // Record the settlement (they paid you the total they owed)
        return $this->recordSettlement($userId, $contactId, $totalAmount, $date, $notes);
    }

    /**
     * Get all settlements for a user.
     *
     * @return Settlement[]
     */
    public function getSettlements(string $userId): array {
        return $this->settlementMapper->findAll($userId);
    }

    /**
     * Get settlements for a specific contact.
     *
     * @return Settlement[]
     */
    public function getSettlementsByContact(int $contactId, string $userId): array {
        return $this->settlementMapper->findByContact($contactId, $userId);
    }

    /**
     * Delete a settlement.
     *
     * @throws DoesNotExistException
     */
    public function deleteSettlement(int $id, string $userId): Settlement {
        $settlement = $this->settlementMapper->find($id, $userId);
        return $this->settlementMapper->delete($settlement);
    }

    // ==================== Balance Methods ====================

    /**
     * Get balance summary for all contacts.
     *
     * @return array{contacts: array, totalOwed: float, totalOwing: float, netBalance: float}
     */
    public function getBalanceSummary(string $userId): array {
        $contacts = $this->contactMapper->findAll($userId);
        $balances = $this->expenseShareMapper->getBalancesByContact($userId);

        $contactBalances = [];
        $totalOwed = 0.0; // Total others owe you
        $totalOwing = 0.0; // Total you owe others

        foreach ($contacts as $contact) {
            $balance = $balances[$contact->getId()] ?? 0.0;
            $contactBalances[] = [
                'contact' => $contact->jsonSerialize(),
                'balance' => $balance,
                'direction' => $balance > 0 ? 'owed' : ($balance < 0 ? 'owing' : 'settled'),
            ];

            if ($balance > 0) {
                $totalOwed += $balance;
            } else {
                $totalOwing += abs($balance);
            }
        }

        return [
            'contacts' => $contactBalances,
            'totalOwed' => $totalOwed,
            'totalOwing' => $totalOwing,
            'netBalance' => $totalOwed - $totalOwing,
        ];
    }

    /**
     * Get detailed balance for a specific contact including transaction history.
     */
    public function getContactDetails(int $contactId, string $userId): array {
        $contact = $this->contactMapper->find($contactId, $userId);
        $shares = $this->expenseShareMapper->findByContact($contactId, $userId);
        $settlements = $this->settlementMapper->findByContact($contactId, $userId);

        // Enrich shares with transaction data
        $enrichedShares = [];
        foreach ($shares as $share) {
            try {
                $transaction = $this->transactionMapper->find($share->getTransactionId(), $userId);
                $enrichedShares[] = [
                    'share' => $share->jsonSerialize(),
                    'transaction' => [
                        'id' => $transaction->getId(),
                        'date' => $transaction->getDate(),
                        'description' => $transaction->getDescription(),
                        'amount' => $transaction->getAmount(),
                    ],
                ];
            } catch (DoesNotExistException $e) {
                // Transaction was deleted, skip this share
                continue;
            }
        }

        $unsettledBalance = 0.0;
        foreach ($shares as $share) {
            if (!$share->getIsSettled()) {
                $unsettledBalance += $share->getAmount();
            }
        }

        return [
            'contact' => $contact->jsonSerialize(),
            'shares' => $enrichedShares,
            'settlements' => array_map(fn($s) => $s->jsonSerialize(), $settlements),
            'balance' => $unsettledBalance,
            'direction' => $unsettledBalance > 0 ? 'owed' : ($unsettledBalance < 0 ? 'owing' : 'settled'),
        ];
    }
}
