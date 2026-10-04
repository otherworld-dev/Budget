<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Command;

use OCA\Budget\Command\PurgeDeletedUsers;
use OCA\Budget\Service\DeletedUserData;
use OCA\Budget\Service\FactoryResetService;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Users deleted before 3.0 kept their Budget data, and their shares and
 * contact links. The command removes it, but only when asked to: a user
 * backend that is down can make a real user look deleted, so it is a dry run
 * by default and asks again right before removing anything.
 */
class PurgeDeletedUsersTest extends TestCase {
	private DeletedUserData $data;
	private FactoryResetService $reset;
	/** @var string[] uids that exist when asked right before removal */
	private array $existing = [];
	/** @var string[] uids purged, in order */
	private array $purged = [];

	protected function setUp(): void {
		$this->data = $this->createMock(DeletedUserData::class);
		$this->reset = $this->createMock(FactoryResetService::class);
		$this->reset->method('purgeDeletedUser')->willReturnCallback(function (string $uid) {
			if ($uid === 'broken') {
				throw new \RuntimeException('DB error');
			}
			$this->purged[] = $uid;
		});
	}

	/** @return array{0: int, 1: string} */
	private function invoke(array $input, array $gone, array $unknown = []): array {
		$this->data->method('find')->willReturn(['gone' => $gone, 'unknown' => $unknown]);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn (string $uid) => in_array($uid, $this->existing, true));
		$command = new PurgeDeletedUsers($this->data, $this->reset, $users);
		$output = new BufferedOutput();
		$code = $command->run(new ArrayInput($input), $output);
		return [$code, $output->fetch()];
	}

	public function testADryRunListsTheDataAndChangesNothing(): void {
		[$code, $out] = $this->invoke([], [
			'frank' => ['budget_accounts' => 2, 'budget_transactions' => 70001, 'budget_shares.shared_with_user_id' => 1],
		]);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('frank: accounts 2, transactions 70001, shares.shared_with_user_id 1', $out);
		$this->assertStringContainsString('Nothing was changed', $out);
		$this->assertSame([], $this->purged);
	}

	public function testForceRemovesTheDataOfEveryUserListed(): void {
		[$code, $out] = $this->invoke(['--force' => true], [
			'erin' => ['budget_settings' => 3],
			'frank' => ['budget_accounts' => 1],
		]);

		$this->assertSame(0, $code);
		$this->assertSame(['erin', 'frank'], $this->purged);
		$this->assertStringContainsString('Removed the Budget data of frank.', $out);
	}

	public function testAUserWhoExistsAgainByThenIsLeftAlone(): void {
		$this->existing = ['frank'];

		[$code, $out] = $this->invoke(['--force' => true], ['frank' => ['budget_accounts' => 1], 'erin' => ['budget_bills' => 1]]);

		$this->assertSame(0, $code);
		$this->assertSame(['erin'], $this->purged);
		$this->assertStringContainsString('frank: the user exists now', $out);
	}

	public function testUserLimitsTheRunToTheNamedUsers(): void {
		[, $out] = $this->invoke(['--force' => true, '--user' => ['frank', 'alice']], [
			'erin' => ['budget_settings' => 3],
			'frank' => ['budget_accounts' => 1],
		]);

		$this->assertSame(['frank'], $this->purged);
		$this->assertStringContainsString('alice: no Budget data of a deleted user', $out);
	}

	public function testAFailedRemovalIsReportedAndTheOthersStillRun(): void {
		[$code, $out] = $this->invoke(['--force' => true], [
			'broken' => ['budget_accounts' => 1],
			'frank' => ['budget_accounts' => 1],
		]);

		$this->assertSame(1, $code);
		$this->assertSame(['frank'], $this->purged);
		$this->assertStringContainsString('Could not remove the Budget data of broken: DB error', $out);
	}

	public function testUsersTheBackendCannotAnswerForAreOnlyMentioned(): void {
		[$code, $out] = $this->invoke(['--force' => true], [], ['ldapuser']);

		$this->assertSame(0, $code);
		$this->assertSame([], $this->purged);
		$this->assertStringContainsString('ldapuser: the user backend could not say', $out);
		$this->assertStringContainsString('No Budget data of deleted users found.', $out);
	}

	public function testANumericUserIdIsPassedAsAString(): void {
		// PHP turns array keys like "1234" into integers
		[, $out] = $this->invoke(['--force' => true], [1234 => ['budget_accounts' => 1]]);

		$this->assertSame(['1234'], $this->purged);
		$this->assertStringContainsString('Removed the Budget data of 1234.', $out);
	}
}
