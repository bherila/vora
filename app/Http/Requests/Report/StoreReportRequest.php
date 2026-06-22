<?php

namespace App\Http\Requests\Report;

use App\Enums\ReportReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an abuse report. Whether the reporter may actually see (and so
 * report) the target is enforced in the controller against the item's own
 * privacy — this only checks the inputs are well formed.
 */
class StoreReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->isApproved() && $user->canLogin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['media', 'story', 'post'])],
            'id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', Rule::in(ReportReason::values())],
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
