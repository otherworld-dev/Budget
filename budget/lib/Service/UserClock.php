<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCP\IConfig;

/**
 * "What day is it?" answered in the USER's timezone, not the server's.
 *
 * Dates in this app come from a person's calendar: the web UI pre-fills the
 * form with the browser's local date, and a capture app sends the phone's.
 * Judging those against the server's own date silently misfiles anything the
 * user records while their local date is ahead of the server's — a UTC server
 * sees a London user's 00:30 purchase as tomorrow, marks it `scheduled`, and
 * leaves it out of the account balance until a background job catches up
 * hours later. For a user in Sydney that window is most of their working day.
 *
 * The timezone is resolved per user, never from the session: writes into a
 * shared account run under the OWNER's id, and OCS/API requests and
 * background jobs have no session at all — \OCP\IDateTimeZone would quietly
 * answer UTC for all of them.
 */
class UserClock {
    public function __construct(
        private IConfig $config,
    ) {
    }

    /**
     * Today's date (Y-m-d) as the given user's calendar shows it.
     *
     * Falls back to the instance's configured timezone, then the server's,
     * when a user has none stored — a user who has never opened the web UI
     * has nothing better to offer.
     */
    public function today(?string $userId): string {
        return $this->now($userId)->format('Y-m-d');
    }

    /**
     * True when $date (Y-m-d) is a genuine future date for this user, and so
     * a transaction on it should be recorded as scheduled rather than
     * counted in the balance today.
     */
    public function isFutureDate(string $date, ?string $userId): bool {
        return $date > $this->today($userId);
    }

    public function now(?string $userId): \DateTimeImmutable {
        return new \DateTimeImmutable('now', $this->timezoneFor($userId));
    }

    private function timezoneFor(?string $userId): \DateTimeZone {
        $candidates = [];

        if ($userId !== null && $userId !== '') {
            // Where Nextcloud's own web UI stores the browser's timezone.
            $candidates[] = (string)$this->config->getUserValue($userId, 'core', 'timezone', '');
        }
        $candidates[] = (string)$this->config->getSystemValue('default_timezone', '');

        foreach ($candidates as $candidate) {
            if (trim($candidate) === '') {
                continue;
            }
            try {
                return new \DateTimeZone($candidate);
            } catch (\Exception $e) {
                // Stored garbage, or a zone this PHP build does not know:
                // try the next candidate rather than fail a save.
            }
        }

        return new \DateTimeZone(date_default_timezone_get());
    }
}
