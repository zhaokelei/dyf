<?php

namespace App\Http\Controllers;

use App\Models\GroupApplication;
use App\Models\ScoreApplication;
use App\Models\ScoreRecord;
use App\Models\Semester;
use App\Services\ScoreService;
use App\Support\SemesterStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SemesterController extends Controller
{
    public function __construct(private readonly ScoreService $scores)
    {
    }

    public function index(): Response
    {
        $semesters = Semester::query()
            ->orderByDesc('academic_year')
            ->orderBy('term')
            ->get()
            ->map(fn (Semester $semester) => [
                'id' => $semester->id,
                'academic_year' => $semester->academic_year,
                'term' => $semester->term,
                'name' => $semester->name,
                'start_date' => $semester->start_date?->format('Y-m-d'),
                'end_date' => $semester->end_date?->format('Y-m-d'),
                'is_current' => $semester->is_current,
                'applications' => ScoreApplication::where('semester_id', $semester->id)->count(),
                'groups' => GroupApplication::where('semester_id', $semester->id)->count(),
                'records' => ScoreRecord::where('semester_id', $semester->id)->count(),
            ]);

        return Inertia::render('Semesters/Index', [
            'semesters' => $semesters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'academic_year' => ['required', 'string', 'regex:/^\d{4}(-\d{4})?$/'],
            'term' => ['required', 'integer', 'in:1,2'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'create_both' => ['boolean'],
        ], [], [
            'academic_year' => '学年',
            'term' => '学期',
        ]);

        $terms = ! empty($data['create_both']) ? [1, 2] : [(int) $data['term']];
        $created = 0;

        DB::transaction(function () use ($data, $terms, &$created) {
            $hasCurrent = Semester::where('is_current', true)->exists();

            foreach ($terms as $term) {
                if (Semester::where('academic_year', $data['academic_year'])->where('term', $term)->exists()) {
                    continue;
                }

                Semester::create([
                    'academic_year' => $data['academic_year'],
                    'term' => $term,
                    'name' => $data['academic_year'].'学年'.($term === 1 ? '第一学期' : '第二学期'),
                    'start_date' => $data['start_date'] ?? null,
                    'end_date' => $data['end_date'] ?? null,
                    'is_current' => ! $hasCurrent && $term === 1,
                ]);

                $created++;
            }
        });

        app(SemesterStore::class)->forget();

        $this->scores->audit($request->user(), 'create_semester', null, [
            'academic_year' => $data['academic_year'],
            'terms' => $terms,
        ]);

        return back()->with(
            $created > 0 ? 'success' : 'error',
            $created > 0 ? "已创建 {$created} 个学期。" : '所选学期的记录已存在。'
        );
    }

    public function setCurrent(Request $request, Semester $semester): RedirectResponse
    {
        DB::transaction(function () use ($semester) {
            Semester::query()->update(['is_current' => false]);
            $semester->update(['is_current' => true]);
        });

        app(SemesterStore::class)->forget();

        $this->scores->audit($request->user(), 'set_current_semester', $semester);

        return back()->with('success', "已将 {$semester->name} 设为当前学期。");
    }

    public function destroy(Request $request, Semester $semester): RedirectResponse
    {
        if ($semester->is_current) {
            return back()->with('error', '不能删除当前学期。');
        }

        $used = ScoreApplication::where('semester_id', $semester->id)->exists()
            || GroupApplication::where('semester_id', $semester->id)->exists()
            || ScoreRecord::where('semester_id', $semester->id)->exists();

        if ($used) {
            return back()->with('error', '该学期已有申请或成绩记录，不能删除。');
        }

        $semester->delete();
        app(SemesterStore::class)->forget();
        $this->scores->audit($request->user(), 'delete_semester', null, [
            'name' => $semester->name,
        ]);

        return back()->with('success', '学期已删除。');
    }
}
