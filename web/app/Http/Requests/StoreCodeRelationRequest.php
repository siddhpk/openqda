<?php

namespace App\Http\Requests;

use App\Models\Code;
use App\Models\CodeRelation;
use App\Models\Project;
use App\Services\Qda\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreCodeRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', [CodeRelation::class, $this->route('project')]);
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'uuid'],
            'to' => ['required', 'uuid', 'different:from'],
            'type' => ['required', 'string', 'in:'.implode(',', Vocabulary::for($this->route('project'))->relationTypeKeys())],
            'label' => ['nullable', 'string', 'max:120', 'required_if:type,custom'],
            'directed' => ['sometimes', 'boolean'],
            'valence' => ['nullable', 'integer', 'in:-1,0,1'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Whether a relationship is directed comes from its type in the project
     * vocabulary; only custom relationships choose for themselves.
     */
    public static function directedFor(Project $project, string $type, mixed $requested): bool
    {
        if ($type === 'custom') {
            return $requested === null ? true : filter_var($requested, FILTER_VALIDATE_BOOLEAN);
        }

        return Vocabulary::for($project)->isDirected($type);
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                /** @var Project $project */
                $project = $this->route('project');
                $codebookIds = $project->codebooks()->pluck('id');
                $found = Code::whereIn('id', [$this->input('from'), $this->input('to')])
                    ->whereIn('codebook_id', $codebookIds)
                    ->count();
                if ($found !== 2) {
                    $validator->errors()->add('from', 'Both codes must belong to this project.');

                    return;
                }
                $type = (string) $this->input('type');
                $key = CodeRelation::dedupeKey(
                    (string) $this->input('from'),
                    (string) $this->input('to'),
                    $type,
                    $this->input('label'),
                    self::directedFor($project, $type, $this->input('directed'))
                );
                if (CodeRelation::where('project_id', $project->id)->where('dedupe_key', $key)->exists()) {
                    $validator->errors()->add('type', 'This relationship already exists.');
                }
            },
        ];
    }
}
