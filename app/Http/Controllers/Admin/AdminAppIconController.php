<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAppIconRequest;
use App\Services\Branding\AppIcon;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use InvalidArgumentException;

class AdminAppIconController extends Controller
{
    public function index(): View
    {
        return view('admin.app-icon');
    }

    public function show(AppIcon $icon): JsonResponse
    {
        return $this->payload($icon);
    }

    public function store(UpdateAppIconRequest $request, AppIcon $icon): JsonResponse
    {
        try {
            $icon->store((string) $request->file('icon')?->get());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage(), 'errors' => ['icon' => [$exception->getMessage()]]], 422);
        }

        return $this->payload($icon);
    }

    public function destroy(AppIcon $icon): JsonResponse
    {
        $icon->remove();

        return $this->payload($icon);
    }

    private function payload(AppIcon $icon): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'app_name' => (string) config('app.name'),
                'icon_url' => $icon->url('icon-512'),
                'apple_touch_icon_url' => $icon->url('apple-touch-icon'),
            ],
        ]);
    }
}
