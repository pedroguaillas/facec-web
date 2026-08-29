<?php

use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\Hash;

function createAuthenticatedUser(string $password = 'old-password'): array
{
    $userType = UserType::firstOrCreate(['type' => 'client']);

    $user = User::factory()->create(['user_type_id' => $userType->id, 'password' => Hash::make($password)]);
    $newToken = $user->createToken('test');

    return [$user, $newToken->plainTextToken, $newToken->accessToken->id];
}

test('cambia la contraseña con current_password correcto y revoca otros tokens', function () {
    [$user, $token, $currentTokenId] = createAuthenticatedUser();
    $user->createToken('other');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('password.change'), [
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

    $response->assertOk()->assertJson(['succes' => true]);

    expect(Hash::check('new-password-123', $user->fresh()->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->first()->id)->toBe($currentTokenId);
});

test('rechaza current_password incorrecto', function () {
    [$user, $token] = createAuthenticatedUser();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('password.change'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('current_password');
    expect(Hash::check('old-password', $user->fresh()->password))->toBeTrue();
});

test('rechaza confirmación de contraseña que no coincide', function () {
    [, $token] = createAuthenticatedUser();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson(route('password.change'), [
            'current_password' => 'old-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'otra-cosa',
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors('password');
});
