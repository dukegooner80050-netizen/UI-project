<?php

namespace App\Http\Controllers;

use App\Models\Log;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogController extends Controller
{
    private function present($log): array
    {
        $timestamp = Carbon::parse($log->timestamp);

        return [
            "id" => $log->idlogs,
            "action" => $log->activity_description,
            "performedBy" =>
                optional($log->user)->full_name
                ?? optional($log->user)->username
                ?? "Unknown User",
            "role" => optional($log->user)->role ?? "Unknown",
            "timestamp" => $log->timestamp,
            "date" => $timestamp->toDateString(),
            "time" => $timestamp->format("H:i:s"),
        ];
    }

    /**
     * GET /logs
     *
     *  - With ?page=N the server does the work and returns one page:
     *      { data: [...], meta: { current_page, last_page, per_page, total } }
     *    Optional: per_page (10-100), sort (time|action|user|role),
     *    dir (asc|desc), search, user_id, from, to (YYYY-MM-DD).
     *  - With ?limit=N it returns just the newest N (the dashboard's
     *    "recent activity").
     *  - With neither it returns everything, as before (the Reports page
     *    still builds its Excel sheet from the full list).
     */
    public function index(Request $request)
    {
        $query = Log::query()
            ->select('logs.*')
            ->leftJoin('users', 'users.idUsers', '=', 'logs.idUsers')
            ->with('user');

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($w) use ($like) {
                $w->where('logs.activity_description', 'like', $like)
                    ->orWhere('users.full_name', 'like', $like)
                    ->orWhere('users.username', 'like', $like)
                    ->orWhere('users.role', 'like', $like)
                    ->orWhere(DB::raw('DATE(logs.timestamp)'), 'like', $like);
            });
        }

        if ($userId = $request->query('user_id')) {
            $query->where('logs.idUsers', (int) $userId);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('logs.timestamp', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('logs.timestamp', '<=', $to);
        }

        $dir = strtolower((string) $request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        switch ($request->query('sort', 'time')) {
            case 'action':
                $query->orderBy('logs.activity_description', $dir);
                break;
            case 'user':
                $query->orderByRaw('COALESCE(users.full_name, users.username) ' . $dir);
                break;
            case 'role':
                $query->orderBy('users.role', $dir);
                break;
            default:
                $query->orderBy('logs.timestamp', $dir);
        }
        // Stable order when the sort column ties.
        $query->orderBy('logs.idlogs', $dir);

        if ($request->has('page')) {
            $perPage = max(10, min(100, (int) $request->query('per_page', 25)));
            $page = $query->paginate($perPage);

            return response()->json([
                'data' => $page->getCollection()->map(fn ($l) => $this->present($l))->values(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]);
        }

        if ($limit = (int) $request->query('limit', 0)) {
            $query->limit(max(1, min(1000, $limit)));
        }

        return response()->json($query->get()->map(fn ($l) => $this->present($l)));
    }

    /** Everyone who appears in the logs, for the "User" filter dropdown. */
    public function users()
    {
        $rows = DB::table('logs')
            ->join('users', 'users.idUsers', '=', 'logs.idUsers')
            ->select('users.idUsers as id', DB::raw('COALESCE(users.full_name, users.username) as name'))
            ->groupBy('users.idUsers', 'users.full_name', 'users.username')
            ->orderBy('name')
            ->get();

        return response()->json($rows);
    }
}
