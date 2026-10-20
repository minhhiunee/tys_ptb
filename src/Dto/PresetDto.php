<?php

declare(strict_types=1);

namespace Photobooth\Dto;

class PresetDto
{
    public string $id;
    public string $name;
    public string $group = 'TYS TEST';
    public string $version = '1.0';
    public string $format = 'xmp';
    public string $sourceFile = '';
    public ?string $lutFile = null;
    public bool $supportsAmount = true;
    public int $defaultAmount = 100;
    public bool $enabled = true;
    public array $exactParams = [];
    public array $approximatedParams = [];
    public array $unsupportedParams = [];

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'group' => $this->group,
            'version' => $this->version,
            'format' => $this->format,
            'source_file' => $this->sourceFile,
            'lut_file' => $this->lutFile,
            'supports_amount' => $this->supportsAmount,
            'default_amount' => $this->defaultAmount,
            'enabled' => $this->enabled,
            'exact_params' => $this->exactParams,
            'approximated_params' => $this->approximatedParams,
            'unsupported_params' => $this->unsupportedParams,
        ];
    }

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->id = (string) ($data['id'] ?? '');
        $dto->name = (string) ($data['name'] ?? '');
        $dto->group = (string) ($data['group'] ?? 'TYS TEST');
        $dto->version = (string) ($data['version'] ?? '1.0');
        $dto->format = (string) ($data['format'] ?? 'xmp');
        $dto->sourceFile = (string) ($data['source_file'] ?? '');
        $dto->lutFile = isset($data['lut_file']) && $data['lut_file'] !== '' ? (string) $data['lut_file'] : null;
        $dto->supportsAmount = (bool) ($data['supports_amount'] ?? true);
        $dto->defaultAmount = (int) ($data['default_amount'] ?? 100);
        $dto->enabled = (bool) ($data['enabled'] ?? true);
        $dto->exactParams = (array) ($data['exact_params'] ?? []);
        $dto->approximatedParams = (array) ($data['approximated_params'] ?? []);
        $dto->unsupportedParams = (array) ($data['unsupported_params'] ?? []);

        return $dto;
    }
}
