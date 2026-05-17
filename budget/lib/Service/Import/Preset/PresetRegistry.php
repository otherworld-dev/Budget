<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

class PresetRegistry {
    /** @var ImportPresetInterface[] */
    private array $presets = [];

    public function __construct() {
        $this->register(new ToshlPreset());
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

    public function toArray(): array {
        return array_map(fn(ImportPresetInterface $p) => [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'description' => $p->getDescription(),
            'format' => 'csv',
            'mapping' => $p->getMapping(),
            'isPreset' => true,
        ], $this->presets);
    }
}
