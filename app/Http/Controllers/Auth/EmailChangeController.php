<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\EmailChangeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The signed link mailed to a requested new address. Like the verification
 * link it is account-bound: it applies only in a session for the same account.
 */
class EmailChangeController extends Controller
{
    public function confirm(Request $request, EmailChangeService $emailChanges, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && hash_equals((string) $user->getKey(), $id), 403);

        $confirmed = $emailChanges->confirm($user, $hash);

        return redirect()->route('user.settings', ['email_change' => $confirmed ? 'confirmed' : 'failed']);
    }
}
