<?php

namespace App\Http\Controllers;

use App\Http\Requests\Library\LibraryBulkRequest;
use App\Services\Library\LibraryBulkService;
use Illuminate\Http\JsonResponse;

class LibraryBulkController extends Controller
{
    public function __invoke(LibraryBulkRequest $request, LibraryBulkService $bulk): JsonResponse
    {
        $count = $bulk->apply(
            $request->user(), $request->validated('action'), $request->validated('item_ulids'),
            $request->validated('collection_ulid'), $request->validated('source_collection_ulid'),
        );

        return response()->json(['success' => true, 'data' => ['affected_count' => $count]]);
    }
}
