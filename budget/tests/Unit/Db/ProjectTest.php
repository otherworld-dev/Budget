<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Project;
use OCA\Budget\Db\ProjectAllocation;
use PHPUnit\Framework\TestCase;

class ProjectTest extends TestCase {
	public function testSerialisesAProjectWithPlainDates(): void {
		$project = new Project();
		$project->setId(10);
		$project->setUserId('user1');
		$project->setName('House renovation');
		$project->setCategoryId(1);
		$project->setTotalAmount(900.0);
		// Some databases hand a DATE back with a time part
		$project->setStartDate('2026-03-01 00:00:00');
		$project->setEndDate(null);

		$json = $project->jsonSerialize();

		$this->assertSame(10, $json['id']);
		$this->assertSame('House renovation', $json['name']);
		$this->assertSame(1, $json['categoryId']);
		$this->assertSame(900.0, $json['totalAmount']);
		$this->assertSame('2026-03-01', $json['startDate']);
		$this->assertNull($json['endDate']);
	}

	public function testDayTrimsToTheDateAndTreatsBlankAsNone(): void {
		$this->assertSame('2026-12-31', Project::day('2026-12-31 00:00:00'));
		$this->assertSame('2026-12-31', Project::day('2026-12-31'));
		$this->assertNull(Project::day(''));
		$this->assertNull(Project::day(null));
	}

	public function testSerialisesAnAllocation(): void {
		$allocation = new ProjectAllocation();
		$allocation->setId(3);
		$allocation->setUserId('user1');
		$allocation->setProjectId(10);
		$allocation->setCategoryId(2);
		$allocation->setAmount(400.0);

		$this->assertSame(
			['id' => 3, 'projectId' => 10, 'categoryId' => 2, 'amount' => 400.0],
			$allocation->jsonSerialize()
		);
	}
}
