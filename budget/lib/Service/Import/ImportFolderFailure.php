<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

/**
 * Why a file dropped in an import folder was not imported, as a code the
 * notification is written from in the reader's own language (#425).
 */
final class ImportFolderFailure extends \RuntimeException {
	/**
	 * @param array<string, string> $details Extra notification parameters (the missing columns, say)
	 */
	public function __construct(
		public readonly string $reason,
		public readonly array $details = [],
	) {
		parent::__construct($reason);
	}
}
