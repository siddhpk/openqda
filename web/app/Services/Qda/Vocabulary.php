<?php

namespace App\Services\Qda;

use App\Models\Code;
use App\Models\CodeRelation;
use App\Models\Note;
use App\Models\Project;
use App\Models\ProjectVocabulary;

/**
 * The analytic vocabulary of a project: which kinds of codes, relationships,
 * memos, statuses and code fields the researchers work with.
 *
 * OpenQDA does not prescribe a methodology. It ships a small, neutral
 * starter set that every project can rename, extend or archive, so a team
 * can work with themes, categories, pattern codes, propositions or any
 * other analytic language of its choice.
 *
 * Keys of the first OpenQDA analytic release (e.g. relation types such as
 * "causal_condition", memo types such as "storyline") stay readable: when a
 * project still uses them they are added to that project's vocabulary.
 */
final class Vocabulary
{
    public const SECTIONS = ['code_types', 'relation_types', 'memo_types', 'statuses', 'code_fields'];

    public const MEMO_TARGETS = ['project', 'source', 'selection', 'code', 'relation', 'case', 'concept', 'proposition'];

    public const FIELD_TYPES = ['text', 'choice', 'boolean'];

    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    /**
     * Neutral starting point for new projects.
     */
    public static function defaults(): array
    {
        $all = self::MEMO_TARGETS;

        return [
            'code_types' => [
                ['key' => 'code', 'label' => 'Code', 'description' => 'Labels a meaningful segment of the data.'],
                ['key' => 'category', 'label' => 'Category', 'description' => 'Groups related codes.'],
                ['key' => 'theme', 'label' => 'Theme', 'description' => 'A broader pattern across the data.'],
            ],
            'relation_types' => [
                ['key' => 'leads_to', 'label' => 'leads to', 'short' => 'leads to', 'directed' => true, 'valence' => true],
                ['key' => 'precedes', 'label' => 'precedes', 'short' => 'precedes', 'directed' => true],
                ['key' => 'condition_for', 'label' => 'is a condition for', 'short' => 'condition', 'directed' => true],
                ['key' => 'consequence', 'label' => 'is a consequence of', 'short' => 'consequence', 'directed' => true],
                ['key' => 'part_of', 'label' => 'is part of', 'short' => 'part of', 'directed' => true],
                ['key' => 'is_a', 'label' => 'is a kind of', 'short' => 'is a', 'directed' => true],
                ['key' => 'associated_with', 'label' => 'is associated with', 'short' => 'assoc.', 'directed' => false],
                ['key' => 'contradicts', 'label' => 'is in tension with', 'short' => 'tension', 'directed' => false],
                ['key' => 'custom', 'label' => 'custom label…', 'short' => '', 'directed' => true],
            ],
            'memo_types' => [
                ['key' => 'analytic', 'label' => 'Memo', 'targets' => $all, 'template' => ''],
                ['key' => 'reflexive', 'label' => 'Reflexive note', 'targets' => $all,
                    'template' => "How my position, assumptions and reactions shape this analysis:\n"],
                ['key' => 'summary', 'label' => 'Summary', 'targets' => ['project', 'source', 'selection', 'case', 'concept'],
                    'template' => "What this is about:\n\nWhat matters for the research questions:\n\nOpen questions:\n"],
                ['key' => 'definition', 'label' => 'Definition', 'targets' => ['code', 'relation', 'concept', 'proposition'],
                    'template' => "What it captures:\n\nWhat it includes and excludes:\n\nTypical evidence:\n"],
                ['key' => 'decision', 'label' => 'Decision note', 'targets' => $all,
                    'template' => "Decision:\n\nReasons:\n\nAlternatives considered:\n"],
            ],
            'statuses' => [
                ['key' => 'draft', 'label' => 'Draft'],
                ['key' => 'developing', 'label' => 'Developing'],
                ['key' => 'stable', 'label' => 'Stable'],
                ['key' => 'retired', 'label' => 'Retired'],
            ],
            'code_fields' => [
                ['key' => 'origin', 'label' => 'Origin', 'type' => 'choice', 'options' => [
                    ['key' => 'a_priori', 'label' => 'Defined before coding'],
                    ['key' => 'emergent', 'label' => 'Developed from the data'],
                    ['key' => 'in_vivo', 'label' => "Participants' own words"],
                ]],
            ],
        ];
    }

    public static function for(Project|int|string $project): VocabularySet
    {
        $id = (string) ($project instanceof Project ? $project->id : $project);
        // cached for the current request only
        $request = app()->bound('request') ? app('request') : null;
        $cacheKey = 'qda.vocabulary.'.$id;
        if ($request?->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }
        $saved = ProjectVocabulary::where('project_id', $id)->first();
        $data = $saved?->data ?? self::defaults();
        $set = new VocabularySet(self::normalize(self::withUsedKeys($data, self::usage($id))));
        $request?->attributes->set($cacheKey, $set);

        return $set;
    }

    public static function forget(Project|int|string $project): void
    {
        $request = app()->bound('request') ? app('request') : null;
        $request?->attributes->remove('qda.vocabulary.'.($project instanceof Project ? $project->id : $project));
    }

    /**
     * How often each key is used in a project.
     *
     * @return array<string, array<string, int>>
     */
    public static function usage(Project|int|string $project): array
    {
        $id = $project instanceof Project ? $project->id : $project;
        $codes = Code::whereHas('codebook', fn ($q) => $q->where('project_id', $id))->get(['kind', 'meta']);
        $usage = array_fill_keys(self::SECTIONS, []);
        foreach ($codes as $code) {
            $kind = $code->kind ?: 'code';
            $usage['code_types'][$kind] = ($usage['code_types'][$kind] ?? 0) + 1;
            foreach ((array) $code->meta as $key => $value) {
                if ($key === 'status' && $value) {
                    $usage['statuses'][$value] = ($usage['statuses'][$value] ?? 0) + 1;
                } elseif (! in_array($key, CodeMeta::FIXED_FIELDS, true) && $value !== null && $value !== '' && $value !== false) {
                    $usage['code_fields'][$key] = ($usage['code_fields'][$key] ?? 0) + 1;
                }
            }
        }
        foreach (CodeRelation::where('project_id', $id)->selectRaw('type, count(*) as n')->groupBy('type')->get() as $row) {
            $usage['relation_types'][$row->type] = (int) $row->n;
        }
        foreach (Note::where('project_id', $id)->whereNotNull('memo_type')->selectRaw('memo_type, count(*) as n')->groupBy('memo_type')->get() as $row) {
            $usage['memo_types'][$row->memo_type] = (int) $row->n;
        }

        return $usage;
    }

    /**
     * Validation rules for saving a vocabulary.
     */
    public static function rules(): array
    {
        $entry = fn (string $section) => [
            "{$section}" => ['required', 'array', 'min:1', 'max:60'],
            "{$section}.*.key" => ['required', 'string', 'regex:'.self::KEY_PATTERN, 'distinct'],
            "{$section}.*.label" => ['required', 'string', 'max:80'],
            "{$section}.*.description" => ['nullable', 'string', 'max:500'],
            "{$section}.*.archived" => ['sometimes', 'boolean'],
        ];

        return array_merge(
            $entry('code_types'),
            $entry('relation_types'),
            [
                'relation_types.*.short' => ['nullable', 'string', 'max:40'],
                'relation_types.*.directed' => ['sometimes', 'boolean'],
                'relation_types.*.valence' => ['sometimes', 'boolean'],
            ],
            $entry('memo_types'),
            [
                'memo_types.*.template' => ['nullable', 'string', 'max:5000'],
                'memo_types.*.targets' => ['nullable', 'array'],
                'memo_types.*.targets.*' => ['string', 'in:'.implode(',', self::MEMO_TARGETS)],
            ],
            $entry('statuses'),
            ['code_fields' => ['present', 'array', 'max:30']],
            [
                'code_fields.*.key' => ['required', 'string', 'regex:'.self::KEY_PATTERN, 'distinct', 'not_in:'.implode(',', CodeMeta::FIXED_FIELDS)],
                'code_fields.*.label' => ['required', 'string', 'max:80'],
                'code_fields.*.description' => ['nullable', 'string', 'max:500'],
                'code_fields.*.archived' => ['sometimes', 'boolean'],
                'code_fields.*.type' => ['required', 'in:'.implode(',', self::FIELD_TYPES)],
                'code_fields.*.options' => ['nullable', 'array', 'max:30'],
                'code_fields.*.options.*.key' => ['required', 'string', 'regex:'.self::KEY_PATTERN],
                'code_fields.*.options.*.label' => ['required', 'string', 'max:80'],
            ],
        );
    }

    /**
     * Keys that are in use but missing from $data (they must be kept, at
     * most archived, so existing analysis keeps its meaning).
     *
     * @return array<string, string[]>
     */
    public static function missingInUse(array $data, array $usage): array
    {
        $missing = [];
        foreach (self::SECTIONS as $section) {
            $keys = array_column($data[$section] ?? [], 'key');
            $lost = array_values(array_diff(array_keys($usage[$section] ?? []), $keys));
            if ($section === 'code_types') {
                $lost = array_values(array_diff($lost, ['code']));
            }
            if ($lost) {
                $missing[$section] = $lost;
            }
        }

        return $missing;
    }

    /**
     * Fill defaults for optional attributes and drop unknown ones.
     */
    public static function normalize(array $data): array
    {
        $out = [];
        foreach (self::SECTIONS as $section) {
            $out[$section] = [];
            foreach ($data[$section] ?? [] as $entry) {
                $clean = [
                    'key' => (string) $entry['key'],
                    'label' => (string) $entry['label'],
                    'description' => (string) ($entry['description'] ?? ''),
                    'archived' => (bool) ($entry['archived'] ?? false),
                ];
                if ($section === 'relation_types') {
                    $clean['short'] = (string) ($entry['short'] ?? $entry['label']);
                    $clean['directed'] = (bool) ($entry['directed'] ?? true);
                    $clean['valence'] = (bool) ($entry['valence'] ?? false);
                } elseif ($section === 'memo_types') {
                    $clean['template'] = (string) ($entry['template'] ?? '');
                    $clean['targets'] = array_values(array_intersect($entry['targets'] ?? self::MEMO_TARGETS, self::MEMO_TARGETS)) ?: self::MEMO_TARGETS;
                } elseif ($section === 'code_fields') {
                    $clean['type'] = in_array($entry['type'] ?? 'text', self::FIELD_TYPES, true) ? $entry['type'] : 'text';
                    $clean['options'] = $clean['type'] === 'choice'
                        ? array_values(array_map(fn ($o) => ['key' => (string) $o['key'], 'label' => (string) $o['label']], $entry['options'] ?? []))
                        : [];
                }
                $out[$section][] = $clean;
            }
        }
        // the plain "code" type is always available
        if (! in_array('code', array_column($out['code_types'], 'key'), true)) {
            array_unshift($out['code_types'], ['key' => 'code', 'label' => 'Code', 'description' => '', 'archived' => false]);
        }
        if (! in_array('custom', array_column($out['relation_types'], 'key'), true)) {
            $out['relation_types'][] = ['key' => 'custom', 'label' => 'custom label…', 'short' => '', 'description' => '', 'archived' => false, 'directed' => true, 'valence' => false];
        }

        return $out;
    }

    /**
     * Keys that a project's data uses but its vocabulary lacks are added
     * back, so that existing codes, relations and memos stay valid.
     */
    private static function withUsedKeys(array $data, array $usage): array
    {
        foreach (self::SECTIONS as $section) {
            $have = array_column($data[$section] ?? [], 'key');
            foreach (array_diff(array_keys($usage[$section] ?? []), $have) as $key) {
                $label = ucfirst(str_replace('_', ' ', $key));
                $data[$section][] = $section === 'code_fields'
                    ? ['key' => $key, 'label' => $label, 'type' => 'text']
                    : ['key' => $key, 'label' => $label];
            }
        }

        return $data;
    }
}
