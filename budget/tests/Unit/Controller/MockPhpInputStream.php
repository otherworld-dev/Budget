<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

/**
 * Stream wrapper to mock php://input for testing controllers
 * that use file_get_contents('php://input').
 *
 * Usage:
 *   MockPhpInputStream::$data = '{"key":"value"}';
 *   stream_wrapper_unregister('php');
 *   stream_wrapper_register('php', MockPhpInputStream::class);
 *   // ... run test ...
 *   stream_wrapper_restore('php');
 */
class MockPhpInputStream {
	public static string $data = '';
	private int $position = 0;
	/** @var resource|null */
	public $context;

	public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
		$this->position = 0;
		return true;
	}

	public function stream_read(int $count): string {
		$chunk = substr(self::$data, $this->position, $count);
		$this->position += strlen($chunk);
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->position >= strlen(self::$data);
	}

	public function stream_stat(): array {
		return ['size' => strlen(self::$data)];
	}

	public function stream_set_option(int $option, int $arg1, int $arg2): bool {
		return true;
	}
}
