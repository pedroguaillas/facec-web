<?php

use App\Mail\VerifyEmail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\EmisionPoint;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\PlanTypes;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Mail::fake();
});

function fakeSriResponse(array $body = ['nombreCompleto' => ' EMPRESA DE PRUEBA S.A. ']): void
{
    Http::fake([config('services.sri.url').'*' => Http::response($body)]);
}

/**
 * @return array<string, string>
 */
function registrationPayload(array $overrides = []): array
{
    fakeSriResponse();

    return array_merge([
        'ruc' => '1790012345001',
        'address' => 'Av. Amazonas N24-03, Quito',
        'phone' => '0999999999',
        'user' => 'nuevo_cliente',
        'email' => 'nuevo@cliente.ec',
        'password' => 'secreto123',
        'password_confirmation' => 'secreto123',
    ], $overrides);
}

test('el registro público crea empresa, usuario, sucursal, punto y prueba gratuita de 1 mes', function () {
    $this->postJson(route('register'), registrationPayload())
        ->assertCreated()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('email', 'nuevo@cliente.ec');

    $company = Company::where('ruc', '1790012345001')->firstOrFail();
    $user = User::where('user', 'nuevo_cliente')->firstOrFail();
    $branch = Branch::where('company_id', $company->id)->firstOrFail();
    $subscription = $company->currentSubscription();

    expect($company->company)->toBe('EMPRESA DE PRUEBA S.A.')
        ->and((int) $company->enviroment_type)->toBe(2)
        ->and((int) $company->decimal)->toBe(6)
        ->and($user->company->id)->toBe($company->id)
        ->and($user->hasRole('client'))->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and((int) $branch->store)->toBe(1)
        ->and($branch->type)->toBe('matriz')
        ->and($branch->address)->toBe('Av. Amazonas N24-03, Quito')
        ->and(EmisionPoint::where('branch_id', $branch->id)->count())->toBe(1)
        ->and(Customer::where('branch_id', $branch->id)->where('identication', '9999999999999')->exists())->toBeTrue()
        ->and($subscription->plan)->toBe(PlanTypes::GRATUITO)
        ->and($subscription->ended_at->toDateString())->toBe(now()->addMonth()->toDateString());

    Mail::assertSent(VerifyEmail::class, fn (VerifyEmail $mail) => $mail->hasTo('nuevo@cliente.ec'));
});

test('un RUC ya registrado no puede volver a pedir la prueba gratuita', function () {
    Company::factory()->create(['ruc' => '1790012345001']);

    $this->postJson(route('register'), registrationPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ruc');

    Mail::assertNothingSent();
});

test('un RUC que no existe en el SRI no se registra', function () {
    $payload = registrationPayload();
    // Http::fake() acumula stubs y gana el primero: se reemplaza el cliente para cambiar la respuesta.
    Http::swap(new HttpFactory);
    fakeSriResponse([]);

    $this->postJson(route('register'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ruc' => 'RUC no encontrado en los registros del SRI.']);

    expect(Company::where('ruc', '1790012345001')->exists())->toBeFalse()
        ->and(User::where('user', 'nuevo_cliente')->exists())->toBeFalse();
});

test('el usuario registrado no puede iniciar sesión hasta verificar el correo', function () {
    $this->postJson(route('register'), registrationPayload())->assertCreated();

    $this->postJson('/api/login', ['user' => 'nuevo_cliente', 'password' => 'secreto123'])
        ->assertForbidden()
        ->assertJsonPath('error', 'email_unverified')
        ->assertJsonMissingPath('token');

    $user = User::where('user', 'nuevo_cliente')->firstOrFail();
    $verifyUrl = URL::temporarySignedRoute('verification.verify', now()->addDay(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->get($verifyUrl)->assertRedirect(rtrim(config('app.frontend_url'), '/').'/login?verified=1');

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    $this->postJson('/api/login', ['user' => 'nuevo_cliente', 'password' => 'secreto123'])
        ->assertOk()
        ->assertJsonPath('permissions.plan', PlanTypes::GRATUITO)
        ->assertJsonPath('permissions.financiero', true);
});

test('el enlace de verificación sin firma válida o con hash ajeno no verifica', function () {
    $this->postJson(route('register'), registrationPayload())->assertCreated();
    $user = User::where('user', 'nuevo_cliente')->firstOrFail();

    $this->get("/api/email/verify/{$user->id}/".sha1($user->email))->assertForbidden();

    $wrongHash = URL::temporarySignedRoute('verification.verify', now()->addDay(), ['id' => $user->id, 'hash' => sha1('otro@correo.ec')]);
    $this->get($wrongHash)->assertRedirect(rtrim(config('app.frontend_url'), '/').'/login?verified=0');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('reenviar verificación responde igual exista o no el usuario', function () {
    $this->postJson(route('register'), registrationPayload())->assertCreated();
    Mail::fake();

    $this->postJson(route('verification.resend'), ['user' => 'nuevo_cliente'])->assertOk();
    $this->postJson(route('verification.resend'), ['user' => 'no_existe'])->assertOk();

    Mail::assertSent(VerifyEmail::class, 1);
});

test('el usuario que crea el admin queda verificado', function () {
    $adminType = UserType::firstOrCreate(['type' => 'admin']);
    $clientType = UserType::firstOrCreate(['type' => 'client']);
    $this->actingAs(User::factory()->create(['user_type_id' => $adminType->id]));

    $this->postJson(route('admin.companies.store'), [
        'ruc' => '1790099999001',
        'company' => 'Empresa Admin',
        'user' => 'creado_admin',
        'email' => 'admin@creado.ec',
        'password' => 'secreto',
        'plan' => PlanTypes::GRATUITO,
        'user_type_id' => $clientType->id,
    ])->assertCreated();

    expect(User::where('user', 'creado_admin')->value('email_verified_at'))->not->toBeNull();
});

test('la consulta pública del SRI devuelve solo la razón social', function () {
    fakeSriResponse();

    $this->getJson(route('register.sri', '1790012345001'))
        ->assertOk()
        ->assertExactJson(['ruc' => '1790012345001', 'company' => 'EMPRESA DE PRUEBA S.A.']);
});

test('la consulta pública del SRI valida el RUC igual que el registro', function (string $ruc, string $message) {
    fakeSriResponse([]);
    Company::factory()->create(['ruc' => '1790099999001']);

    $this->getJson(route('register.sri', $ruc))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ruc' => $message]);
})->with([
    'formato inválido' => ['12345', 'El RUC debe tener exactamente 13 dígitos numéricos.'],
    'ya registrado' => ['1790099999001', 'Este RUC ya está registrado.'],
    'no existe en el SRI' => ['1790012345001', 'RUC no encontrado en los registros del SRI.'],
]);
