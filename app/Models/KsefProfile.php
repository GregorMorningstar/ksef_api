<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['user_id', 'nip', 'storage_path', 'auth_method', 'connected_at', 'last_ksef_status'])]
class KsefProfile extends Model
{
    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'last_ksef_status' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(KsefCertificate::class);
    }

    public function offlineCertificate(): HasOne
    {
        return $this->hasOne(KsefCertificate::class)
            ->where('type', 'offline')
            ->where('active', true)
            ->orderByDesc('id');
    }

    public function onlineCertificate(): HasOne
    {
        return $this->hasOne(KsefCertificate::class)
            ->where('type', 'online')
            ->where('active', true)
            ->orderByDesc('id');
    }

    /**
     * Check if profile has both offline and online certificates.
     */
    public function hasRequiredCertificates(): bool
    {
        return (bool) $this->offlineCertificate && (bool) $this->onlineCertificate;
    }
}
