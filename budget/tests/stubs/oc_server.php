<?php

declare(strict_types=1);

/**
 * Minimal \OC stub for unit testing.
 *
 * OCP\Server::get() calls \OC::$server->get() internally.
 * This stub provides the \OC class with a static $server property
 * that tests can set to a mock PSR container.
 */
if (!class_exists(\OC::class)) {
    class OC {
        /** @var \Psr\Container\ContainerInterface|null */
        public static $server = null;
    }
}
