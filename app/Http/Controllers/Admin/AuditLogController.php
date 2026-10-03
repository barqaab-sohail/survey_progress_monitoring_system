<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $logs = AuditLog::with(['user', 'organization'])->when($request->filled('action'), fn ($query) => $query->where('action', 'like', '%'.$request->action.'%'))->latest()->paginate(50)->withQueryString();

        return view('admin.audit.index', compact('logs'));
    }
}
