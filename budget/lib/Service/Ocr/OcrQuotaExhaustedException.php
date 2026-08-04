<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Ocr;

/**
 * The provider answered 429 — a metered backend (the relay's license tiers,
 * a paid API's rate cap) has run out for now. Distinct from a failure: the
 * configuration is right and the receipt is readable, the meter is empty.
 * Controllers answer 429 with error_code ocr_quota_exhausted.
 */
class OcrQuotaExhaustedException extends OcrProviderException {
}
