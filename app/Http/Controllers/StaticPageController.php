<?php

namespace App\Http\Controllers;

use App\Models\StaticPage;
use App\Support\DefaultStaticPages;
use App\Support\StaticPageRenderer;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StaticPageController extends Controller
{
    public function home(): View
    {
        return $this->show('home');
    }

    public function show(string $slug): View
    {
        $page = StaticPage::query()->where('slug', $slug)->where('is_published', true)->first();
        $fallback = $page === null ? DefaultStaticPages::get($slug) : null;

        if ($page === null && $fallback === null) {
            throw new NotFoundHttpException;
        }

        $title = $page?->title ?? (string) $fallback['title'];
        $markdown = $page?->body_markdown ?? (string) $fallback['body_markdown'];
        $html = StaticPageRenderer::renderMarkdown($markdown, StaticPageRenderer::variables($page ?? $fallback));

        return view('static-page', compact('title', 'html'));
    }
}
