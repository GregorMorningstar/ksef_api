<?php

namespace App\Repositories\Contracts;

use App\Models\KsefCertificate;
use App\Models\KsefProfile;

interface KsefProfileRepositoryInterface
{
    public function findByNip(string $nip): ?KsefProfile;

    public function findByUserId(int $userId): ?KsefProfile;

    public function upsertForUser(int $userId, string $nip, string $storagePath, string $authMethod = 'certificate'): KsefProfile;

    public function storeCertificate(KsefProfile $profile, array $attributes): KsefCertificate;
}
