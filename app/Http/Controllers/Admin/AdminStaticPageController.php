<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaticPageRequest;
use App\Models\StaticPage;
use App\Support\DefaultStaticPages;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class AdminStaticPageController extends Controller
{
    public function index(): View
    {
        return view('admin.static-pages');
    }

    public function apiIndex(): JsonResponse
    {
        $pages = StaticPage::query()->orderBy('sort_order')->orderBy('title')->get();

        return response()->json(['success' => true, 'data' => $pages]);
    }

    public function store(StaticPageRequest $request): JsonResponse
    {
        $page = StaticPage::query()->create($this->payload($request));

        return response()->json(['success' => true, 'data' => $page], 201);
    }

    public function update(StaticPageRequest $request, StaticPage $staticPage): JsonResponse
    {
        $staticPage->fill($this->payload($request))->save();

        return response()->json(['success' => true, 'data' => $staticPage]);
    }

    public function seedDefaults(): JsonResponse
    {
        foreach (DefaultStaticPages::all() as $page) {
            StaticPage::query()->updateOrCreate(
                ['slug' => $page['slug']],
                array_merge($page, ['variables' => json_encode($page['variables'], JSON_THROW_ON_ERROR)])
            );
        }

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StaticPageRequest $request): array
    {
        $data = $request->validated();
        $data['variables'] = json_encode($data['variables'] ?? [], JSON_THROW_ON_ERROR);

        return $data;
    }
}
