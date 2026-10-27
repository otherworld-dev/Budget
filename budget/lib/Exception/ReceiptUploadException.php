<?php

declare(strict_types=1);

namespace OCA\Budget\Exception;

/**
 * A receipt that could not be stored, with a stable code saying why (#437),
 * so an API client can tell "this app password can't reach your Files" from
 * "your Files are full" without parsing the message. An
 * InvalidArgumentException so every existing caller keeps answering 400.
 */
class ReceiptUploadException extends \InvalidArgumentException {
	/** The login (an app password with filesystem access switched off) cannot reach Files. */
	public const FILES_ACCESS_DENIED = 'files_access_denied';
	/** Files are reachable but the receipts folder, or a parent, refuses writes. */
	public const FOLDER_NOT_WRITABLE = 'receipt_folder_not_writable';
	public const NOT_ENOUGH_SPACE = 'not_enough_space';
	/** Too big, the wrong type, or the upload itself broke. */
	public const INVALID_FILE = 'invalid_file';

	public function __construct(
		private string $errorCode,
		string $message,
	) {
		parent::__construct($message);
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}
}
