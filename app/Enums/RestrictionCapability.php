<?php

namespace App\Enums;

enum RestrictionCapability: string
{
    case MediaUpload = 'media.upload';
    case MediaView = 'media.view';
    case CommentCreate = 'comment.create';

    public function label(): string
    {
        return match ($this) {
            self::MediaUpload => 'Media uploads',
            self::MediaView => 'Media viewing',
            self::CommentCreate => 'Commenting',
        };
    }
}
