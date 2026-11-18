<?php

namespace App\Http\Requests;

use App\Services\Qda\Vocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateCodeRelationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('relation'));
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', 'in:'.implode(',', Vocabulary::for($this->route('project'))->relationTypeKeys())],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'directed' => ['sometimes', 'boolean'],
            'valence' => ['sometimes', 'nullable', 'integer', 'in:-1,0,1'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
