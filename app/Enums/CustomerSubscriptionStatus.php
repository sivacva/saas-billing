<?php

namespace App\Enums;

enum CustomerSubscriptionStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Canceled = 'canceled';
}
