<?php

namespace App\Http\Requests\Admin;

use App\Models\InterestRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminInterestRequestCrudRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->isAdmin() && $user->isApproved() && $user->canLogin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var InterestRequest|null $requestRecord */
        $requestRecord = $this->route('interestRequest');
        $requestId = is_int($requestRecord?->id) ? (int) $requestRecord->id : 0;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('interests', 'name'),
                Rule::unique('interest_requests', 'name')
                    ->ignore($requestId)
                    ->where(function ($query) {
                        return $query->where('status', InterestRequest::STATUS_PENDING);
                    }),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_interest_id' => ['nullable', 'integer', Rule::exists('interests', 'id')],
        ];
    }
}
