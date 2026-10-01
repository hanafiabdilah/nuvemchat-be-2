<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\Setting;
use App\Services\Push\FcmClient;
use App\Services\Push\FirebaseConfig;
use Illuminate\Http\Request;

/**
 * Back Office → Integrations → Firebase: the service account that sends push
 * notifications to the mobile app.
 *
 * Uploaded as the file Google hands out rather than typed into fields: the
 * private key is a multi-line PEM that breaks on the first copy-paste, and the
 * file is what the person actually has in their downloads folder. The key
 * itself never comes back out — the screen shows which project and which
 * account, which is all anybody needs to know it is the right file.
 */
class AdminFirebaseController extends Controller
{
    public function show()
    {
        return response()->json(['data' => $this->summary()]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required_without:service_account', 'file', 'max:64'],
            'service_account' => ['required_without:file', 'string', 'max:65536'],
        ]);

        $json = $request->hasFile('file')
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : (string) $request->input('service_account');

        $account = FirebaseConfig::store($json);

        AuditLog::record('firebase.service_account_uploaded', 'Uploaded the Firebase service account', [
            'project_id' => $account['project_id'],
            'client_email' => $account['client_email'],
        ]);

        return response()->json(['data' => $this->summary()]);
    }

    public function destroy()
    {
        $project = FirebaseConfig::projectId();
        FirebaseConfig::clear();

        AuditLog::record('firebase.service_account_removed', 'Removed the Firebase service account', [
            'project_id' => $project,
        ]);

        return response()->json(['data' => $this->summary()]);
    }

    public function test(FcmClient $fcm)
    {
        $result = $fcm->test();

        return response()->json(['data' => $result], $result['ok'] ? 200 : 422);
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $updatedAt = Setting::query()->where('key', FirebaseConfig::KEY_SERVICE_ACCOUNT)->value('updated_at');

        return [
            'configured' => FirebaseConfig::isConfigured(),
            'project_id' => FirebaseConfig::projectId(),
            'client_email' => FirebaseConfig::clientEmail(),
            'updated_at' => FirebaseConfig::isConfigured() && $updatedAt ? \Illuminate\Support\Carbon::parse($updatedAt)->toIso8601String() : null,
            'devices' => DeviceToken::query()->count(),
            'enabled' => (bool) config('push.enabled'),
        ];
    }
}
