<?php

namespace App\Http\Requests\Location;

use App\Enums\LocationAudience;
use App\Services\Location\PlaceDirectory;
use Closure;
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
            'place_id' => ['present', 'nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
                // A city from the current list, or the one already saved (which
                // may have been kept after a later import dropped it).
                if ($value === null || (int) $value === $this->user()?->place_id) {
                    return;
                }
                if (! app(PlaceDirectory::class)->isOffered((int) $value)) {
                    $fail('That city is not in the list any more. Choose another.');
                }
            }],
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
