<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Attachment;
use OCA\Budget\Db\AttachmentMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

/**
 * Receipt attachments for transactions. Files live in the user's own Files
 * space and are referenced by fileId — stable across rename/move. The app
 * never deletes the user's files; detach and cascade remove only the
 * reference rows. Owner-only in v1: shared-account viewers cannot resolve
 * the owner's fileId through their own folder, so attachments are not
 * exposed to them.
 */
class AttachmentService {

    /** Public so the REST API can advertise the limits it enforces (v1 /info). */
    public const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf',
    ];
    public const MAX_SIZE = 26214400; // 25 MB
    private const RECEIPTS_FOLDER = 'Budget/Receipts';

    public function __construct(
        private AttachmentMapper $mapper,
        private TransactionMapper $transactionMapper,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * List a transaction's attachments, flagging rows whose file is gone
     * (deleted by the user) so the UI can grey them out instead of breaking.
     *
     * @return array[]
     */
    public function listForTransaction(int $transactionId, string $userId): array {
        $this->transactionMapper->find($transactionId, $userId); // ownership or 404

        $userFolder = $this->rootFolder->getUserFolder($userId);
        $result = [];
        foreach ($this->mapper->findByTransaction($transactionId, $userId) as $attachment) {
            $nodes = $userFolder->getById($attachment->getFileId());
            $node = $nodes[0] ?? null;
            $data = $attachment->jsonSerialize();
            if ($node instanceof File) {
                // Refresh name/mime — the user may have renamed the file
                $data['fileName'] = $node->getName();
                $data['mimeType'] = $node->getMimeType();
                $data['missing'] = false;
            } else {
                $data['missing'] = true;
            }
            $data['isImage'] = str_starts_with((string) $data['mimeType'], 'image/');
            $result[] = $data;
        }

        return $result;
    }

    /**
     * Attach an existing file from the user's Files (by path from the file
     * picker, or by fileId).
     */
    public function attachExisting(int $transactionId, string $userId, ?int $fileId, ?string $path): Attachment {
        $this->transactionMapper->find($transactionId, $userId); // ownership or 404

        $userFolder = $this->rootFolder->getUserFolder($userId);
        if ($fileId !== null) {
            $nodes = $userFolder->getById($fileId);
            $node = $nodes[0] ?? null;
        } elseif ($path !== null && $path !== '') {
            $node = $userFolder->get('/' . ltrim($path, '/'));
        } else {
            throw new \InvalidArgumentException('A file id or path is required');
        }

        if (!$node instanceof File) {
            throw new \InvalidArgumentException('Not a file');
        }
        $this->assertAllowedMime($node->getMimeType());

        return $this->createRow($transactionId, $userId, $node);
    }

    /**
     * Upload a new receipt into /Budget/Receipts/<year of transaction date>/
     * in the user's Files and attach it.
     *
     * @param array $uploadedFile PHP uploaded-file array (name, type, tmp_name, error, size)
     */
    public function upload(int $transactionId, string $userId, array $uploadedFile): Attachment {
        $transaction = $this->transactionMapper->find($transactionId, $userId); // ownership or 404

        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Upload failed');
        }
        if (($uploadedFile['size'] ?? 0) > self::MAX_SIZE) {
            throw new \InvalidArgumentException('File exceeds the 25 MB limit');
        }
        $mime = mime_content_type($uploadedFile['tmp_name']) ?: ($uploadedFile['type'] ?? '');
        $this->assertAllowedMime($mime);

        $userFolder = $this->rootFolder->getUserFolder($userId);
        $year = substr($transaction->getDate(), 0, 4) ?: date('Y');
        $folder = $userFolder;
        foreach ([...explode('/', self::RECEIPTS_FOLDER), $year] as $segment) {
            $folder = $folder->nodeExists($segment) ? $folder->get($segment) : $folder->newFolder($segment);
        }

        $name = $this->uniqueName($folder, $uploadedFile['name'] ?? 'receipt');
        try {
            $stream = fopen($uploadedFile['tmp_name'], 'rb');
            $node = $folder->newFile($name, $stream);
        } catch (NotEnoughSpaceException $e) {
            throw new \InvalidArgumentException('Not enough free space in your Files');
        } catch (NotPermittedException $e) {
            throw new \InvalidArgumentException('You are not allowed to write to the receipts folder');
        }

        return $this->createRow($transactionId, $userId, $node);
    }

    /**
     * Remove the attachment reference. The file itself is never touched.
     */
    public function detach(int $transactionId, string $userId, int $attachmentId): void {
        $this->transactionMapper->find($transactionId, $userId); // ownership or 404

        $attachment = $this->mapper->find($attachmentId, $userId);
        if ($attachment->getTransactionId() !== $transactionId) {
            throw new \InvalidArgumentException('Attachment does not belong to this transaction');
        }
        $this->mapper->delete($attachment);
    }

    /**
     * Attachment counts per transaction (for list badges).
     *
     * @return array<int, int>
     */
    public function getCounts(string $userId): array {
        return $this->mapper->countsByUser($userId);
    }

    private function createRow(int $transactionId, string $userId, File $node): Attachment {
        // Reject duplicates politely (unique index would throw anyway)
        foreach ($this->mapper->findByTransaction($transactionId, $userId) as $existing) {
            if ($existing->getFileId() === $node->getId()) {
                return $existing;
            }
        }

        $attachment = new Attachment();
        $attachment->setTransactionId($transactionId);
        $attachment->setUserId($userId);
        $attachment->setFileId($node->getId());
        $attachment->setFileName($node->getName());
        $attachment->setMimeType($node->getMimeType());
        $attachment->setCreatedAt(date('Y-m-d H:i:s'));

        return $this->mapper->insert($attachment);
    }

    private function assertAllowedMime(string $mime): void {
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('File type not allowed — use an image or PDF');
        }
    }

    private function uniqueName($folder, string $name): string {
        $name = trim(str_replace(['/', '\\'], '-', $name)) ?: 'receipt';
        if (!$folder->nodeExists($name)) {
            return $name;
        }
        $dot = strrpos($name, '.');
        $base = $dot === false ? $name : substr($name, 0, $dot);
        $ext = $dot === false ? '' : substr($name, $dot);
        for ($i = 1; $i < 1000; $i++) {
            $candidate = "{$base}-{$i}{$ext}";
            if (!$folder->nodeExists($candidate)) {
                return $candidate;
            }
        }
        return $base . '-' . time() . $ext;
    }
}
