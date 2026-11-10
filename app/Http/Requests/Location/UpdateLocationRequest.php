<?php

namespace App\Http\Requests\Location;

use App\Enums\LocationAudience;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
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
            'place_id' => ['present', 'nullable', 'integer', Rule::exists('places', 'id')],
            'country_audience' => ['required', Rule::enum(LocationAudience::class)],
            'city_audience' => ['required', Rule::enum(LocationAudience::class)],
        ];
    }

    /**
     * A city names its country, so the city may never reach more people than
     * the country does.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $country = LocationAudience::tryFrom((string) $this->input('country_audience'));
                $city = LocationAudience::tryFrom((string) $this->input('city_audience'));
                if ($country !== null && $city !== null && $city->rank() > $country->rank()) {
                    $validator->errors()->add('city_audience', 'Your city cannot be shown to more people than your country.');
                }
            },
        ];
    }
}
