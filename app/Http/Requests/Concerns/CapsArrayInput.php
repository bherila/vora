<?php

namespace App\Http\Requests\Concerns;

/**
 * Validation runs every `field.*` rule for every element, and `distinct` is
 * quadratic, so an oversized array costs work before its own `max:` rule
 * rejects it. Validate a copy where each array with a `max:N` rule keeps only
 * N + 1 elements: the `max:` rule still fails with its usual message, and the
 * element rules never see more than that. Inputs within the limit are untouched.
 */
trait CapsArrayInput
{
    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->all();

        foreach ($this->container->call([$this, 'rules']) as $key => $rules) {
            if (str_contains((string) $key, '*')) {
                continue;
            }

            $limit = self::arrayLimit(is_string($rules) ? explode('|', $rules) : (array) $rules);
            $value = data_get($data, $key);
            if ($limit !== null && is_array($value) && count($value) > $limit + 1) {
                data_set($data, $key, array_slice($value, 0, $limit + 1, true));
            }
        }

        return $data;
    }

    /**
     * @param  array<int|string, mixed>  $rules
     */
    private static function arrayLimit(array $rules): ?int
    {
        if (! in_array('array', $rules, true)) {
            return null;
        }

        foreach ($rules as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $match) === 1) {
                return (int) $match[1];
            }
        }

        return null;
    }
}
