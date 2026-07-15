<?php

use App\Events\TemplateStatusUpdated;

test('maps the Meta payload to broadcast fields', function () {
    $event = new TemplateStatusUpdated(42, [
        'message_template_name' => 'welcome',
        'message_template_language' => 'pt_BR',
        'event' => 'APPROVED',
        'reason' => null,
        'message_template_id' => '123',
    ]);

    $payload = $event->broadcastWith();

    expect($payload)->toMatchArray([
        'name' => 'welcome',
        'language' => 'pt_BR',
        'status' => 'APPROVED',
        'reason' => null,
        'template_id' => '123',
    ]);
});

test('broadcasts on the tenant channel with a stable event name', function () {
    $event = new TemplateStatusUpdated(42, []);

    expect($event->broadcastAs())->toBe('template-status-updated');
    expect($event->broadcastOn()[0]->name)->toBe('tenant-channel.42');
});

test('tolerates a partial payload', function () {
    $event = new TemplateStatusUpdated('tenant-x', ['event' => 'REJECTED']);

    expect($event->broadcastWith())->toMatchArray([
        'name' => null,
        'status' => 'REJECTED',
        'template_id' => null,
    ]);
});
