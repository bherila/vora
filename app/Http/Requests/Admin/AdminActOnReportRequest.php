<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an admin action on an abuse report. Authorization (admin-only) is
 * enforced by the route middleware; this only checks the inputs.
 */
class AdminActOnReportRequest extends FormRequest
{
    /**
     * The actions an admin may take on a report. Item-scoped actions hide the
     * reported content (soft delete, retained for recovery); account-scoped
     * actions also act on the owning account.
     */
    public const ACTIONS = ['dismiss', 'delete_item', 'suspend_owner', 'legal_hold_owner'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(self::ACTIONS)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
