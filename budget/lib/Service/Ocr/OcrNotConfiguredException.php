<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Ocr;

/**
 * No OCR provider is configured (or the configured one is unusable).
 * Controllers answer 501 — the client shows "not set up on your server".
 */
class OcrNotConfiguredException extends \RuntimeException {
}
