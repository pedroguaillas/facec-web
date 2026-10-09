<?php

use App\Models\Holiday;
use App\Models\User;
use App\Models\UserType;

function holidayActingAs(string $type): void
{
    $userType = UserType::firstOrCreate(['type' => $type]);

    test()->actingAs(User::factory()->create(['user_type_id' => $userType->id]));
}

test('el admin crea, lista, edita y elimina feriados', function () {
    holidayActingAs('admin');

    $holiday = $this->postJson(route('admin.holidays.store'), [
        'name' => 'Feriado de Navidad',
        'starts_at' => '2026-12-24',
        'ends_at' => '2026-12-25',
    ])->assertCreated()
        ->assertJsonPath('starts_at', '2026-12-24')
        ->json();

    $this->getJson(route('admin.holidays.index'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Feriado de Navidad');

    $this->putJson(route('admin.holidays.update', $holiday['id']), [
        'name' => 'Navidad',
        'starts_at' => '2026-12-24',
        'ends_at' => '2026-12-26',
    ])->assertOk()->assertJsonPath('ends_at', '2026-12-26');

    $this->deleteJson(route('admin.holidays.destroy', $holiday['id']))->assertOk();

    expect(Holiday::count())->toBe(0);
});

test('la fecha de fin no puede ser anterior a la de inicio', function () {
    holidayActingAs('admin');

    $this->postJson(route('admin.holidays.store'), [
        'name' => 'Feriado',
        'starts_at' => '2026-12-25',
        'ends_at' => '2026-12-24',
    ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
});

test('un cliente no puede gestionar feriados', function () {
    holidayActingAs('client');

    $this->getJson(route('admin.holidays.index'))->assertForbidden();
});
