<?php

namespace App\Http\Requests\Events;

use App\Http\Requests\Media\StoreMediaRequest;
use App\Models\Event;
use App\Services\Events\EventAccess;

class EventUploadRequest extends StoreMediaRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        $event = $this->route('event');
        abort_unless($event instanceof Event && app(EventAccess::class)->canView($this->user(), $event), 404, 'Not found.');

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['audience' => 'everyone', 'audience_user_ids' => [], 'discoverable' => false, 'announce' => false]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'character_id' => ['prohibited'], 'event_id' => ['prohibited'], 'event_ulid' => ['prohibited'],
            'purpose' => ['prohibited'], 'collection_ulid' => ['prohibited'], 'interest_ids' => ['prohibited'],
            'photo_metadata' => ['sometimes', 'array:width,height,has_gps,has_capture_time,has_camera', 'prohibited_unless:type,photo'],
            'photo_metadata.width' => ['nullable', 'required_with:photo_metadata.height', 'integer', 'min:1', 'max:100000'],
            'photo_metadata.height' => ['nullable', 'required_with:photo_metadata.width', 'integer', 'min:1', 'max:100000'],
            'photo_metadata.has_gps' => ['sometimes', 'boolean'],
            'photo_metadata.has_capture_time' => ['sometimes', 'boolean'],
            'photo_metadata.has_camera' => ['sometimes', 'boolean'],
        ];
    }
}
