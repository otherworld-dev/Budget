<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\AuthController;
use OCA\Budget\Service\AuthService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuthControllerTest extends TestCase {
	private AuthController $controller;
	private AuthService $authService;
	private SettingService $settingService;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->authService = $this->createMock(AuthService::class);
		$this->settingService = $this->createMock(SettingService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new AuthController(
			$this->request,
			'user1',
			$this->authService,
			$this->settingService,
			$this->logger
		);
	}

	// ── status ──────────────────────────────────────────────────────

	public function testStatusReturnsAuthState(): void {
		$this->authService->method('isPasswordProtectionEnabled')->willReturn(true);
		$this->authService->method('hasPasswordProtection')->willReturn(true);
		$this->request->method('getHeader')->with('X-Budget-Session-Token')->willReturn('');

		$response = $this->controller->status();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['enabled']);
		$this->assertTrue($data['hasPassword']);
		$this->assertFalse($data['authenticated']);
	}

	public function testStatusShowsAuthenticatedWithValidSession(): void {
		$this->authService->method('isPasswordProtectionEnabled')->willReturn(true);
		$this->authService->method('hasPasswordProtection')->willReturn(true);
		$this->request->method('getHeader')->with('X-Budget-Session-Token')->willReturn('valid-token');
		$this->authService->method('isValidSession')->with('valid-token')->willReturn(true);
		$this->authService->method('getUserIdFromSession')->with('valid-token')->willReturn('user1');

		$response = $this->controller->status();

		$this->assertTrue($response->getData()['authenticated']);
	}

	public function testStatusNotAuthenticatedWithWrongUser(): void {
		$this->authService->method('isPasswordProtectionEnabled')->willReturn(true);
		$this->authService->method('hasPasswordProtection')->willReturn(true);
		$this->request->method('getHeader')->with('X-Budget-Session-Token')->willReturn('valid-token');
		$this->authService->method('isValidSession')->with('valid-token')->willReturn(true);
		$this->authService->method('getUserIdFromSession')->with('valid-token')->willReturn('other-user');

		$response = $this->controller->status();

		$this->assertFalse($response->getData()['authenticated']);
	}

	public function testStatusHandlesError(): void {
		$this->authService->method('isPasswordProtectionEnabled')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->status();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── setup ───────────────────────────────────────────────────────

	public function testSetupCreatesPassword(): void {
		$this->authService->expects($this->once())->method('setupPassword')->with('user1', 'goodpassword');
		$this->settingService->expects($this->once())->method('set')
			->with('user1', 'password_protection_enabled', 'true');
		$this->authService->method('verifyPassword')
			->willReturn(['success' => true, 'sessionToken' => 'token123']);

		$response = $this->controller->setup('goodpassword');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('token123', $data['sessionToken']);
	}

	public function testSetupRejectsShortPassword(): void {
		$response = $this->controller->setup('short');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('at least 6 characters', $response->getData()['error']);
	}

	public function testSetupHandlesInvalidArgument(): void {
		$this->authService->method('setupPassword')
			->willThrowException(new \InvalidArgumentException('Password already set'));

		$response = $this->controller->setup('goodpassword');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Password already set', $response->getData()['error']);
	}

	// ── verify ──────────────────────────────────────────────────────

	public function testVerifySucceeds(): void {
		$this->authService->method('verifyPassword')
			->with('user1', 'correct')
			->willReturn(['success' => true, 'sessionToken' => 'tok123']);

		$response = $this->controller->verify('correct');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame('tok123', $response->getData()['sessionToken']);
	}

	public function testVerifyFails(): void {
		$this->authService->method('verifyPassword')
			->willReturn(['success' => false, 'error' => 'Wrong password']);

		$response = $this->controller->verify('wrong');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	// ── lock ────────────────────────────────────────────────────────

	public function testLockSucceeds(): void {
		$this->authService->expects($this->once())->method('lockSession')->with('user1');

		$response = $this->controller->lock();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testLockHandlesError(): void {
		$this->authService->method('lockSession')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->lock();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── extend ──────────────────────────────────────────────────────

	public function testExtendSucceeds(): void {
		$this->request->method('getHeader')->with('X-Budget-Session-Token')->willReturn('valid-token');
		$this->authService->method('extendSession')->with('valid-token', 'user1')->willReturn(true);

		$response = $this->controller->extend();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testExtendRejectsNoToken(): void {
		$this->request->method('getHeader')->with('X-Budget-Session-Token')->willReturn('');

		$response = $this->controller->extend();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No session token provided', $response->getData()['error']);
	}

	public function testExtendRejectsInvalidSession(): void {
		$this->request->method('getHeader')->with('X-Budget-Session-Token')->willReturn('bad-token');
		$this->authService->method('extendSession')->willReturn(false);

		$response = $this->controller->extend();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	// ── disable ─────────────────────────────────────────────────────

	public function testDisableSucceeds(): void {
		$this->authService->method('disablePasswordProtection')->with('user1', 'correct')->willReturn(true);

		$response = $this->controller->disable('correct');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testDisableRejectsWrongPassword(): void {
		$this->authService->method('disablePasswordProtection')->willReturn(false);

		$response = $this->controller->disable('wrong');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Incorrect password', $response->getData()['error']);
	}

	// ── changePassword ──────────────────────────────────────────────

	public function testChangePasswordSucceeds(): void {
		$this->authService->method('changePassword')
			->with('user1', 'oldpass', 'newpassword')
			->willReturn(true);

		$response = $this->controller->changePassword('oldpass', 'newpassword');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testChangePasswordRejectsShortNew(): void {
		$response = $this->controller->changePassword('oldpass', 'short');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('at least 6 characters', $response->getData()['error']);
	}

	public function testChangePasswordRejectsWrongCurrent(): void {
		$this->authService->method('changePassword')->willReturn(false);

		$response = $this->controller->changePassword('wrong', 'newpassword');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Incorrect current password', $response->getData()['error']);
	}

	public function testChangePasswordHandlesInvalidArgument(): void {
		$this->authService->method('changePassword')
			->willThrowException(new \InvalidArgumentException('Same as current'));

		$response = $this->controller->changePassword('oldpass', 'newpassword');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
