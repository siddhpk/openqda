<?php

namespace App\Listeners;

use App\Services\Desktop\Channel;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\MustVerifyEmail;

/**
 * The laptop edition has no mail server, so confirmation emails could never
 * arrive. Accounts are confirmed on registration instead; access is already
 * limited by the host's access code and by project membership.
 */
class VerifyDesktopRegistration
{
    public function handle(Registered $event): void
    {
        if (Channel::desktop() && $event->user instanceof MustVerifyEmail && ! $event->user->hasVerifiedEmail()) {
            $event->user->markEmailAsVerified();
        }
    }
}
