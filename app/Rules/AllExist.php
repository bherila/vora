<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * One whereIn query for every id in an array, instead of the per-element query
 * an `exists` rule on `field.*` runs. Missing ids are reported on their own
 * element (`field.3`, or `field.3.key` for arrays of objects), as `exists`
 * would. Non-integer elements are left to the element's own `integer` rule.
 */
final class AllExist implements ValidationRule, ValidatorAwareRule
{
    private ?Validator $validator = null;

    /**
     * @param  (Closure(Builder): mixed)|null  $scope
     */
    public function __construct(
        private readonly string $table,
        private readonly string $column = 'id',
        private readonly ?string $key = null,
        private readonly ?Closure $scope = null,
    ) {}

    public function setValidator(mixed $validator): static
    {
        $this->validator = $validator instanceof Validator ? $validator : null;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        /** @var array<int|string, int> $ids */
        $ids = [];
        foreach ($value as $index => $item) {
            $id = $this->key === null ? $item : (is_array($item) ? ($item[$this->key] ?? null) : null);
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[$index] = (int) $id;
            }
        }

        if ($ids === []) {
            return;
        }

        $query = DB::table($this->table)->whereIn($this->column, array_values(array_unique($ids)));
        if ($this->scope !== null) {
            ($this->scope)($query);
        }
        $found = array_flip(array_map('intval', $query->pluck($this->column)->all()));

        foreach ($ids as $index => $id) {
            if (isset($found[$id])) {
                continue;
            }

            $path = $attribute.'.'.$index.($this->key === null ? '' : '.'.$this->key);
            $message = 'The selected '.str_replace('_', ' ', $this->key ?? $attribute).' is invalid.';
            if ($this->validator !== null) {
                $this->validator->errors()->add($path, $message);
            } else {
                $fail($message);
            }
        }
    }
}
