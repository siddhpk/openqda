<?php

namespace App\Services\Qda;

/**
 * Validation rules and normalisation for the analytic details of a code
 * (stored as JSON in codes.meta).
 *
 * Fixed codebook fields work for any analytic approach: definition,
 * inclusion/exclusion criteria, an example, properties with the range of
 * their variation, and a status. Everything else is a custom field that
 * the project defines in its vocabulary (Vocabulary::for($project)).
 * Unknown keys are dropped.
 */
final class CodeMeta
{
    public const TEXT_FIELDS = ['definition', 'inclusion', 'exclusion', 'example'];

    public const FIXED_FIELDS = ['definition', 'inclusion', 'exclusion', 'example', 'properties', 'status'];

    public static function rules(VocabularySet $vocabulary, string $prefix = 'meta'): array
    {
        $rules = [
            $prefix => ['sometimes', 'nullable', 'array'],
            "{$prefix}.status" => ['nullable', 'in:'.implode(',', $vocabulary->statusKeys())],
            "{$prefix}.properties" => ['nullable', 'array', 'max:20'],
            "{$prefix}.properties.*.name" => ['required_with:'.$prefix.'.properties', 'string', 'max:120'],
            "{$prefix}.properties.*.low" => ['nullable', 'string', 'max:120'],
            "{$prefix}.properties.*.high" => ['nullable', 'string', 'max:120'],
        ];
        foreach (self::TEXT_FIELDS as $field) {
            $rules["{$prefix}.{$field}"] = ['nullable', 'string', 'max:5000'];
        }
        foreach ($vocabulary->codeFields() as $field) {
            $rules["{$prefix}.{$field['key']}"] = match ($field['type']) {
                'boolean' => ['nullable', 'boolean'],
                'choice' => ['nullable', 'in:'.implode(',', array_column($field['options'], 'key'))],
                default => ['nullable', 'string', 'max:5000'],
            };
        }

        return $rules;
    }

    public static function normalize(?array $meta, VocabularySet $vocabulary): ?array
    {
        if (! $meta) {
            return null;
        }

        $out = [];
        foreach (self::TEXT_FIELDS as $field) {
            $value = isset($meta[$field]) ? trim((string) $meta[$field]) : '';
            if ($value !== '') {
                $out[$field] = $value;
            }
        }
        if (! empty($meta['status']) && in_array($meta['status'], $vocabulary->statusKeys(), true)) {
            $out['status'] = $meta['status'];
        }
        if (! empty($meta['properties']) && is_array($meta['properties'])) {
            $props = [];
            foreach (array_slice($meta['properties'], 0, 20) as $property) {
                $name = trim((string) ($property['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $props[] = [
                    'name' => $name,
                    'low' => trim((string) ($property['low'] ?? '')),
                    'high' => trim((string) ($property['high'] ?? '')),
                ];
            }
            if ($props) {
                $out['properties'] = $props;
            }
        }
        foreach ($vocabulary->codeFields() as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $meta)) {
                continue;
            }
            $value = $meta[$key];
            if ($field['type'] === 'boolean') {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    $out[$key] = true;
                }
            } elseif ($field['type'] === 'choice') {
                if (in_array($value, array_column($field['options'], 'key'), true)) {
                    $out[$key] = $value;
                }
            } elseif (trim((string) $value) !== '') {
                $out[$key] = mb_substr(trim((string) $value), 0, 5000);
            }
        }

        return $out ?: null;
    }
}
