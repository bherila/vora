<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

abstract class Controller
{
    /**
     * Authorize an action, answering 404 (not 403) when the viewer may not act
     * on the row — and equally when the row is missing ($model is null). This
     * keeps "exists but isn't yours / isn't visible / isn't reviewed" indistinguishable
     * from "doesn't exist", so numeric ids and ulids can't be used as an existence
     * oracle. Admins satisfy the policies via their before() hooks, so admin
     * visibility is unchanged. The generic body matches the missing-row 404 emitted
     * by the model route bindings (see AppServiceProvider) so responses can't be
     * diffed to tell "hidden" from "does not exist".
     */
    protected function authorizeOr404(string $ability, ?Model $model): void
    {
        abort_unless($model !== null && Gate::allows($ability, $model), 404, 'Not found.');
    }
}
