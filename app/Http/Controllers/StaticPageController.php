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
        $row = StaticPage::query()->where('slug', $slug)->first();

        // An existing-but-unpublished row was deliberately taken down: 404 rather
        // than silently reverting to the built-in boilerplate. The fallback only
        // applies when no row exists at all (e.g. defaults never seeded).
        if ($row !== null && ! $row->is_published) {
            throw new NotFoundHttpException;
        }

        $page = $row;
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
