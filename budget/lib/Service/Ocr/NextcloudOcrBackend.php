<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Ocr;

use OCA\Budget\AppInfo\Application;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads an image through Nextcloud's own TaskProcessing framework.
 *
 * Kept as its own tiny class for the same reason OcrSettingsService resolves
 * the manager through the container: the TaskProcessing API only exists on
 * some of the Nextcloud versions this app supports, so nothing here may be
 * type-hinted against it — and the extraction service becomes testable by
 * mocking this seam instead of the framework.
 */
class NextcloudOcrBackend {
    /**
     * The task type asked for. OcrSettingsService::isNextcloudAiAvailable()
     * gates on the same prefix, so a configured instance is one where this
     * type resolves.
     */
    private const TASK_TYPE = 'core:image2text:ocr';

    public function __construct(
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Run OCR on the image and return the raw text.
     *
     * runTask() is the synchronous path: it executes inline when the provider
     * supports it and otherwise waits on the scheduled task. Receipt capture
     * is an interactive request, so a provider that cannot answer within the
     * request is treated as failed rather than left running in the dark.
     *
     * @param string $imageBase64 Raw image bytes, base64-encoded.
     * @throws OcrProviderException
     */
    public function extractText(string $imageBase64, string $userId): string {
        try {
            $manager = $this->container->get(\OCP\TaskProcessing\IManager::class);

            $task = new \OCP\TaskProcessing\Task(
                self::TASK_TYPE,
                ['input' => $imageBase64],
                Application::APP_ID,
                $userId
            );

            $finished = $manager->runTask($task);

            if ($finished->getStatus() !== \OCP\TaskProcessing\Task::STATUS_SUCCESSFUL) {
                throw new OcrProviderException(
                    'TaskProcessing task ended with status ' . $finished->getStatus()
                    . ($finished->getErrorMessage() !== null ? ': ' . $finished->getErrorMessage() : '')
                );
            }

            $output = $finished->getOutput()['output'] ?? null;
            if (!is_string($output) || trim($output) === '') {
                throw new OcrProviderException('TaskProcessing returned no text');
            }

            return $output;
        } catch (OcrProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('Receipt OCR via TaskProcessing failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
                'exception' => $e,
            ]);

            throw new OcrProviderException('TaskProcessing failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
