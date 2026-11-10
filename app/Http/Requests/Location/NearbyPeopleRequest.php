<?php

namespace App\Http\Requests\Location;

use App\Http\Controllers\Location\NearbyPeopleController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NearbyPeopleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'radius' => ['sometimes', 'integer', Rule::in(NearbyPeopleController::RADII_KM)],
        ];
    }
}
