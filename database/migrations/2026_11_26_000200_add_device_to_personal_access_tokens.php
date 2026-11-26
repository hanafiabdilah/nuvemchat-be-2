<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of device each dashboard sign-in came from, and how to name it.
 *
 * `device_kind` is what lets an account stay signed in on a computer and a
 * phone at once (App\Services\Auth\SingleSession): a new sign-in ends only the
 * session of its own kind. `device_label` ("Chrome · Windows") is what the
 * person reads under Profile → Signed-in devices.
 *
 * Sessions from before this column existed have neither. They are treated as
 * any kind, so the next sign-in ends them as it always did — except the ones
 * the Pingly app registered for push notifications, which are known phones and
 * are labelled so here instead of being thrown out by the first sign-in at a
 * computer after the deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('device_kind', 16)->nullable()->after('abilities');
            $table->string('device_label', 64)->nullable()->after('device_kind');
        });

        $platforms = DB::table('device_tokens')
            ->whereNotNull('personal_access_token_id')
            ->pluck('platform', 'personal_access_token_id');

        foreach ($platforms as $tokenId => $platform) {
            DB::table('personal_access_tokens')
                ->where('id', $tokenId)
                ->where('name', 'auth_token')
                ->whereNull('device_kind')
                ->update([
                    'device_kind' => 'phone',
                    'device_label' => 'Pingly app · '.($platform === 'ios' ? 'iOS' : 'Android'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['device_kind', 'device_label']);
        });
    }
};
