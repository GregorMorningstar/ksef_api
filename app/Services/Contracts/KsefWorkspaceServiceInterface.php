<?php

namespace App\Services\Contracts;

use App\Models\KsefCertificate;
use App\Models\KsefProfile;
use App\Models\User;

interface KsefWorkspaceServiceInterface
{
    public function findProfileByNip(string $nip): ?KsefProfile;

    public function findProfileForUser(User $user): ?KsefProfile;

    public function provisionForUser(User $user, string $nip): KsefProfile;

    public function storeCertificates(User $user, array $offlineFiles, array $onlineFiles): void;

    public function updateCertificate(User $user, array $files, string $type): void;

    public function deleteCertificate(User $user, string $type): void;

    public function ensureWorkspace(User $user): string;
}
