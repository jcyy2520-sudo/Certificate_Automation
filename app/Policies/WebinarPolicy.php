<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Webinar;

class WebinarPolicy
{
    public function manage(User $user, Webinar $webinar): bool
    {
        return $user->canAccessPlatform()
            && ($user->isAdministrator()
                || ($user->role === User::USER_ROLE && $user->account_source !== 'admin_invitation' && (int) $webinar->created_by === (int) $user->id)
                || $user->assignedWebinars()->where('webinars.id', $webinar->id)->exists());
    }

    public function permission(User $user, Webinar $webinar, string $permission): bool
    {
        if (! $this->manage($user, $webinar)) {
            return false;
        }
        if ($user->isAdministrator() || ($user->role === User::USER_ROLE && $user->account_source !== 'admin_invitation' && (int) $webinar->created_by === (int) $user->id)) {
            return true;
        }
        if (! in_array($permission, ['manage_participants', 'manage_certificates', 'send_emails'], true)) {
            return false;
        }

        return $user->role === User::MANAGER_ROLE && $user->assignedWebinars()->where('webinars.id', $webinar->id)->wherePivot($permission, true)->exists();
    }
}
