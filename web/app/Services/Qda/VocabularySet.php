<?php

namespace App\Services\Qda;

/**
 * A project's resolved vocabulary (see Vocabulary::for()).
 */
final class VocabularySet
{
    public function __construct(public readonly array $data) {}

    public function keys(string $section, bool $includeArchived = true): array
    {
        return array_values(array_map(
            fn ($e) => $e['key'],
            array_filter($this->data[$section] ?? [], fn ($e) => $includeArchived || ! $e['archived'])
        ));
    }

    public function entry(string $section, string $key): ?array
    {
        foreach ($this->data[$section] ?? [] as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    public function codeTypeKeys(): array
    {
        return $this->keys('code_types');
    }

    public function relationTypeKeys(): array
    {
        return $this->keys('relation_types');
    }

    public function memoTypeKeys(): array
    {
        return $this->keys('memo_types');
    }

    public function statusKeys(): array
    {
        return $this->keys('statuses');
    }

    public function isDirected(string $relationType): bool
    {
        return (bool) ($this->entry('relation_types', $relationType)['directed'] ?? true);
    }

    /** @return array<int, array> */
    public function codeFields(): array
    {
        return $this->data['code_fields'] ?? [];
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
