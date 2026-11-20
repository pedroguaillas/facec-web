<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Models\UserType;
use App\StaticClasses\VoucherTypes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');

    $userType = UserType::firstOrCreate(['type' => 'client']);
    $this->company = Company::factory()->create(['ruc' => '0102030405001', 'logo_dir' => '0102030405001.png']);
    $this->branch = Branch::factory()->for($this->company)->create(['store' => 2, 'type' => 'sucursal']);
    $user = User::factory()->create(['user_type_id' => $userType->id]);
    CompanyUser::create(['user_id' => $user->id, 'level' => 1, 'level_id' => $this->company->id]);
    $this->actingAs($user);
});

test('al editar el establecimiento se guarda su propio logo', function () {
    $this->put(route('settings.branches.update', $this->branch), ['logo' => UploadedFile::fake()->image('logo.png')])
        ->assertOk()
        ->assertJsonPath('logo_dir', '0102030405001-002.png')
        ->assertJsonPath('logo_url', Storage::disk('public')->url('logos/0102030405001-002.png'));

    Storage::disk('public')->assertExists('logos/0102030405001-002.png');
    expect($this->branch->fresh()->logo_dir)->toBe('0102030405001-002.png');
});

test('reemplazar el logo con otra extension borra el anterior', function () {
    $this->put(route('settings.branches.update', $this->branch), ['logo' => UploadedFile::fake()->image('logo.png')])->assertOk();
    $this->put(route('settings.branches.update', $this->branch), ['logo' => UploadedFile::fake()->image('logo.jpg')])->assertOk();

    Storage::disk('public')->assertMissing('logos/0102030405001-002.png');
    Storage::disk('public')->assertExists('logos/0102030405001-002.jpg');
});

test('al crear un establecimiento se puede subir su logo', function () {
    $this->post(route('settings.branches.store'), [
        'store' => 3,
        'address' => 'Av. Sur 300',
        'type' => 'sucursal',
        'logo' => UploadedFile::fake()->image('logo.png'),
    ])->assertCreated()->assertJsonPath('logo_dir', '0102030405001-003.png');

    Storage::disk('public')->assertExists('logos/0102030405001-003.png');
});

test('el logo debe ser una imagen', function () {
    $this->put(route('settings.branches.update', $this->branch), ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')])
        ->assertUnprocessable()->assertJsonValidationErrors('logo');
});

test('el PDF usa el logo del establecimiento, si no el de la empresa y si no el de defecto', function () {
    expect($this->branch->logoFile())->toBe('0102030405001.png');

    $this->branch->update(['logo_dir' => '0102030405001-002.png']);
    expect($this->branch->fresh()->logoFile())->toBe('0102030405001-002.png');

    $this->company->update(['logo_dir' => null]);
    $withoutLogos = Branch::factory()->for($this->company)->create(['store' => 4]);
    expect($withoutLogos->logoFile())->toBe('default.png');
});

test('el RIDE muestra el logo del establecimiento emisor', function () {
    $this->branch->update(['logo_dir' => '0102030405001-002.png']);

    $html = view('vouchers.theme.company', ['company' => $this->company, 'branch' => $this->branch->fresh(), 'voucherTypeSlug' => VoucherTypes::VENTA_FACTURA])->render();

    expect($html)->toContain('logos/0102030405001-002.png');
});

test('quitar el logo borra el archivo y vuelve al de la empresa', function () {
    $this->put(route('settings.branches.update', $this->branch), ['logo' => UploadedFile::fake()->image('logo.png')])->assertOk();

    $this->deleteJson(route('settings.branches.logo.destroy', $this->branch))
        ->assertOk()
        ->assertJsonPath('logo_dir', null)
        ->assertJsonPath('logo_url', null);

    Storage::disk('public')->assertMissing('logos/0102030405001-002.png');
    expect($this->branch->fresh()->logoFile())->toBe('0102030405001.png');
});

test('no se puede quitar el logo de un establecimiento de otra empresa', function () {
    $otherBranch = Branch::factory()->for(Company::factory())->create(['logo_dir' => 'otro.png']);

    $this->deleteJson(route('branches.logo.destroy', $otherBranch))->assertForbidden();

    expect($otherBranch->fresh()->logo_dir)->toBe('otro.png');
});
