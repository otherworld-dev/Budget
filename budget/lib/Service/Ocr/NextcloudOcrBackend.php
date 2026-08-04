<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Ocr;

use OCA\Budget\AppInfo\Application;
use OCP\Files\IRootFolder;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads an image through Nextcloud's own TaskProcessing framework.
 *
 * Kept as its own class for the same reason OcrSettingsService resolves the
 * manager through the container: the TaskProcessing API only exists on some
 * of the Nextcloud versions this app supports, so nothing here may be
 * type-hinted against it — and the extraction service becomes testable by
 * mocking this seam instead of the framework.
 *
 * The core:image2text:ocr task type takes its input as a LIST OF FILE IDS
 * (EShapeType::ListOfFiles) and returns a LIST OF STRINGS — not base64 in,
 * string out. TaskProcessing validates file access through the user's own
 * mounts, so the image is written to a hidden temp file in the user's Files
 * for the duration of the task and deleted again in a finally block. Both
 * shapes were verified against a live NC 35; the earlier base64 version of
 * this class failed task validation on every call.
 */
class NextcloudOcrBackend {
    /**
     * The task type asked for (@since NC 33). OcrSettingsService gates the
     * 'nextcloud' provider on exactly this type being available.
     */
    public const TASK_TYPE = 'core:image2text:ocr';

    /** Hidden holding folder inside the user's Files for in-flight scans. */
    private const TMP_FOLDER = 'Budget/Receipts/.ocr-tmp';

    public function __construct(
        private ContainerInterface $container,
        private IRootFolder $rootFolder,
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
     * @param string $imageBytes RAW image bytes (not base64).
     * @throws OcrProviderException
     */
    public function extractText(string $imageBytes, string $mime, string $userId): string {
        $file = null;

        try {
            $manager = $this->container->get(\OCP\TaskProcessing\IManager::class);

            // The task references the image by file id, and TaskProcessing
            // checks that id resolves through the requesting user's mounts —
            // so the bytes must briefly live in the user's own Files.
            $userFolder = $this->rootFolder->getUserFolder($userId);
            try {
                $folder = $userFolder->get(self::TMP_FOLDER);
            } catch (\OCP\Files\NotFoundException $e) {
                $folder = $userFolder->newFolder(self::TMP_FOLDER);
            }
            $extension = str_replace('image/', '', $mime) === 'jpeg' ? 'jpg' : str_replace('image/', '', $mime);
            $file = $folder->newFile(uniqid('scan-', true) . '.' . $extension, $imageBytes);

            $task = new \OCP\TaskProcessing\Task(
                self::TASK_TYPE,
                ['input' => [$file->getId()]],
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

            // The output slot is a list of texts, one per input file.
            $output = $finished->getOutput()['output'] ?? null;
            if (is_array($output)) {
                $output = implode("\n", array_filter($output, 'is_string'));
            }
            if (!is_string($output) || trim($output) === '') {
                throw new OcrProviderException('TaskProcessing returned no text');
            }

            return $output;
        } catch (OcrProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Message ONLY — never the exception object. A serialized trace
            // carries this method's arguments, i.e. the receipt image itself,
            // straight into nextcloud.log.
            $this->logger->warning('Receipt OCR via TaskProcessing failed: ' . $e->getMessage(), [
                'app' => Application::APP_ID,
            ]);

            throw new OcrProviderException('TaskProcessing failed: ' . $e->getMessage(), 0, $e);
        } finally {
            if ($file !== null) {
                try {
                    $file->delete();
                } catch (\Throwable $e) {
                    $this->logger->debug('Could not remove temp scan file: ' . $e->getMessage(), [
                        'app' => Application::APP_ID,
                    ]);
                }
            }
        }
    }
}
