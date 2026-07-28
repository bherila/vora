<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateActiveIdentityRequest;
use App\Support\ActiveIdentity;
use Illuminate\Http\JsonResponse;

class IdentityController extends Controller
{
    public function update(UpdateActiveIdentityRequest $request, ActiveIdentity $identity): JsonResponse
    {
        $characterId = $request->validated('character_id');
        $identity->set($request, $characterId);

        return response()->json([
            'success' => true,
            'data' => ['active_identity_id' => $characterId],
        ]);
    }
}
