<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCP\Notification\IManager as INotificationManager;

/**
 * Tells a user their promotional interest rate is about to end, once per
 * end date, so they can clear or move the balance first.
 */
class PromoRateWarningService {
	public function __construct(
		private AccountMapper $accountMapper,
		private INotificationManager $notificationManager,
	) {
	}

	/**
	 * @param string $today the user's own date, Y-m-d
	 * @return int the number of warnings sent
	 */
	public function sendDueWarnings(string $userId, string $today): int {
		$sent = 0;
		foreach ($this->accountMapper->findAll($userId) as $account) {
			$end = $account->getPromoEnd();
			if ($end === null
				|| $account->getClosed()
				|| $account->getPromoWarned() === $end
				|| !PromoRate::endsWithin($account, $today, PromoRate::WARNING_DAYS)
			) {
				continue;
			}

			$this->notify($userId, $account, $end);
			$this->accountMapper->markPromoWarned($account->getId(), $end);
			$sent++;
		}
		return $sent;
	}

	private function notify(string $userId, Account $account, string $end): void {
		$notification = $this->notificationManager->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('account', (string)$account->getId())
			->setSubject('promo_rate_ending', [
				'accountName' => $account->getName(),
				'promoRate' => self::formatRate($account->getPromoRate()),
				'promoEnd' => $end,
				'normalRate' => self::formatRate($account->getInterestRate() ?? 0.0),
			]);
		$this->notificationManager->notify($notification);
	}

	/** At most 2 dp, trailing zeros dropped: 0, 22.9, 19.99. */
	private static function formatRate(?float $rate): string {
		$text = rtrim(rtrim(number_format((float)$rate, 2, '.', ''), '0'), '.');
		return $text === '' || $text === '-0' ? '0' : $text;
	}
}
