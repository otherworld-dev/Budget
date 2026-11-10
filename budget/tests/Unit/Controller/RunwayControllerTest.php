<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\RunwayController;
use OCA\Budget\Db\RunwayOneOff;
use OCA\Budget\Service\Runway\RunwayOneOffService;
use OCA\Budget\Service\Runway\RunwayService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RunwayControllerTest extends TestCase {
	private IRequest $request;
	private RunwayService $runway;
	private RunwayOneOffService $oneOffs;
	private RunwayController $controller;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->runway = $this->createMock(RunwayService::class);
		$this->oneOffs = $this->createMock(RunwayOneOffService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$this->controller = new RunwayController(
			$this->request,
			$this->runway,
			$this->oneOffs,
			$l,
			'user1',
			$this->createMock(LoggerInterface::class)
		);
	}

	private function oneOff(): RunwayOneOff {
		$o = new RunwayOneOff();
		$o->setDescription('Boiler');
		$o->setAmount('-864.27');
		$o->setDate('2026-12-01');
		return $o;
	}

	public function testIndexReturnsServiceArray(): void {
		$data = ['state' => 'ok', 'runwayDays' => 120];
		$this->runway->expects($this->once())->method('get')->with('user1')->willReturn($data);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($data, $response->getData());
	}

	public function testIndexUnexpectedErrorIs500AndGeneric(): void {
		$this->runway->method('get')->willThrowException(new \RuntimeException('secret detail'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertStringNotContainsString('secret', json_encode($response->getData()));
	}

	public function testCreatePassesStrings(): void {
		$this->request->method('getParams')->willReturn(['description' => 'Boiler', 'amount' => -864.27, 'date' => '2026-12-01']);
		$this->oneOffs->expects($this->once())->method('create')
			->with('user1', 'Boiler', '-864.27', '2026-12-01')
			->willReturn($this->oneOff());

		$response = $this->controller->createOneOff();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateInvalidIs400WithMessage(): void {
		$this->request->method('getParams')->willReturn(['description' => 'x', 'amount' => '864,27', 'date' => '2026-12-01']);
		$this->oneOffs->method('create')->willThrowException(new \InvalidArgumentException('Amount is not valid'));

		$response = $this->controller->createOneOff();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Amount is not valid'], $response->getData());
	}

	public function testCreateRejectsArrayParam(): void {
		$this->request->method('getParams')->willReturn(['description' => ['a'], 'amount' => '1', 'date' => '2026-12-01']);
		$this->oneOffs->expects($this->never())->method('create');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createOneOff()->getStatus());
	}

	public function testCreateRejectsNumericDescription(): void {
		$this->request->method('getParams')->willReturn(['description' => 5, 'amount' => '1', 'date' => '2026-12-01']);
		$this->oneOffs->expects($this->never())->method('create');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->createOneOff()->getStatus());
	}

	public function testUpdateOnlyPassesPresentKeys(): void {
		$this->request->method('getParams')->willReturn(['id' => '4', 'amount' => '-10.00']);
		$this->oneOffs->expects($this->once())->method('update')
			->with('user1', 4, ['amount' => '-10.00'])
			->willReturn($this->oneOff());

		$this->assertSame(Http::STATUS_OK, $this->controller->updateOneOff(4)->getStatus());
	}

	public function testUpdateAnotherUsersIdIs404(): void {
		$this->request->method('getParams')->willReturn(['amount' => '1']);
		$this->oneOffs->method('update')->willThrowException(new DoesNotExistException('nope'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->updateOneOff(99)->getStatus());
	}

	public function testUpdateRejectsObjectParam(): void {
		$this->request->method('getParams')->willReturn(['date' => ['x' => 1]]);
		$this->oneOffs->expects($this->never())->method('update');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->updateOneOff(4)->getStatus());
	}

	public function testDestroyReturns200(): void {
		$this->oneOffs->expects($this->once())->method('delete')->with('user1', 4);

		$response = $this->controller->destroyOneOff(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => true], $response->getData());
	}

	public function testDestroyMissingIs404(): void {
		$this->oneOffs->method('delete')->willThrowException(new DoesNotExistException('nope'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller->destroyOneOff(4)->getStatus());
	}
}
