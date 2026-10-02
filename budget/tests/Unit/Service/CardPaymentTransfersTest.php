<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\InterestRateMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A shared card's "Payment due" tile read the viewer's own transfers only,
 * so whichever of the two people hadn't set up the card's payment was
 * offered "Set up payment" and could create a second one into the same card.
 */
class CardPaymentTransfersTest extends TestCase {
	private function transfer(int $id, string $owner, string $amountType = 'statement'): Bill {
		$bill = new Bill();
		$bill->setId($id);
		$bill->setUserId($owner);
		$bill->setName('Card payment');
		$bill->setAmount(0.0);
		$bill->setAmountType($amountType);
		$bill->setIsTransfer(true);
		$bill->setIsActive(true);
		$bill->setAccountId(3);
		$bill->setDestinationAccountId(7);
		$bill->setNextDueDate('2026-10-25');
		return $bill;
	}

	public function testEveryActivePaymentIntoTheCardIsSeenByWhoeverOpensIt(): void {
		$card = new Account();
		$card->setId(7);
		$card->setUserId('alice');
		$card->setType('credit_card');
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('find')->willReturn($card);
		$accounts->method('findById')->willReturn($card);

		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getVisibleAccountIds')->willReturnMap([['alice', [7, 3]], ['bob', [7, 3]], ['carol', [4]]]);
		$shares->method('getSharedAccountIds')->willReturn([]);
		$shares->method('canAccess')->willReturn(true);

		$bills = $this->createMock(BillMapper::class);
		$bills->expects($this->once())->method('findActiveTransfersInto')->with(7)->willReturn([
			$this->transfer(1, 'bob'),
			// someone the card is no longer shared with
			$this->transfer(2, 'carol'),
		]);

		$service = new AccountService(
			$accounts,
			$this->createMock(TransactionMapper::class),
			$this->createMock(InterestRateMapper::class),
			$this->createMock(CurrencyConversionService::class),
			$shares,
			$this->createMock(TransactionService::class),
			$this->createMock(IL10N::class),
			null,
			null,
			null,
			$bills,
		);

		$payments = $service->getPaymentTransfers(7, 'alice');

		$this->assertCount(1, $payments);
		$this->assertSame(['nextDueDate' => '2026-10-25', 'amount' => 0.0, 'amountType' => 'statement', 'mine' => false], $payments[0]);
	}
}
