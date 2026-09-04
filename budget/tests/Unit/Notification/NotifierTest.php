<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Notification;

use OCA\Budget\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * The Notifier's whole job is populating an INotification, so these tests
 * capture what it sets. The IL10N double runs the same vsprintf that
 * Nextcloud's L10NString does — that is what makes an unescaped literal '%'
 * in a t() string blow up here rather than in production (#305).
 */
class NotifierTest extends TestCase {
    private Notifier $notifier;

    private string $richSubject = '';
    private array $richSubjectParams = [];
    private string $richMessage = '';
    private array $richMessageParams = [];

    protected function setUp(): void {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(
            static fn(string $text, array $params = []): string => vsprintf($text, $params)
        );
        $l->method('n')->willReturnCallback(
            static fn(string $singular, string $plural, int $count, array $params = []): string =>
                vsprintf(str_replace('%n', (string) $count, $count === 1 ? $singular : $plural), $params)
        );

        $l10nFactory = $this->createMock(IFactory::class);
        $l10nFactory->method('get')->willReturn($l);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/budget/');
        $urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example/app.svg');
        $urlGenerator->method('imagePath')->willReturn('/img/app.svg');

        $this->notifier = new Notifier($l10nFactory, $urlGenerator);
    }

    private function prepare(string $subject, array $parameters): INotification {
        $notification = $this->createMock(INotification::class);
        // Specific stubs must be registered BEFORE the catch-all: PHPUnit
        // matches in registration order and anything() would swallow them.
        $notification->method('getApp')->willReturn('budget');
        $notification->method('getSubject')->willReturn($subject);
        $notification->method('getSubjectParameters')->willReturn($parameters);
        $notification->method('setRichSubject')
            ->willReturnCallback(function (string $text, array $params = []) use ($notification) {
                $this->richSubject = $text;
                $this->richSubjectParams = $params;
                return $notification;
            });
        $notification->method('setRichMessage')
            ->willReturnCallback(function (string $text, array $params = []) use ($notification) {
                $this->richMessage = $text;
                $this->richMessageParams = $params;
                return $notification;
            });
        $notification->method($this->anything())->willReturnSelf();

        return $this->notifier->prepare($notification, 'en');
    }

    private function digestParameters(string $anomalyCount): array {
        return [
            'frequency' => 'weekly',
            'income' => '$3,000.00',
            'expenses' => '$2,000.00',
            'net' => '$1,000.00',
            'billCount' => '2',
            'anomalyCount' => $anomalyCount,
        ];
    }

    public function testDigestMessageOmitsUnusualSpendingWhenThereIsNone(): void {
        $this->prepare('digest', $this->digestParameters('0'));

        $this->assertStringNotContainsString('unusually', $this->richMessage);
        $this->assertArrayNotHasKey('anomalies', $this->richMessageParams);
    }

    public function testDigestMessageMentionsASingleUnusualCategory(): void {
        $this->prepare('digest', $this->digestParameters('1'));

        $this->assertStringContainsString('one category is spending unusually', $this->richMessage);
    }

    public function testDigestMessageMentionsSeveralUnusualCategories(): void {
        $this->prepare('digest', $this->digestParameters('3'));

        $this->assertStringContainsString('{anomalies} categories are spending unusually', $this->richMessage);
        $this->assertSame('3', $this->richMessageParams['anomalies']['name']);
    }

    /**
     * Notifications queued before the count was rendered have no such
     * parameter; preparing one must not fatal.
     */
    public function testDigestMessageToleratesAMissingAnomalyCount(): void {
        $parameters = $this->digestParameters('0');
        unset($parameters['anomalyCount']);

        $this->prepare('digest', $parameters);

        $this->assertStringContainsString('bills due soon', $this->richMessage);
    }

    public function testSpendingAnomalyMessageRendersALiteralPercentSign(): void {
        $this->prepare('spending_anomaly', [
            'categoryName' => 'Groceries',
            'percentAbove' => '62',
            'amount' => '$412.90',
        ]);

        $this->assertStringContainsString('{percent}% above', $this->richMessage);
        $this->assertSame('Groceries', $this->richSubjectParams['category']['name']);
    }

    private function budgetAlertParameters(string $severity): array {
        return [
            'categoryName' => 'Groceries',
            'severity' => $severity,
            'percentage' => '92.5',
            'spent' => '$462.50',
            'budget' => '$500.00',
        ];
    }

    public function testBudgetAlertWarningNamesTheCategoryAndItsSpend(): void {
        $this->prepare('budget_alert', $this->budgetAlertParameters('warning'));

        $this->assertSame('Groceries', $this->richSubjectParams['category']['name']);
        $this->assertSame('$462.50', $this->richMessageParams['spent']['name']);
        $this->assertSame('$500.00', $this->richMessageParams['budget']['name']);
    }

    public function testBudgetAlertDangerReadsAsOverBudget(): void {
        $this->prepare('budget_alert', $this->budgetAlertParameters('danger'));
        $overBudget = $this->richSubject;

        $this->prepare('budget_alert', $this->budgetAlertParameters('warning'));

        $this->assertStringContainsString('over budget', $overBudget);
        $this->assertNotSame($overBudget, $this->richSubject);
    }

    public function testBudgetAlertMessageRendersALiteralPercentSign(): void {
        $this->prepare('budget_alert', $this->budgetAlertParameters('warning'));

        $this->assertStringContainsString('{percent}%', $this->richMessage);
        $this->assertStringNotContainsString('%%', $this->richMessage);
        $this->assertSame('92.5', $this->richMessageParams['percent']['name']);
    }

    public function testForecastWarningNamesTheMonthAndBalance(): void {
        $this->prepare('forecast_warning', ['month' => 'Aug 2026', 'balance' => '-$120.50']);

        $this->assertSame('Aug 2026', $this->richMessageParams['month']['name']);
        $this->assertSame('-$120.50', $this->richMessageParams['balance']['name']);
    }

    public function testUnknownSubjectIsRejected(): void {
        $this->expectException(\OCP\Notification\UnknownNotificationException::class);

        $this->prepare('not_a_budget_subject', []);
    }
}
