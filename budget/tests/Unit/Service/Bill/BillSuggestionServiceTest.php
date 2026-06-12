<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Bill;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Service\Bill\BillSuggestionService;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use PHPUnit\Framework\TestCase;

class BillSuggestionServiceTest extends TestCase {
    private BillSuggestionService $service;
    private RecurringBillDetector $detector;
    private BillMapper $billMapper;
    private DismissedSuggestionMapper $dismissedMapper;

    /** @var array[] */
    private array $detected = [];
    /** @var Bill[] */
    private array $bills = [];
    /** @var string[] */
    private array $dismissedHashes = [];

    protected function setUp(): void {
        $this->detector = $this->createMock(RecurringBillDetector::class);
        $this->detector->method('detectRecurringBills')
            ->with('alice', 6, true)
            ->willReturnCallback(fn() => $this->detected);
        $this->detector->method('normalizeDescription')
            ->willReturnCallback(fn(string $d) => strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\d+/', '', $d)))));
        $this->billMapper = $this->createMock(BillMapper::class);
        $this->billMapper->method('findAll')->willReturnCallback(fn() => $this->bills);
        $this->dismissedMapper = $this->createMock(DismissedSuggestionMapper::class);
        $this->dismissedMapper->method('findHashes')->willReturnCallback(fn() => $this->dismissedHashes);

        $this->service = new BillSuggestionService(
            $this->detector,
            $this->billMapper,
            $this->dismissedMapper
        );
    }

    private function makeCandidate(array $overrides = []): array {
        return array_merge([
            'patternKey' => 'netflix|13',
            'description' => 'NETFLIX 12345',
            'suggestedName' => 'Netflix',
            'amount' => 12.99,
            'frequency' => 'monthly',
            'dueDay' => 15,
            'categoryId' => null,
            'accountId' => 1,
            'occurrences' => 5,
            'confidence' => 0.83,
            'autoDetectPattern' => 'NETFLIX',
            'lastSeen' => '2026-06-01',
        ], $overrides);
    }

    private function makeBill(string $name, ?string $pattern = null): Bill {
        $bill = new Bill();
        $bill->setName($name);
        $bill->setAutoDetectPattern($pattern);
        return $bill;
    }

    public function testNewCandidateSurfaces(): void {
        $this->detected = [$this->makeCandidate()];

        $result = $this->service->getSuggestions('alice');

        $this->assertSame(1, $result['total']);
        $this->assertSame('Netflix', $result['suggestions'][0]['suggestedName']);
        $this->assertSame('netflix|13', $result['suggestions'][0]['patternKey']);
    }

    public function testLowConfidenceFilteredOut(): void {
        $this->detected = [$this->makeCandidate(['confidence' => 0.4])];

        $result = $this->service->getSuggestions('alice');

        $this->assertSame(0, $result['total']);
    }

    public function testDismissedPatternNeverReappears(): void {
        $this->detected = [$this->makeCandidate()];
        $this->dismissedHashes = [sha1('netflix|13')];

        $result = $this->service->getSuggestions('alice');

        $this->assertSame(0, $result['total']);
    }

    public function testExistingBillPatternExcluded(): void {
        $this->detected = [$this->makeCandidate()];
        $this->bills = [$this->makeBill('Streaming', 'NETFLIX 999')];

        $result = $this->service->getSuggestions('alice');

        $this->assertSame(0, $result['total']);
    }

    public function testExistingBillNameFallbackExcluded(): void {
        // Bill without autoDetectPattern — its name still excludes matches
        $this->detected = [$this->makeCandidate(['description' => 'Netflix subscription'])];
        $this->bills = [$this->makeBill('Netflix', null)];

        $result = $this->service->getSuggestions('alice');

        $this->assertSame(0, $result['total']);
    }

    public function testUnrelatedBillDoesNotExclude(): void {
        $this->detected = [$this->makeCandidate()];
        $this->bills = [$this->makeBill('Rent', 'LANDLORD CO')];

        $result = $this->service->getSuggestions('alice');

        $this->assertSame(1, $result['total']);
    }

    public function testLimitAndTotal(): void {
        $this->detected = [];
        for ($i = 1; $i <= 8; $i++) {
            $this->detected[] = $this->makeCandidate([
                'patternKey' => "merchant{$i}|10",
                'description' => "Merchant {$i}",
            ]);
        }

        $result = $this->service->getSuggestions('alice', 3);

        $this->assertCount(3, $result['suggestions']);
        $this->assertSame(8, $result['total']);
    }

    public function testDismissStoresHashedKey(): void {
        $this->dismissedMapper->expects($this->once())
            ->method('dismiss')
            ->with('alice', 'bill', sha1('netflix|13'), 'netflix|13');

        $this->service->dismiss('alice', 'netflix|13');
    }
}
