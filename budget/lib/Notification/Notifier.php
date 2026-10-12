<?php

declare(strict_types=1);

namespace OCA\Budget\Notification;

use OCA\Budget\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	private IFactory $l10nFactory;
	private IURLGenerator $urlGenerator;

	public function __construct(
		IFactory $l10nFactory,
		IURLGenerator $urlGenerator,
	) {
		$this->l10nFactory = $l10nFactory;
		$this->urlGenerator = $urlGenerator;
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Budget');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException('Unknown app');
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$parameters = $notification->getSubjectParameters();

		switch ($notification->getSubject()) {
			case 'bill_reminder':
				// TRANSLATORS: {bill} is replaced with the bill name. Do NOT translate {bill} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('Bill reminder: {bill}'),
					[
						'bill' => [
							'type' => 'highlight',
							'id' => (string)$parameters['billId'],
							'name' => $parameters['billName'],
						],
					]
				);

				$daysText = (int)$parameters['daysUntilDue'] === 0
					? $l->t('due today')
					: (
						(int)$parameters['daysUntilDue'] === 1
							? $l->t('due tomorrow')
							: $l->t('due in %s days', [(string)$parameters['daysUntilDue']])
					);

				// TRANSLATORS: {bill}, {amount}, {dueText} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('{bill} ({amount}) is {dueText}'),
					[
						'bill' => [
							'type' => 'highlight',
							'id' => (string)$parameters['billId'],
							'name' => $parameters['billName'],
						],
						'amount' => [
							'type' => 'highlight',
							'id' => 'amount',
							'name' => $parameters['amount'],
						],
						'dueText' => [
							'type' => 'highlight',
							'id' => 'due',
							'name' => $daysText,
						],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#bills');

				break;

			case 'bill_overdue':
				// TRANSLATORS: {bill} is replaced with the bill name. Do NOT translate {bill} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('Overdue bill: {bill}'),
					[
						'bill' => [
							'type' => 'highlight',
							'id' => (string)$parameters['billId'],
							'name' => $parameters['billName'],
						],
					]
				);

				$daysOverdue = abs((int)$parameters['daysOverdue']);
				$overdueText = $daysOverdue === 1
					? $l->t('1 day overdue')
					: $l->t('%s days overdue', [(string)$daysOverdue]);

				// TRANSLATORS: {bill}, {amount}, {overdueText} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('{bill} ({amount}) is {overdueText}'),
					[
						'bill' => [
							'type' => 'highlight',
							'id' => (string)$parameters['billId'],
							'name' => $parameters['billName'],
						],
						'amount' => [
							'type' => 'highlight',
							'id' => 'amount',
							'name' => $parameters['amount'],
						],
						'overdueText' => [
							'type' => 'highlight',
							'id' => 'overdue',
							'name' => $overdueText,
						],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#bills');

				break;

			case 'bill_auto_paid':
				// TRANSLATORS: {bill} is replaced with the bill name. Do NOT translate {bill} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('Bill auto-paid: {bill}'),
					[
						'bill' => [
							'type' => 'highlight',
							'id' => (string)$parameters['billId'],
							'name' => $parameters['billName'],
						],
					]
				);

				// TRANSLATORS: {bill}, {amount}, {nextDueDate} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('{bill} ({amount}) was automatically paid. Next due: {nextDueDate}'),
					[
						'bill' => [
							'type' => 'highlight',
							'id' => (string)$parameters['billId'],
							'name' => $parameters['billName'],
						],
						'amount' => [
							'type' => 'highlight',
							'id' => 'amount',
							'name' => $parameters['amount'],
						],
						'nextDueDate' => [
							'type' => 'highlight',
							'id' => 'nextDueDate',
							'name' => (string)$parameters['nextDueDate'],
						],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#bills');

				break;

			case 'bill_auto_pay_failed':
				// TRANSLATORS: {bill} is replaced with the bill name. Do NOT translate {bill} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('Auto-pay failed: {bill}'),
					[
						'bill' => [
							'type' => 'highlight',
							'id' => (string)$parameters['billId'],
							'name' => $parameters['billName'],
						],
					]
				);

				// TRANSLATORS: {bill}, {amount}, {reason} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('Failed to auto-pay {bill} ({amount}). Auto-pay has been disabled. Reason: {reason}'),
					[
						'bill' => [
							'type' => 'highlight',
							'id' => (string)$parameters['billId'],
							'name' => $parameters['billName'],
						],
						'amount' => [
							'type' => 'highlight',
							'id' => 'amount',
							'name' => $parameters['amount'],
						],
						'reason' => [
							'type' => 'highlight',
							'id' => 'reason',
							'name' => $parameters['reason'],
						],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#bills');

				break;

			case 'income_auto_created':
				// TRANSLATORS: {income} is replaced with the income name. Do NOT translate {income} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('Income auto-created: {income}'),
					[
						'income' => [
							'type' => 'highlight',
							'id' => (string)$parameters['incomeId'],
							'name' => $parameters['incomeName'],
						],
					]
				);

				// TRANSLATORS: {income}, {amount}, {nextExpectedDate} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('{income} ({amount}) transaction was automatically created. Next expected: {nextExpectedDate}'),
					[
						'income' => [
							'type' => 'highlight',
							'id' => (string)$parameters['incomeId'],
							'name' => $parameters['incomeName'],
						],
						'amount' => [
							'type' => 'highlight',
							'id' => 'amount',
							'name' => $parameters['amount'],
						],
						'nextExpectedDate' => [
							'type' => 'highlight',
							'id' => 'nextExpectedDate',
							'name' => (string)$parameters['nextExpectedDate'],
						],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#income');

				break;

			case 'income_auto_create_failed':
				// TRANSLATORS: {income} is replaced with the income name. Do NOT translate {income} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('Auto-create failed: {income}'),
					[
						'income' => [
							'type' => 'highlight',
							'id' => (string)$parameters['incomeId'],
							'name' => $parameters['incomeName'],
						],
					]
				);

				// TRANSLATORS: {income}, {amount}, {reason} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('Failed to auto-create transaction for {income} ({amount}). Reason: {reason}'),
					[
						'income' => [
							'type' => 'highlight',
							'id' => (string)$parameters['incomeId'],
							'name' => $parameters['incomeName'],
						],
						'amount' => [
							'type' => 'highlight',
							'id' => 'amount',
							'name' => $parameters['amount'],
						],
						'reason' => [
							'type' => 'highlight',
							'id' => 'reason',
							'name' => $parameters['reason'],
						],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#income');

				break;

			case 'pension_auto_post_failed':
				// TRANSLATORS: {pension} is replaced with the pension name. Do NOT translate {pension} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('Scheduled contribution stopped: {pension}'),
					[
						'pension' => [
							'type' => 'highlight',
							'id' => (string)$parameters['pensionId'],
							'name' => (string)$parameters['pensionName'],
						],
					]
				);

				// TRANSLATORS: {pension}, {amount}, {reason} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('The {amount} contribution to {pension} could not be posted, so auto-post has been turned off. Reason: {reason}'),
					[
						'pension' => [
							'type' => 'highlight',
							'id' => (string)$parameters['pensionId'],
							'name' => (string)$parameters['pensionName'],
						],
						'amount' => [
							'type' => 'highlight',
							'id' => 'amount',
							'name' => (string)$parameters['amount'],
						],
						'reason' => [
							'type' => 'highlight',
							'id' => 'reason',
							'name' => (string)$parameters['reason'],
						],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#pensions');

				break;

			case 'share_invitation':
				// TRANSLATORS: {user} is replaced with the username. Do NOT translate {user} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('{user} shared their budget with you'),
					[
						'user' => [
							'type' => 'user',
							'id' => $parameters['ownerUserId'],
							'name' => $parameters['ownerDisplayName'],
						],
					]
				);

				// TRANSLATORS: {user} is replaced with the username. Do NOT translate {user} — keep it exactly as-is.
				$notification->setRichMessage(
					$l->t('{user} has invited you to view their budget. Open Budget to accept or decline.'),
					[
						'user' => [
							'type' => 'user',
							'id' => $parameters['ownerUserId'],
							'name' => $parameters['ownerDisplayName'],
						],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#sharing');

				break;

			case 'digest':
				$notification->setRichSubject(
					$parameters['frequency'] === 'monthly'
						? $l->t('Your monthly budget digest')
						: $l->t('Your weekly budget digest')
				);

				$messageParameters = [
					'income' => ['type' => 'highlight', 'id' => 'income', 'name' => $parameters['income']],
					'expenses' => ['type' => 'highlight', 'id' => 'expenses', 'name' => $parameters['expenses']],
					'net' => ['type' => 'highlight', 'id' => 'net', 'name' => $parameters['net']],
					'bills' => ['type' => 'highlight', 'id' => 'bills', 'name' => $parameters['billCount']],
				];

				// Absent on notifications queued before the counts were rendered
				$anomalyCount = (int)($parameters['anomalyCount'] ?? 0);
				$overdueCount = (int)($parameters['overdueCount'] ?? 0);

				// TRANSLATORS: {income}, {expenses}, {net} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$sentences = [$l->t('Income {income}, spending {expenses} ({net} net).')];
				// TRANSLATORS: {bills} is a placeholder (the number of bills) — do NOT translate it.
				$sentences[] = $l->n('{bills} bill due soon.', '{bills} bills due soon.', (int)$parameters['billCount']);
				if ($overdueCount > 0) {
					$messageParameters['overdue'] = [
						'type' => 'highlight', 'id' => 'overdue', 'name' => $parameters['overdueCount'],
					];
					// TRANSLATORS: {overdue} is a placeholder (the number of bills) — do NOT translate it.
					$sentences[] = $l->n('{overdue} bill is overdue.', '{overdue} bills are overdue.', $overdueCount);
				}
				if ($anomalyCount > 0) {
					$messageParameters['anomalies'] = [
						'type' => 'highlight', 'id' => 'anomalies', 'name' => $parameters['anomalyCount'],
					];
					// TRANSLATORS: {anomalies} is a placeholder (the number of categories) — do NOT translate it.
					$sentences[] = $l->n('One category is spending unusually.', '{anomalies} categories are spending unusually.', $anomalyCount);
				}
				$message = implode(' ', $sentences);

				$notification->setRichMessage($message, $messageParameters);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#dashboard');

				break;

			case 'spending_anomaly':
				// TRANSLATORS: {category} is replaced with the category name. Do NOT translate {category} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('Unusual spending in {category}'),
					[
						'category' => ['type' => 'highlight', 'id' => 'category', 'name' => $parameters['categoryName']],
					]
				);

				// TRANSLATORS: {category}, {percent}, {amount} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('{category} spending is {percent}%% above your typical level — {amount} so far this month.'),
					[
						'category' => ['type' => 'highlight', 'id' => 'category', 'name' => $parameters['categoryName']],
						'percent' => ['type' => 'highlight', 'id' => 'percent', 'name' => $parameters['percentAbove']],
						'amount' => ['type' => 'highlight', 'id' => 'amount', 'name' => $parameters['amount']],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#budget');

				break;

			case 'report_ready':
				// TRANSLATORS: {month} is replaced with the report month. Do NOT translate {month} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('Your Budget report for {month} is ready'),
					[
						'month' => ['type' => 'highlight', 'id' => 'month', 'name' => $parameters['month']],
					]
				);

				// TRANSLATORS: {file} is replaced with the file name. Do NOT translate {file} — keep it exactly as-is.
				$notification->setRichMessage(
					$l->t('{file} was saved to your Files.'),
					[
						'file' => [
							'type' => 'file',
							'id' => $parameters['fileId'],
							'name' => $parameters['fileName'],
							'path' => $parameters['fileName'],
							'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $parameters['fileId']]),
						],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $parameters['fileId']]));

				break;

			case 'receipt_added_by_other':
				// A share recipient attached a receipt to one of the owner's
				// transactions; the file was stored in the OWNER's Files.
				// TRANSLATORS: {user} is replaced with the person's name. Do NOT translate {user} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('{user} added a receipt to your budget'),
					[
						'user' => [
							'type' => 'user',
							'id' => $parameters['actorUserId'],
							'name' => $parameters['actorDisplayName'],
						],
					]
				);

				// TRANSLATORS: {file} and {transaction} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('{file} was saved to your Files for {transaction}.'),
					[
						'file' => [
							'type' => 'file',
							'id' => (string)$parameters['fileId'],
							'name' => $parameters['fileName'],
							'path' => $parameters['fileName'],
							'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $parameters['fileId']]),
						],
						'transaction' => [
							'type' => 'highlight',
							'id' => (string)$parameters['transactionId'],
							'name' => $parameters['description'] !== '' ? $parameters['description'] : '#' . $parameters['transactionId'],
						],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $parameters['fileId']]));

				break;

			case 'budget_alert':
				// TRANSLATORS: {category} is replaced with the category name. Do NOT translate {category} — keep it exactly as-is.
				$alertSubject = $l->t('Approaching your {category} budget');
				if (($parameters['severity'] ?? '') === 'danger') {
					// TRANSLATORS: {category} is replaced with the category name. Do NOT translate {category} — keep it exactly as-is.
					$alertSubject = $l->t('{category} is over budget');
				}

				$notification->setRichSubject(
					$alertSubject,
					[
						'category' => ['type' => 'highlight', 'id' => 'category', 'name' => $parameters['categoryName']],
					]
				);

				// TRANSLATORS: {spent}, {budget}, {percent} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('You have spent {spent} of your {budget} budget ({percent}%% of the limit).'),
					[
						'spent' => ['type' => 'highlight', 'id' => 'spent', 'name' => $parameters['spent']],
						'budget' => ['type' => 'highlight', 'id' => 'budget', 'name' => $parameters['budget']],
						'percent' => ['type' => 'highlight', 'id' => 'percent', 'name' => $parameters['percentage']],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#budget');

				break;

			case 'forecast_warning':
				$notification->setRichSubject($l->t('Your balance is forecast to go negative'));

				// TRANSLATORS: {balance}, {month} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichMessage(
					$l->t('Your projected balance falls to {balance} in {month}.'),
					[
						'balance' => ['type' => 'highlight', 'id' => 'balance', 'name' => $parameters['balance']],
						'month' => ['type' => 'highlight', 'id' => 'month', 'name' => $parameters['month']],
					]
				);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#forecast');

				break;

			case 'import_folder_done':
				// TRANSLATORS: {file} and {account} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
				$notification->setRichSubject(
					$l->t('{file} imported into {account}'),
					[
						'file' => $this->fileParameter($parameters),
						'account' => ['type' => 'highlight', 'id' => (string)$parameters['accountId'], 'name' => $parameters['accountName']],
					]
				);

				$parts = [$l->n('%n transaction imported', '%n transactions imported', (int)$parameters['imported'])];
				if ((int)($parameters['skipped'] ?? 0) > 0) {
					$parts[] = $l->n('%n already there or skipped', '%n already there or skipped', (int)$parameters['skipped']);
				}
				if ((int)($parameters['errors'] ?? 0) > 0) {
					$parts[] = $l->n('%n row could not be read', '%n rows could not be read', (int)$parameters['errors']);
				}
				// Plain, not rich: there is nothing to link, and rich text would
				// read any braces as placeholders
				$notification->setParsedMessage(implode(', ', $parts));

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#transactions');

				break;

			case 'import_folder_review':
				// TRANSLATORS: {file} is replaced with the file name. Do NOT translate {file} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('{file} is ready to review'),
					['file' => $this->fileParameter($parameters)]
				);

				// Plain, not rich: an account's name may hold braces
				$notification->setParsedMessage($l->t('Open the Import page to check it and import it into %1$s.', [(string)$parameters['accountName']]));

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute(
					Application::APP_ID . '.page.index'
				) . '#import');

				break;

			case 'import_folder_failed':
				// TRANSLATORS: {file} is replaced with the file name. Do NOT translate {file} — keep it exactly as-is.
				$notification->setRichSubject(
					$l->t('{file} could not be imported'),
					['file' => $this->fileParameter($parameters)]
				);

				$reason = match ($parameters['reason'] ?? 'error') {
					'unsupported_type' => $l->t('This type of file cannot be imported.'),
					'too_large' => $l->t('The file is larger than 10 MB.'),
					'account_unavailable' => $l->t('The account this folder imports into has been closed or deleted.'),
					'no_template' => $l->t('CSV and spreadsheet files need an import template, and this folder has none for them. Choose one in Budget\'s settings.'),
					'columns_missing' => $l->t('These columns from the folder\'s template are not in the file: %1$s.', [(string)($parameters['columns'] ?? '')]),
					'nothing_read' => $l->t('No transaction could be read from it. The folder\'s template may not fit this file.'),
					'wrong_direction' => $l->t('Nearly every row would go in the wrong way round (income as spending, or the reverse), which usually means the wrong template. Import it from the Import page, where you can check it first.'),
					'empty' => $l->t('The file is empty.'),
					'not_moved' => ($parameters['imported'] ?? '0') === '1'
						? $l->t('It was imported, but could not be moved out of the import folder, so the folder has been switched off. Move the file yourself, then switch the folder back on in Budget\'s settings.')
						: $l->t('It could not be moved out of the import folder either, so the folder has been switched off. Move the file yourself, then switch the folder back on in Budget\'s settings.'),
					'message' => (string)($parameters['message'] ?? ''),
					default => $l->t('Something went wrong while importing it. The server log has the details.'),
				};
				if (($parameters['moved'] ?? '0') === '1') {
					$reason .= ' ' . $l->t('It is in the Failed folder.');
				}
				$notification->setParsedMessage($reason);

				$notification->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
				));

				$notification->setLink($this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $parameters['fileId']]));

				break;

			default:
				throw new UnknownNotificationException('Unknown subject');
		}

		return $notification;
	}

	/**
	 * A file in the user's Files as a rich-text parameter.
	 *
	 * @param array<string, mixed> $parameters With fileId and fileName
	 * @return array<string, string>
	 */
	private function fileParameter(array $parameters): array {
		return [
			'type' => 'file',
			'id' => (string)$parameters['fileId'],
			'name' => (string)$parameters['fileName'],
			'path' => (string)$parameters['fileName'],
			'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $parameters['fileId']]),
		];
	}
}
