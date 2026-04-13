<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();
    $nip = fake()->unique()->numerify('##########');

    $user->ksefProfile()->create([
        'nip' => $nip,
        'storage_path' => 'ksef/users/' . $user->id,
    ]);

    $response = $this->post(route('login.store'), [
        'nip' => $nip,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
    $this->get(route('dashboard'))->assertRedirect(route('ksef.setup.create'));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();
    $nip = fake()->unique()->numerify('##########');

    $user->ksefProfile()->create([
        'nip' => $nip,
        'storage_path' => 'ksef/users/' . $user->id,
    ]);

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->post(route('login.store'), [
        'nip' => $nip,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();
    $nip = fake()->unique()->numerify('##########');

    $user->ksefProfile()->create([
        'nip' => $nip,
        'storage_path' => 'ksef/users/' . $user->id,
    ]);

    $this->post(route('login.store'), [
        'nip' => $nip,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect(route('home'));
});

test('users are rate limited', function () {
    $user = User::factory()->create();
    $nip = fake()->unique()->numerify('##########');

    $user->ksefProfile()->create([
        'nip' => $nip,
        'storage_path' => 'ksef/users/' . $user->id,
    ]);

    $throttleKey = Str::transliterate(Str::lower($nip).'|127.0.0.1');
    RateLimiter::increment($throttleKey, amount: 5);

    expect(RateLimiter::tooManyAttempts($throttleKey, 5))->toBeTrue();

    $response = $this->from(route('login'))->post(route('login.store'), [
        'nip' => $nip,
        'password' => 'wrong-password',
    ]);

    $response
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['nip']);
});
