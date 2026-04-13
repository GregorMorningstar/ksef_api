<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ksef_profile_id',
    'type',
    'cert_path',
    'key_path',
    'cert_filename',
    'key_filename',
    'active',
])]
class KsefCertificate extends Model
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(KsefProfile::class, 'ksef_profile_id');
    }

    /**
     * Get offline certificate for profile.
     */
    public static function offlineActive(int $profileId)
    {
        return self::where('ksef_profile_id', $profileId)
            ->where('type', 'offline')
            ->where('active', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Get online certificate for profile.
     */
    public static function onlineActive(int $profileId)
    {
        return self::where('ksef_profile_id', $profileId)
            ->where('type', 'online')
            ->where('active', true)
            ->orderByDesc('id')
            ->first();
    }
}