<?php

namespace Database\Seeders;

use Database\Seeders\ManualDemo\Automation;
use Database\Seeders\ManualDemo\Commerce;
use Database\Seeders\ManualDemo\EmailInbox;
use Database\Seeders\ManualDemo\History;
use Database\Seeders\ManualDemo\Inbox;
use Database\Seeders\ManualDemo\Support;
use Database\Seeders\ManualDemo\Workspace;
use Illuminate\Database\Seeder;

/**
 * The fictitious "Loja Aurora" workspace the public user manual is
 * photographed against (nuvemchat-fe-2/scripts/manual).
 *
 * Every name, number, e-mail and file here is invented — the manual is public,
 * and a screenshot is a publication: nothing from a real workspace may end up
 * in one. That is also why this seeder refuses to run unless MANUAL_DEMO=1:
 * it is meant for a throwaway database (the capture script points the backend
 * at its own SQLite file and storage folder), never for a dev or production one.
 *
 *   MANUAL_DEMO=1 MANUAL_DEMO_MEDIA=/path/to/demo-media \
 *     php artisan migrate:fresh --seed --seeder=ManualDemoSeeder
 *
 * Never wired into DatabaseSeeder.
 */
class ManualDemoSeeder extends Seeder
{
    use Support, Workspace, Automation, Inbox, EmailInbox, History, Commerce;

    public const OWNER_EMAIL = 'marina@lojaaurora.example';
    public const PASSWORD = 'aurora2026';

    public function run(): void
    {
        if (! env('MANUAL_DEMO')) {
            $this->command?->error('ManualDemoSeeder only runs with MANUAL_DEMO=1 on a throwaway database.');

            return;
        }

        $this->now = now()->startOfMinute();
        $this->mediaDir = env('MANUAL_DEMO_MEDIA');

        $this->call([
            RoleAndPermissionSeeder::class,
            PlanSeeder::class,
            ApiwayPlanSeeder::class,
            TrainedAgentSeeder::class,
        ]);

        $this->seedWorkspace();
        $this->seedAutomation();
        $this->seedInbox();
        $this->seedEmail();
        $this->seedHistory();
        $this->seedCommerce();

        $this->command?->info('Manual demo workspace ready — login: ' . self::OWNER_EMAIL . ' / ' . self::PASSWORD);
    }
}
