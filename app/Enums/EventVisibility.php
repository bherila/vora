<?php

namespace App\Enums;

enum EventVisibility: string
{
    case Everyone = 'everyone';
    case Specific = 'specific';
}
