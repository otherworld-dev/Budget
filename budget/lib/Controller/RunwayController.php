<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\Runway\RunwayOneOffService;
use OCA\Budget\Service\Runway\RunwayService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Web UI back end for the dashboard's runway tiles: the forecast itself and
 * the planned one-offs that feed it.
 */
class RunwayController extends Controller {
	use ApiErrorHandlerTrait;

	private const ONE_OFF_FIELDS = ['description', 'amount', 'date'];

	private string $userId;

	public function __construct(
		IRequest $request,
		private RunwayService $runway,
		private RunwayOneOffService $oneOffs,
		private IL10N $l,
		?string $userId,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->userId = $userId ?? '';
		$this->setLogger($logger);
	}

	#[NoAdminRequired]
	public function index(): DataResponse {
		try {
			return new DataResponse($this->runway->get($this->userId));
		} catch (\Throwable $e) {
			return $this->fail($e, $this->l->t('Failed to load the runway forecast'));
		}
	}

	#[NoAdminRequired]
	public function createOneOff(): DataResponse {
		try {
			$input = $this->readInput();
			foreach (self::ONE_OFF_FIELDS as $field) {
				// A missing field is the same invalid input as an empty one.
				$input[$field] ??= '';
			}
			$oneOff = $this->oneOffs->create($this->userId, $input['description'], $input['amount'], $input['date']);
			return new DataResponse($oneOff, Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fail($e, $this->l->t('Failed to save the one-off'));
		}
	}

	#[NoAdminRequired]
	public function updateOneOff(int $id): DataResponse {
		try {
			$oneOff = $this->oneOffs->update($this->userId, $id, $this->readInput());
			return new DataResponse($oneOff);
		} catch (\Throwable $e) {
			return $this->fail($e, $this->l->t('Failed to save the one-off'));
		}
	}

	#[NoAdminRequired]
	public function destroyOneOff(int $id): DataResponse {
		try {
			$this->oneOffs->delete($this->userId, $id);
			return new DataResponse(['success' => true]);
		} catch (\Throwable $e) {
			return $this->fail($e, $this->l->t('Failed to delete the one-off'));
		}
	}

	/**
	 * The one-off fields present in the request, as strings. The service casts
	 * whatever it is given, so an array or object has to be refused here
	 * rather than become the text "Array". Only the amount may arrive as a
	 * JSON number.
	 *
	 * @return array<string, string>
	 * @throws \InvalidArgumentException
	 */
	private function readInput(): array {
		$params = $this->request->getParams();
		$input = [];
		foreach (self::ONE_OFF_FIELDS as $field) {
			if (!array_key_exists($field, $params)) {
				continue;
			}
			$value = $params[$field];
			$ok = is_string($value) || ($field === 'amount' && (is_int($value) || is_float($value)));
			if (!$ok) {
				throw new \InvalidArgumentException($this->l->t('Invalid value for %1$s.', [$field]));
			}
			$input[$field] = (string)$value;
		}
		return $input;
	}

	private function fail(\Throwable $e, string $generic): DataResponse {
		if ($e instanceof \InvalidArgumentException) {
			return $this->handleValidationError($e);
		}
		if ($e instanceof DoesNotExistException) {
			return $this->handleNotFoundError($e, $this->l->t('One-off'));
		}
		return $this->handleError($e, $generic, Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
