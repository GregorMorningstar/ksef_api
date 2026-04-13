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

    public function updateCertificate(User $user, array $files, string $type): void
    {
        $profile = $this->findProfileForUser($user);

        if (!$profile) {
            throw new \RuntimeException('User does not have a KSeF profile.');
        }

        $storagePath = $this->ensureWorkspace($user);
        $this->storeCertificateType($profile, $storagePath, $files, $type);
    }

    public function deleteCertificate(User $user, string $type): void
    {
        $profile = $this->findProfileForUser($user);

        if (!$profile) {
            throw new \RuntimeException('User does not have a KSeF profile.');
        }

        $certificates = $profile->certificates()
            ->where('type', $type)
            ->get();

        foreach ($certificates as $certificate) {
            Storage::disk('local')->delete(array_filter([
                $certificate->cert_path,
                $certificate->key_path,
            ]));
        }

        $profile->certificates()
            ->where('type', $type)
            ->delete();
    }

    private function storeCertificateType(KsefProfile $profile, string $storagePath, array $files, string $type): void
    {
        $certSourcePath = $files['cert_path'];
        $keySourcePath = $files['key_path'];
        $originalCertName = $files['cert_name'];
        $originalKeyName = $files['key_name'];

        // Delete old files from disk before replacing
        $oldCert = $profile->certificates()
            ->where('type', $type)
            ->where('active', true)
            ->first();

        if ($oldCert) {
            Storage::disk('local')->delete(array_filter([
                $oldCert->cert_path,
                $oldCert->key_path,
            ]));
        }

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

        $storagePath = $this->buildStoragePath($user->id);

        if ($profile->storage_path !== $storagePath) {
            $profile->forceFill([
                'storage_path' => $storagePath,
            ])->save();
        }

        Storage::disk('local')->makeDirectory($storagePath);

        return $storagePath;
    }

    private function buildStoragePath(int $userId): string
    {
        return 'ksef/certificate/users/' . $userId;
    }
}
