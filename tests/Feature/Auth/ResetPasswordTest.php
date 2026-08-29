<?php

use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

test('resetea la contraseña con token válido y revoca tokens existentes', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $user = User::factory()->create(['user_type_id' => $userType->id, 'user' => 'jdoe', 'password' => Hash::make('old-password')]);
    $user->createToken('stale');
    $token = Password::createToken($user);

    $response = $this->postJson(route('password.reset'), [
        'user' => 'jdoe',
        'token' => $token,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertOk()->assertJson(['succes' => true]);
    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
});

test('rechaza token inválido', function () {
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $user = User::factory()->create(['user_type_id' => $userType->id, 'user' => 'jdoe']);

    $response = $this->postJson(route('password.reset'), [
        'user' => 'jdoe',
        'token' => 'token-invalido',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('token');
});

test('rechaza username inexistente', function () {
    $response = $this->postJson(route('password.reset'), [
        'user' => 'no-existe',
        'token' => 'cualquiera',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('token');
});
