<?php

namespace App\Http\Controllers;

use App\Models\ScoreCategory;
use App\Services\ScoreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function __construct(private readonly ScoreService $scores)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Categories/Index', [
            'categories' => ScoreCategory::query()
                ->withCount(['applications', 'records'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $category = ScoreCategory::create($data);
        $this->scores->audit($request->user(), 'create_category', $category);

        return back()->with('success', '类别已创建。');
    }

    public function update(Request $request, ScoreCategory $category): RedirectResponse
    {
        $data = $this->validated($request);

        $category->update($data);
        $this->scores->audit($request->user(), 'update_category', $category);

        return back()->with('success', '类别已更新。');
    }

    public function destroy(Request $request, ScoreCategory $category): RedirectResponse
    {
        if ($category->applications()->exists() || $category->records()->exists()) {
            return back()->with('error', '该类别已有申请或成绩记录，不能删除，可以改为停用。');
        }

        $category->delete();
        $this->scores->audit($request->user(), 'delete_category', null, ['name' => $category->name]);

        return back()->with('success', '类别已删除。');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'type' => ['required', Rule::in(['add', 'deduct'])],
            'description' => ['nullable', 'string', 'max:500'],
            'default_score' => ['nullable', 'numeric', 'min:0', 'max:1000', 'multiple_of:0.5'],
            'max_score' => ['nullable', 'numeric', 'min:0.5', 'max:1000', 'multiple_of:0.5'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ], [], [
            'name' => '类别名称',
            'type' => '类别类型',
            'max_score' => '单次上限',
        ]);
    }
}
