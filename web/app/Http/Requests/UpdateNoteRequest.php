<?php

namespace App\Http\Requests;

use App\Services\Qda\NoteTargets;
use App\Services\Qda\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class UpdateNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $note = $this->route('note');

        return Gate::allows('update', $note);
    }

    public function rules(): array
    {
        return [
            'content' => 'sometimes|required|string',
            'visibility' => 'sometimes|integer|in:0,1',
            'memo_type' => 'sometimes|nullable|string|in:'.implode(',', Vocabulary::for($this->route('project'))->memoTypeKeys()),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $note = $this->route('note');
                if ($validator->errors()->isNotEmpty() || ! $this->has('memo_type') || ! $note) {
                    return;
                }
                if (! NoteTargets::memoTypeAllows($this->route('project'), $this->input('memo_type'), (string) $note->type)) {
                    $validator->errors()->add('memo_type', 'This memo type is not available here. Change its targets on Research design → Vocabulary.');
                }
            },
        ];
    }
}
