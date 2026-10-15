<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\Tag;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The tags of a whole page of transactions, read in one go. The
 * transactions list used to ask for each row's tags separately.
 */
class TransactionTagsBatchTest extends IntegrationTestCase {
	public function testEachTransactionGetsItsOwnTags(): void {
		$account = $this->makeAccount()->getId();
		$groceries = $this->makeTag(null);
		$holiday = $this->makeTag(null);
		$work = $this->makeTag(null);
		$a = $this->makeTransaction($account);
		$b = $this->makeTransaction($account);
		$untagged = $this->makeTransaction($account);
		$this->tagTransaction($a, $holiday);
		$this->tagTransaction($a, $groceries);
		$this->tagTransaction($b, $work);

		$byTransaction = $this->service(TransactionTagMapper::class)->findTagIdsByTransactions([$a, $b, $untagged]);

		$this->assertSame([$a, $b], array_keys($byTransaction));
		$this->assertSame([min($groceries, $holiday), max($groceries, $holiday)], $byTransaction[$a]);
		$this->assertSame([$work], $byTransaction[$b]);

		$tags = $this->service(TransactionTagService::class)->getTagsForTransactions([$a, $b, $untagged]);
		$this->assertSame([$a, $b], array_keys($tags));
		$this->assertSame([$work], array_map(fn (Tag $tag) => $tag->getId(), $tags[$b]));
	}

	public function testAPageOfMoreThanFiveHundredRowsIsReadInChunks(): void {
		$account = $this->makeAccount()->getId();
		$tag = $this->makeTag(null);
		$first = $this->makeTransaction($account);
		$last = $this->makeTransaction($account);
		$this->tagTransaction($first, $tag);
		$this->tagTransaction($last, $tag);
		// Ids nobody has, so the two real ones land in different chunks
		$padding = range($last + 1000, $last + 1600);

		$byTransaction = $this->service(TransactionTagMapper::class)
			->findTagIdsByTransactions([$first, ...$padding, $last]);

		$this->assertSame([$first => [$tag], $last => [$tag]], $byTransaction);
	}
}
