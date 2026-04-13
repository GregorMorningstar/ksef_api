<?php

namespace App\Repositories;

use App\Models\KsefCertificate;
use App\Models\KsefProfile;
use App\Repositories\Contracts\KsefProfileRepositoryInterface;

class KsefProfileRepository implements KsefProfileRepositoryInterface
{
    public function findByNip(string $nip): ?KsefProfile
    {
        return KsefProfile::query()
            ->with(['user', 'offlineCertificate', 'onlineCertificate'])
            ->where('nip', $nip)
            ->first();
    }

    public function findByUserId(int $userId): ?KsefProfile
    {
        return KsefProfile::query()
            ->with(['user', 'offlineCertificate', 'onlineCertificate'])
            ->where('user_id', $userId)
            ->first();
    }

    public function upsertForUser(int $userId, string $nip, string $storagePath, string $authMethod = 'certificate'): KsefProfile
    {
        return KsefProfile::query()->updateOrCreate(
            ['user_id' => $userId],
            [
                'nip' => $nip,
                'storage_path' => $storagePath,
                'auth_method' => $authMethod,
            ],
        );
    }

    public function storeCertificate(KsefProfile $profile, array $attributes): KsefCertificate
    {
        $type = $attributes['type'] ?? 'offline';
        
        // Deactivate old certificates of the same type
        $profile->certificates()
            ->where('type', $type)
            ->where('active', true)
            ->update(['active' => false]);

        return $profile->certificates()->create([
            ...$attributes,
            'active' => true,
        ]);
    }
}
