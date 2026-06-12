<?php

declare(strict_types=1);

namespace OCA\Budget\Notification;

use OCA\Budget\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
    private IFactory $l10nFactory;
    private IURLGenerator $urlGenerator;

    public function __construct(
        IFactory $l10nFactory,
        IURLGenerator $urlGenerator
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
            throw new \InvalidArgumentException('Unknown app');
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
                            'id' => $parameters['billId'],
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
                            'id' => $parameters['billId'],
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
                            'id' => $parameters['billId'],
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
                            'id' => $parameters['billId'],
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
                            'id' => $parameters['billId'],
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
                            'id' => $parameters['billId'],
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
                            'name' => $parameters['nextDueDate'],
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
                            'id' => $parameters['billId'],
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
                            'id' => $parameters['billId'],
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
                            'id' => $parameters['incomeId'],
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
                            'id' => $parameters['incomeId'],
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
                            'name' => $parameters['nextExpectedDate'],
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
                            'id' => $parameters['incomeId'],
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
                            'id' => $parameters['incomeId'],
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

                // TRANSLATORS: {income}, {expenses}, {net}, {bills} are placeholders — do NOT translate them. Keep all {placeholder} names exactly as-is.
                $notification->setRichMessage(
                    $l->t('Income {income}, spending {expenses} ({net} net). {bills} bills due soon.'),
                    [
                        'income' => ['type' => 'highlight', 'id' => 'income', 'name' => $parameters['income']],
                        'expenses' => ['type' => 'highlight', 'id' => 'expenses', 'name' => $parameters['expenses']],
                        'net' => ['type' => 'highlight', 'id' => 'net', 'name' => $parameters['net']],
                        'bills' => ['type' => 'highlight', 'id' => 'bills', 'name' => $parameters['billCount']],
                    ]
                );

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
                    $l->t('{category} spending is {percent}% above your typical level — {amount} so far this month.'),
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

            default:
                throw new \InvalidArgumentException('Unknown subject');
        }

        return $notification;
    }
}
