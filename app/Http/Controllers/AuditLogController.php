<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Support\PageSize;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $page = PageSize::resolve($request, 20);

        $logs = AuditLog::query()
            ->with('user:id,name,role')
            ->when($request->input('action'), fn ($q, $value) => $q->where('action', $value))
            ->when($request->input('search'), function ($q, $value) {
                $q->where(function ($sub) use ($value) {
                    $sub->where('action', 'like', "%{$value}%")
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$value}%"));
                });
            })
            ->latest('created_at')
            ->paginate($page['limit'])
            ->withQueryString();

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'filters' => $request->only(['action', 'search']),
            'perPage' => $page['per_page'],
        ]);
    }
}
