<?php

namespace App\Services;

use App\Models\KsefCertificate;
use App\Models\KsefProfile;
use App\Models\User;
use App\Repositories\Contracts\KsefProfileRepositoryInterface;
use App\Services\Contracts\KsefWorkspaceServiceInterface;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class KsefWorkspaceService implements KsefWorkspaceServiceInterface
{
    public function __construct(
        private readonly KsefProfileRepositoryInterface $repository,
    ) {}

    public function findProfileByNip(string $nip): ?KsefProfile
    {
        return $this->repository->findByNip($nip);
    }

    public function findProfileForUser(User $user): ?KsefProfile
    {
        return $this->repository->findByUserId($user->id);
    }

    public function provisionForUser(User $user, string $nip): KsefProfile
    {
        $storagePath = $this->buildStoragePath($user->id);

        Storage::disk('local')->makeDirectory($storagePath);

        return $this->repository->upsertForUser($user->id, $nip, $storagePath);
    }

    public function storeCertificates(User $user, array $offlineFiles, array $onlineFiles): void
    {
        $profile = $this->findProfileForUser($user);

        if (!$profile) {
            throw new \RuntimeException('User does not have a KSeF profile.');
        }

        $storagePath = $this->ensureWorkspace($user);

        // Store offline certificate
        $this->storeCertificateType($profile, $storagePath, $offlineFiles, 'offline');

        // Store online certificate
        $this->storeCertificateType($profile, $storagePath, $onlineFiles, 'online');
    }

    private function storeCertificateType(KsefProfile $profile, string $storagePath, array $files, string $type): void
    {
        $certSourcePath = $files['cert_path'];
        $keySourcePath = $files['key_path'];
        $originalCertName = $files['cert_name'];
        $originalKeyName = $files['key_name'];

        $certExtension = pathinfo($originalCertName, PATHINFO_EXTENSION) ?: 'crt';
        $keyExtension = pathinfo($originalKeyName, PATHINFO_EXTENSION) ?: 'key';
        $certFileName = $type . '_cert_' . Str::uuid() . '.' . $certExtension;
        $keyFileName = $type . '_key_' . Str::uuid() . '.' . $keyExtension;
        $certDestination = $storagePath . '/' . $certFileName;
        $keyDestination = $storagePath . '/' . $keyFileName;

        Storage::disk('local')->put($certDestination, file_get_contents($certSourcePath));
        Storage::disk('local')->put($keyDestination, file_get_contents($keySourcePath));

        $this->repository->storeCertificate($profile, [
            'type' => $type,
            'cert_path' => $certDestination,
            'key_path' => $keyDestination,
            'cert_filename' => $originalCertName,
            'key_filename' => $originalKeyName,
        ]);
    }

    public function ensureWorkspace(User $user): string
    {
        $profile = $this->findProfileForUser($user);

        if (!$profile) {
            throw new \RuntimeException('User does not have a KSeF profile.');
        }

        Storage::disk('local')->makeDirectory($profile->storage_path);

        return $profile->storage_path;
    }

    private function buildStoragePath(int $userId): string
    {
        return 'ksef/users/' . $userId;
    }
}
