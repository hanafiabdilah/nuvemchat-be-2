<?php

namespace App\Services\Push;

use App\Events\ConversationHandoff;
use App\Events\ConversationTakenOver;
use App\Events\ConversationTransferred;
use App\Events\MessageReceived;
use Illuminate\Support\Facades\Event;

/**
 * Push notifications ride on the same broadcast events the dashboard listens
 * to, so the phone hears about exactly what an open tab would toast — and no
 * sender of those events has to know phones exist. (`broadcast()` dispatches
 * through the event dispatcher, so a listener sees every one of them.)
 */
final class PushEvents
{
    public static function register(): void
    {
        Event::listen(MessageReceived::class, function (MessageReceived $event) {
            PushNotifier::messageReceived($event->message);
        });

        Event::listen(ConversationHandoff::class, function (ConversationHandoff $event) {
            PushNotifier::queue(PushNotifier::HANDOFF, $event->conversation);
        });

        Event::listen(ConversationTransferred::class, function (ConversationTransferred $event) {
            PushNotifier::queue(PushNotifier::TRANSFERRED, $event->conversation, [
                'to_agent_id' => $event->toAgent->id,
                'agent_name' => $event->fromAgent->name,
            ]);
        });

        Event::listen(ConversationTakenOver::class, function (ConversationTakenOver $event) {
            PushNotifier::queue(PushNotifier::TAKEN_OVER, $event->conversation, [
                'from_agent_id' => $event->fromAgent->id,
                'agent_name' => $event->toAgent->name,
            ]);
        });
    }
}
