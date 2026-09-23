<?php

declare(strict_types=1);

namespace OCA\Budget\Dashboard;

use OCA\Budget\Db\Bill;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\BillService;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Nextcloud dashboard widget: the user's next due bills.
 * Server-rendered via IAPIWidgetV2 — no app JS bundle required.
 */
class UpcomingBillsWidget implements IAPIWidget, IAPIWidgetV2, IIconWidget, IButtonWidget {

	private const MAX_ITEMS = 5;

	public function __construct(
		private BillService $billService,
		private AmountFormatter $amountFormatter,
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getId(): string {
		return 'budget-upcoming-bills';
	}

	public function getTitle(): string {
		return $this->l->t('Budget: Upcoming bills');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-budget-widget';
	}

	public function getIconUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('budget', 'app-dark.svg')
		);
	}

	public function getUrl(): ?string {
		return $this->urlGenerator->linkToRouteAbsolute('budget.page.index') . '#/bills';
	}

	public function load(): void {
		Util::addStyle('budget', 'dashboard');
	}

	/**
	 * @return WidgetItem[]
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		return $this->buildItems($userId);
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = $this->buildItems($userId);
		return new WidgetItems(
			$items,
			empty($items) ? $this->l->t('No upcoming bills') : ''
		);
	}

	/**
	 * @return WidgetButton[]
	 */
	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(WidgetButton::TYPE_MORE, $this->getUrl(), $this->l->t('All bills')),
		];
	}

	/**
	 * @return WidgetItem[]
	 */
	private function buildItems(string $userId): array {
		$bills = array_slice($this->billService->findUpcoming($userId, 60), 0, self::MAX_ITEMS);
		$bills = $this->billService->enrichBillsWithCurrency($bills, $userId);

		return array_map(function (Bill $bill) use ($userId) {
			return new WidgetItem(
				$bill->getName(),
				$this->amountFormatter->formatForUser($userId, (float)$bill->getAmount(), $bill->getCurrency())
					. ' · ' . $this->formatDueDate($bill->getNextDueDate()),
				$this->getUrl(),
				$this->getIconUrl(),
				(string)$bill->getId()
			);
		}, $bills);
	}

	private function formatDueDate(?string $dueDate): string {
		if ($dueDate === null) {
			return '';
		}
		$today = date('Y-m-d');
		if ($dueDate < $today) {
			return $this->l->t('overdue');
		}
		if ($dueDate === $today) {
			return $this->l->t('due today');
		}
		$days = (int)((strtotime($dueDate) - strtotime($today)) / 86400);
		return $this->l->n('due in %n day', 'due in %n days', $days);
	}
}
