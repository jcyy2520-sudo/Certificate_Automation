<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Webinar;

class WebinarPolicy
{
    public function manage(User $user, Webinar $webinar): bool
    {
        return $user->canAccessPlatform()
            && ($user->isAdministrator() || (int) $webinar->created_by === (int) $user->id);
    }
}
