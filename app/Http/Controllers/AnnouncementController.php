<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Services\ScoreService;
use App\Support\HtmlSanitizer;
use App\Support\PageSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AnnouncementController extends Controller
{
    public function __construct(private readonly ScoreService $scores)
    {
    }

    public function index(Request $request): Response
    {
        $page = PageSize::resolve($request, 10);

        return Inertia::render('Announcements/Index', [
            'announcements' => Announcement::query()
                ->with('author:id,name')
                ->orderByDesc('is_important')
                ->orderByDesc('published_at')
                ->paginate($page['limit'])
                ->withQueryString()
                ->through(fn (Announcement $item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'excerpt' => Str::limit(HtmlSanitizer::toText($item->content), 120),
                    'content' => HtmlSanitizer::toHtml($item->content),
                    'is_important' => $item->is_important,
                    'is_popup' => $item->is_popup,
                    'author' => $item->author?->name,
                    'published_at' => $item->published_at?->toIso8601String(),
                ]),
            'perPage' => $page['per_page'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $announcement = Announcement::create([
            ...$data,
            'author_id' => $request->user()->id,
            'published_at' => now(),
        ]);

        $this->scores->audit($request->user(), 'create_announcement', $announcement);

        return back()->with('success', '公告已发布。');
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($this->validated($request));

        $this->scores->audit($request->user(), 'update_announcement', $announcement);

        return back()->with('success', '公告已更新。');
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        $this->scores->audit($request->user(), 'delete_announcement', null, [
            'title' => $announcement->title,
        ]);

        return back()->with('success', '公告已删除。');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string', 'max:20000'],
            'is_important' => ['boolean'],
            'is_popup' => ['boolean'],
        ], [], [
            'title' => '公告标题',
            'content' => '公告内容',
        ]);

        $data['content'] = HtmlSanitizer::normalize($data['content']);

        return $data;
    }
}
