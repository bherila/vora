<?php

$profile = env('APP_PROFILE', 'vora');
if (! in_array($profile, ['vora', 'event'], true)) {
    throw new InvalidArgumentException('APP_PROFILE must be vora or event.');
}

$event = $profile === 'event';
$module = static function (string $name, bool $default): bool {
    $value = filter_var(env('MODULE_'.strtoupper($name).'_ENABLED', $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($value === null) {
        throw new InvalidArgumentException('Module settings must be boolean values.');
    }

    return $value;
};

return [
    'profile' => $profile,
    'modules' => [
        'events' => $module('events', $event),
        'personas' => $module('personas', ! $event),
        'stories' => $module('stories', ! $event),
        'chat' => $module('chat', ! $event),
        'community' => $module('community', ! $event),
        'profiles' => $module('profiles', ! $event),
        'interests' => $module('interests', ! $event),
        'library' => $module('library', true),
        'favorites' => $module('favorites', true),
    ],
];
