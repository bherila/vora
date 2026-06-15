<?php

namespace App\Support;

use App\Enums\MediaType;
use App\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The user-supplied listing filters shared by every media listing — the owner's
 * library today and cross-user exploration/search tomorrow. Holding them in one
 * value object (and applying them in one place) keeps the two listings from
 * drifting: a new filter is added here once and both surfaces gain it.
 *
 * This object carries ONLY the discovery filters (type, interests). It never
 * carries the privacy/ownership scoping — that is the caller's responsibility
 * (own rows vs. visibleTo + approved) and is intentionally kept separate.
 */
class MediaFilter
{
    /**
     * @param  list<int>  $interestIds
     */
    public function __construct(
        public readonly ?MediaType $type = null,
        public readonly array $interestIds = [],
    ) {}

    /**
     * Build the filter from validated request input. Unknown/blank values fall
     * back to "no filter" so the same constructor serves every listing.
     */
    public static function fromRequest(Request $request): self
    {
        $typeValue = $request->input('type');
        $type = is_string($typeValue) && in_array($typeValue, MediaType::values(), true)
            ? MediaType::from($typeValue)
            : null;

        $interestIds = array_values(array_unique(array_map(
            'intval',
            (array) $request->input('interest_ids', []),
        )));

        return new self($type, $interestIds);
    }

    /**
     * Apply the discovery filters to a query already scoped for privacy by the
     * caller. Each underlying scope is a no-op when its value is absent.
     *
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function applyTo(Builder $query): Builder
    {
        return $query
            ->ofType($this->type)
            ->withAnyInterest($this->interestIds);
    }
}
