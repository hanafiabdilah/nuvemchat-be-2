<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /**
     * Catalog of platform (Back Office) permissions for the role editor.
     */
    public function index()
    {
        $permissions = Permission::where('is_platform', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $permissions]);
    }
}
