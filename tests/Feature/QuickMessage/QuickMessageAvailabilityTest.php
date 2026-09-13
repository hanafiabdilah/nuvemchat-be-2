<?php

use App\Models\QuickMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * The "Disponibilidade" field in the edit modal (whole workspace vs. only me)
 * used to have no effect: UpdateQuickMessageRequest::rules() never listed
 * user_id, so $request->validated() silently dropped it before the model was
 * ever touched — the toast said "updated successfully" and nothing changed.
 */
test('an owner can move a quick message from tenant-level to only themselves', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    Role::findOrCreate('owner', 'web');
    $owner->assignRole('owner');

    $quickMessage = QuickMessage::create([
        'tenant_id' => $tenant->id,
        'user_id' => null,
        'shortcut' => 'ola',
        'message' => 'Olá, tudo bem?',
    ]);

    $response = $this->actingAs($owner)->putJson("/api/quick-messages/{$quickMessage->id}", [
        'shortcut' => 'ola',
        'message' => 'Olá, tudo bem?',
        'user_id' => $owner->id,
    ]);

    $response->assertOk();
    expect($quickMessage->fresh()->user_id)->toBe($owner->id);
});

test('an owner can move a quick message from only themselves back to the whole workspace', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();
    Role::findOrCreate('owner', 'web');
    $owner->assignRole('owner');

    $quickMessage = QuickMessage::create([
        'tenant_id' => $tenant->id,
        'user_id' => $owner->id,
        'shortcut' => 'ola',
        'message' => 'Olá, tudo bem?',
    ]);

    $response = $this->actingAs($owner)->putJson("/api/quick-messages/{$quickMessage->id}", [
        'shortcut' => 'ola',
        'message' => 'Olá, tudo bem?',
        'user_id' => null,
    ]);

    $response->assertOk();
    expect($quickMessage->fresh()->user_id)->toBeNull();
});

test('an agent editing their own quick message cannot widen it to the whole workspace', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    $agent = User::factory()->create(['tenant_id' => $tenant->id]);

    $quickMessage = QuickMessage::create([
        'tenant_id' => $tenant->id,
        'user_id' => $agent->id,
        'shortcut' => 'ola',
        'message' => 'Olá, tudo bem?',
    ]);

    $response = $this->actingAs($agent)->putJson("/api/quick-messages/{$quickMessage->id}", [
        'shortcut' => 'ola',
        'message' => 'Mensagem editada',
        'user_id' => null,
    ]);

    $response->assertForbidden();
    expect($quickMessage->fresh())
        ->user_id->toBe($agent->id)
        ->message->toBe('Olá, tudo bem?');
});

test('an agent re-saving their own quick message with their own id still updates the message', function () {
    $owner = User::factory()->create();
    $tenant = Tenant::create(['user_id' => $owner->id]);
    $owner->forceFill(['tenant_id' => $tenant->id])->save();

    $agent = User::factory()->create(['tenant_id' => $tenant->id]);

    $quickMessage = QuickMessage::create([
        'tenant_id' => $tenant->id,
        'user_id' => $agent->id,
        'shortcut' => 'ola',
        'message' => 'Olá, tudo bem?',
    ]);

    $response = $this->actingAs($agent)->putJson("/api/quick-messages/{$quickMessage->id}", [
        'shortcut' => 'ola',
        'message' => 'Mensagem editada',
        'user_id' => $agent->id,
    ]);

    $response->assertOk();
    expect($quickMessage->fresh())
        ->user_id->toBe($agent->id)
        ->message->toBe('Mensagem editada');
});
