<?php

namespace App\Services\Mail;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RequesterResolutionService
{
    public function resolveOrCreate(int $tenantId, string $email, ?string $displayName = null, ?int $organizationId = null): User
    {
        $normalizedEmail = Str::lower(trim($email));

        $existing = User::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();

        if ($existing instanceof User) {
            return $existing;
        }

        return User::query()->create([
            'tenant_id' => $tenantId,
            'organization_id' => $organizationId,
            'name' => $displayName !== null && trim($displayName) !== '' ? trim($displayName) : $normalizedEmail,
            'email' => $normalizedEmail,
            'password' => Hash::make(Str::random(32)),
            'type' => 'end_user',
        ]);
    }
}
