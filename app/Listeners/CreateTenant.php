<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class CreateTenant
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        $user = User::find($event->user->id);

        if($user?->hasRole('owner')){
            $tenant = $user->tenant()->create([]);
            $user->tenant_id = $tenant->id;
            $user->save();
        }
    }
}
