<?php

declare(strict_types=1);

namespace Symfony\UX\LiveComponent;

final class ModelBehavior
{
    private string $default = 'on(change)';

    /** @var array<string,string> */
    private array $fields = [];

    public function default(string $model): self
    {
        $this->default = $model;

        return $this;
    }

    public function field(string $name, string $model): self
    {
        $this->fields[$name] = $model;

        return $this;
    }

    public function getModelForField(string $field): string
    {
        $model = $this->fields[$field] ?? $this->default;

        return $model.'|'.$field;
    }
}
