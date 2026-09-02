<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Tag;
use PHPUnit\Framework\TestCase;

/**
 * A tag can be hidden from the pickers that assign tags to new entries while
 * staying on everything that already carries it (#373). The flag has to reach
 * the browser on every tag payload, and a tag that predates the column (NULL)
 * must read as not hidden.
 */
class TagTest extends TestCase {
    public function testJsonSerializeReportsHiddenFalseByDefault(): void {
        $tag = new Tag();
        $tag->setId(1);
        $tag->setName('2026 NYC');

        $json = $tag->jsonSerialize();

        $this->assertArrayHasKey('hidden', $json);
        $this->assertFalse($json['hidden']);
    }

    public function testJsonSerializeReportsHiddenTrueWhenSet(): void {
        $tag = new Tag();
        $tag->setId(1);
        $tag->setName('2026 NYC');
        $tag->setHidden(true);

        $this->assertTrue($tag->jsonSerialize()['hidden']);
    }

    public function testHiddenReadsFalseForNullFromOlderRows(): void {
        $tag = Tag::fromRow(['id' => 1, 'name' => '2026 NYC', 'hidden' => null]);

        $this->assertFalse($tag->getHidden());
        $this->assertFalse($tag->jsonSerialize()['hidden']);
    }
}
