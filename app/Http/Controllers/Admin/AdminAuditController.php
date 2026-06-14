<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AdminAuditController extends Controller
{
    /**
     * Auth audit-log page. Data is fetched client-side from the package's
     * admin audit endpoint (GET /api/auth/audit-log/all), gated by the
     * `admin-only` ability via config('bherila-auth.audit.admin_ability').
     */
    public function index(): View
    {
        return view('admin.audit-log');
    }
}
