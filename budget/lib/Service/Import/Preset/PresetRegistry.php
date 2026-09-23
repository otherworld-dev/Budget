<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

class PresetRegistry {
	/** @var ImportPresetInterface[] */
	private array $presets = [];

	public function __construct() {
		$this->register(new ToshlPreset());
		$this->register(new FireflyIIIPreset());
		$this->register(new YnabPreset());
		$this->register(new ActualBudgetPreset());
		$this->register(new MintPreset());
		$this->register(new MonarchMoneyPreset());
	}

	private function register(ImportPresetInterface $preset): void {
		$this->presets[$preset->getId()] = $preset;
	}

	public function get(string $id): ?ImportPresetInterface {
		return $this->presets[$id] ?? null;
	}

	/** @return ImportPresetInterface[] */
	public function getAll(): array {
		return array_values($this->presets);
	}

	/**
	 * The preset whose export this header row looks like, or null.
	 *
	 * A header-mapped preset matches when every one of its required columns
	 * is present (in any order, any case); Toshl, which is read by position,
	 * matches only its own English header exactly. When several match, the
	 * one that recognises the most columns wins, so a file is never claimed
	 * by a preset that only shares a few generic names with it.
	 *
	 * @param array<int, mixed> $headers The file's header row
	 */
	public function detect(array $headers): ?string {
		$present = [];
		foreach ($headers as $header) {
			if (is_scalar($header)) {
				$present[mb_strtolower(trim((string)$header))] = true;
			}
		}
		if ($present === []) {
			return null;
		}

		$bestId = null;
		$bestScore = 0;
		foreach ($this->presets as $id => $preset) {
			if ($preset instanceof HeaderMappedPresetInterface) {
				$wanted = $preset->getRequiredHeaders();
			} else {
				$wanted = $preset->getExpectedHeaders() ?? [];
				if (count($wanted) !== count($present)) {
					continue;
				}
			}
			if ($wanted === []) {
				continue;
			}

			foreach ($wanted as $header) {
				if (!isset($present[mb_strtolower($header)])) {
					continue 2;
				}
			}
			if (count($wanted) > $bestScore) {
				$bestId = (string)$id;
				$bestScore = count($wanted);
			}
		}

		return $bestId;
	}

	public function toArray(): array {
		return array_map(fn (ImportPresetInterface $p) => [
			'id' => $p->getId(),
			'name' => $p->getName(),
			'description' => $p->getDescription(),
			'format' => 'csv',
			'mapping' => $p->getMapping(),
			'options' => $p->getOptions(),
			'isPreset' => true,
		], $this->presets);
	}
}
