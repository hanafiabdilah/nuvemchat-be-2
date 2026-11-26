<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Crm\CustomerImport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Customers in from a spreadsheet or an address book (.vcf, Google Contacts CSV): preview the columns, then import. */
class CustomerImportController extends Controller
{
    public function __construct(private readonly CustomerImport $import) {}

    public function preview(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        return response()->json($this->import->preview($request->user()->tenant_id, $request->file('file')));
    }

    public function commit(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0'],
            'on_match' => ['required', Rule::in(CustomerImport::ON_MATCH)],
            // Brazilian numbers saved without their area code (DDD) get this one.
            'area_code' => ['nullable', 'string', 'regex:/^[1-9]{2}$/'],
        ]);

        $user = $request->user();
        $report = $this->import->commit($user, $data['token'], $data['mapping'], $data['on_match'], $data['area_code'] ?? null);

        AuditLog::record('crm.import', 'Imported CRM customers', [
            'tenant_id' => $user->tenant_id,
            'created' => $report['created'],
            'updated' => $report['updated'],
            'skipped' => $report['skipped'],
        ]);

        return response()->json($report);
    }
}
