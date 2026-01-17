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
                    $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
                ));

                $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                    Application::APP_ID . '.page.index'
                ) . '#bills');

                break;

            case 'bill_overdue':
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
                    $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
                ));

                $notification->setLink($this->urlGenerator->linkToRouteAbsolute(
                    Application::APP_ID . '.page.index'
                ) . '#bills');

                break;

            default:
                throw new \InvalidArgumentException('Unknown subject');
        }

        return $notification;
    }
}
