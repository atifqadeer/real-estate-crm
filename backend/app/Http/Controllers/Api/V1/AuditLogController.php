<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('audit.view'), 403);
        $logs = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->with('user:id,name,email')
            ->latest('created_at')
            ->paginate(25);
        return response()->json($logs);
    }
}
