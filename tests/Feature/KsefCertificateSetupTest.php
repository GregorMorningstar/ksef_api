<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('authenticated users can view the KSeF certificate setup page', function () {
    $user = User::factory()->create();
    $user->ksefProfile()->create([
        'nip' => fake()->unique()->numerify('##########'),
        'storage_path' => 'ksef/users/' . $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('ksef.setup.create'))
        ->assertOk();
});

test('authenticated users can upload a KSeF certificate and private key', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $user->ksefProfile()->create([
        'nip' => fake()->unique()->numerify('##########'),
        'storage_path' => 'ksef/users/' . $user->id,
    ]);

    $response = $this->actingAs($user)->post(route('ksef.setup.store'), [
        'offline_certificate' => UploadedFile::fake()->create('offline_cert.pem', 2),
        'offline_private_key' => UploadedFile::fake()->create('offline_key.key', 2),
        'online_certificate' => UploadedFile::fake()->create('online_cert.pem', 2),
        'online_private_key' => UploadedFile::fake()->create('online_key.key', 2),
    ]);

    $user->refresh();
    $profile = $user->ksefProfile()->with('offlineCertificate', 'onlineCertificate')->first();

    expect($profile)->not->toBeNull();
    expect($profile->offlineCertificate)->not->toBeNull();
    expect($profile->onlineCertificate)->not->toBeNull();
    Storage::disk('local')->assertExists($profile->offlineCertificate->cert_path);
    Storage::disk('local')->assertExists($profile->offlineCertificate->key_path);
    Storage::disk('local')->assertExists($profile->onlineCertificate->cert_path);
    Storage::disk('local')->assertExists($profile->onlineCertificate->key_path);

    $response->assertRedirect(route('dashboard'));
});