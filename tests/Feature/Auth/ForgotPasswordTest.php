<?php

use App\Mail\PasswordReset;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\Mail;

test('envía el correo de recuperación y devuelve el email enmascarado cuando el usuario existe', function () {
    Mail::fake();
    $userType = UserType::firstOrCreate(['type' => 'client']);
    $user = User::factory()->create(['user_type_id' => $userType->id, 'user' => 'jdoe', 'email' => 'pier_surdito@hotmail.com']);

    $response = $this->postJson(route('password.forgot'), ['user' => 'jdoe']);

    $response->assertOk()->assertJson(['succes' => true, 'email' => 'pi***o@hotmail.com']);
    Mail::assertSent(PasswordReset::class, fn ($mail) => $mail->hasTo($user->email));
});

test('rechaza username inexistente con 422', function () {
    Mail::fake();

    $response = $this->postJson(route('password.forgot'), ['user' => 'no-existe']);

    $response->assertStatus(422)->assertJsonValidationErrors('user');
    Mail::assertNotSent(PasswordReset::class);
});

test('user es obligatorio', function () {
    $response = $this->postJson(route('password.forgot'), []);

    $response->assertStatus(422)->assertJsonValidationErrors('user');
});
