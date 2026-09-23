<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Nextcloud\CodingStandard\Config;

// Nextcloud's shared coding standard (tabs, same-line braces, ordered imports).
// No ignoreVCSIgnored(): the checkout is often a git worktree whose .git file
// points at a host path a container can't resolve, so the exclusions are
// spelled out instead.
$config = new Config();
$config
	->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
	->getFinder()
	->in(__DIR__)
	->exclude([
		'build',
		'js',
		'l10n',
		'node_modules',
		'src',
		'translationfiles',
		'vendor',
	])
	->notPath('Bank statements');

return $config;
