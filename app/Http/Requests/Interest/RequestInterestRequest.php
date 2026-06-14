<?php

namespace App\Http\Requests\Interest;

use App\Models\Interest;
use App\Models\InterestRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->isApproved() && $user->canLogin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('interests', 'name'),
                Rule::unique('interest_requests', 'name')->where(function ($query) {
                    return $query->where('status', InterestRequest::STATUS_PENDING);
                }),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_interest_id' => ['nullable', 'integer', Rule::exists('interests', 'id')],
        ];
    }
}
