<?php

namespace App\Http\Requests;

use App\Models\Note;
use App\Services\Qda\NoteTargets;
use App\Services\Qda\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return Gate::allows('create', [Note::class, $project]);
    }

    public function rules(): array
    {
        return [
            'content' => 'required|string',
            'target' => 'required|string|max:255',
            'type' => 'required|string|in:project,source,selection,code,case,relation,concept,proposition',
            'memo_type' => 'nullable|string|in:'.implode(',', Vocabulary::for($this->route('project'))->memoTypeKeys()),
            'scope' => 'nullable|string|max:255',
            'visibility' => 'integer|in:0,1',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $project = $this->route('project');
                $type = (string) $this->input('type');
                if (! NoteTargets::exists($project, $type, (string) $this->input('target'))) {
                    $validator->errors()->add('target', 'The memo must refer to something in this project.');
                }
                if (! NoteTargets::memoTypeAllows($project, $this->input('memo_type'), $type)) {
                    $validator->errors()->add('memo_type', 'This memo type is not available here. Change its targets on Research design → Vocabulary.');
                }
            },
        ];
    }
}
