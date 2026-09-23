<?php

declare(strict_types=1);

namespace OCA\Budget\Search;

use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\GranularShareService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Nextcloud unified-search provider for budget transactions.
 * Searches description, vendor and notes across the user's own and shared
 * accounts, deep-linking into the app's transaction view with the term
 * pre-filled.
 */
class TransactionSearchProvider implements IProvider {

	public function __construct(
		private TransactionMapper $transactionMapper,
		private GranularShareService $granularShareService,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
	}

	public function getId(): string {
		return 'budget-transactions';
	}

	public function getName(): string {
		return $this->l->t('Budget transactions');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		// Boost inside the app, modest priority elsewhere
		return str_starts_with($route, 'budget.') ? -1 : 25;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = trim($query->getTerm());
		if (mb_strlen($term) < 2) {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = $query->getLimit();
		$offset = (int)($query->getCursor() ?? 0);
		$visibleAccountIds = $this->granularShareService->getVisibleAccountIds($user->getUID());

		$transactions = $this->transactionMapper->search(
			$user->getUID(),
			$term,
			$limit,
			$offset,
			$visibleAccountIds
		);

		$appUrl = $this->urlGenerator->linkToRouteAbsolute('budget.page.index');
		$deepLink = $appUrl . '#/transactions?search=' . rawurlencode($term);
		$iconUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('budget', 'app-dark.svg')
		);

		$entries = array_map(function (Transaction $t) use ($deepLink, $iconUrl) {
			$sign = $t->getType() === 'debit' ? '-' : '+';
			$subtitle = $sign . number_format((float)$t->getAmount(), 2) . ' · ' . $t->getDate();
			return new SearchResultEntry(
				$iconUrl,
				$t->getDescription() !== '' ? $t->getDescription() : ($t->getVendor() ?? ''),
				$subtitle,
				$deepLink,
				'',
				false
			);
		}, $transactions);

		return count($entries) < $limit
			? SearchResult::complete($this->getName(), $entries)
			: SearchResult::paginated($this->getName(), $entries, $offset + $limit);
	}
}
