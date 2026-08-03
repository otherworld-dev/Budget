<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Ocr;

/**
 * The configured provider failed: unreachable, errored, timed out, or
 * returned something that cannot be read as a receipt. Controllers answer
 * 502 — the configuration is (probably) fine, this request just failed.
 */
class OcrProviderException extends \RuntimeException {
}
