<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Support\Dates;
use App\Support\Enums;

final class UserResource
{
    /** @return array<string,mixed> */
    public static function toArray(User $user, ?User $viewer = null): array
    {
        $out = [
            'id' => (int) $user->id,
            'ldap_uid' => $user->ldap_uid,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->displayName(),
            'role' => $user->role,
            'role_label' => Enums::ROLE_LABELS[$user->role] ?? $user->role,
            'role_locked' => (bool) $user->role_locked,
            'matricola' => $user->matricola,
            'course' => $user->course,
            'phone' => $user->phone,
            'is_active' => (bool) $user->is_active,
            'last_login_at' => Dates::iso($user->last_login_at),
            'created_at' => Dates::iso($user->created_at),
        ];
        $viewerIsStaff = $viewer !== null && $viewer->isStaff();
        if (!$viewerIsStaff) {
            unset($out['role_locked']);
        } else {
            $out['notes'] = $user->notes;
        }
        return $out;
    }

    /** Compact embedded form. @return array<string,mixed> */
    public static function mini(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'display_name' => $user->displayName(),
            'ldap_uid' => $user->ldap_uid,
        ];
    }
}
