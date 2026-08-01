<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    public function health()
    {
        $isSync = config('queue.default') === 'sync';
        $pendingJobs = 0;
        $failedJobs = 0;

        try {
            $pendingJobs = $isSync ? 0 : (int) DB::table('jobs')->count();
        } catch (\Throwable) {
            $pendingJobs = 0;
        }

        try {
            $failedJobs = $isSync ? 0 : (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $failedJobs = 0;
        }


        Scan::query()
            ->whereIn('status', ['pending', 'running'])
            ->where('updated_at', '<', now()->subMinutes(3))
            ->update([
                'status' => 'failed',
                'error_message' => 'Takılı tarama otomatik kapatıldı.',
                'finished_at' => now(),
            ]);

        $activeScan = Scan::query()
            ->whereIn('status', ['pending', 'running'])
            ->orderByDesc('updated_at')
            ->first(['id', 'status', 'progress', 'found_count', 'saved_count', 'failed_count', 'error_message', 'updated_at', 'project_id']);

        $hasStuck = $activeScan && $activeScan->updated_at < now()->subMinutes(2);
        $stuckScans = $hasStuck ? 1 : 0;
        $recentActive = $activeScan && $activeScan->updated_at > now()->subSeconds(90);
        $queuePending = $pendingJobs;
        $workerLikelyDown = ! $isSync && $queuePending > 0 && (bool) $hasStuck;
        $workerOk = $isSync || $pendingJobs === 0 || (bool) $recentActive;
        $healthy = ! $workerLikelyDown;

        $workerHint = null;
        if (! $isSync && $pendingJobs > 0 && $stuckScans > 0) {
            $workerHint = 'Queue worker kapalı olabilir: php artisan queue:work';
        }

        $message = match (true) {
            $isSync => 'Tarama senkron çalışıyor (queue worker gerekmez).',
            $workerLikelyDown => 'Kuyrukta iş var ancak tarama ilerleme göstermiyor. Queue worker kapalı olabilir: php artisan queue:work',
            $pendingJobs > 0 && $recentActive => 'Kuyruk çalışıyor; taramalar güncelleniyor.',
            $pendingJobs > 0 => 'Kuyrukta bekleyen işler var — php artisan queue:work gerekli.',
            $stuckScans > 0 => 'Eski bekleyen/çalışan tarama kayıtları var; durumu kontrol edin.',
            default => 'Sistem sağlıklı görünüyor.',
        };

        $latestScan = null;
        if ($activeScan) {
            $latestScan = [
                'id' => $activeScan->id,
                'status' => $activeScan->status,
                'progress' => $activeScan->progress,
                'found_count' => $activeScan->found_count,
                'saved_count' => $activeScan->saved_count,
                'failed_count' => $activeScan->failed_count,
                'error_message' => $activeScan->error_message,
                'project' => null,
            ];
        } else {
            $latest = Scan::query()->latest('id')->first(['id', 'status', 'progress', 'found_count', 'saved_count', 'failed_count', 'error_message']);
            if ($latest) {
                $latestScan = [
                    'id' => $latest->id,
                    'status' => $latest->status,
                    'progress' => $latest->progress,
                    'found_count' => $latest->found_count,
                    'saved_count' => $latest->saved_count,
                    'failed_count' => $latest->failed_count,
                    'error_message' => $latest->error_message,
                    'project' => null,
                ];
            }
        }

        return response()->json([
            'api' => true,
            'queue_connection' => config('queue.default'),
            'pending_jobs' => $pendingJobs,
            'failed_jobs' => $failedJobs,
            'stuck_scans' => $stuckScans,
            'queue_pending' => $queuePending,
            'has_stuck' => (bool) $hasStuck,
            'worker_likely_down' => $workerLikelyDown,
            'worker_ok' => $workerOk,
            'healthy' => $healthy,
            'worker_hint' => $workerHint,
            'message' => $message,
            'latest_scan' => $latestScan,
        ]);
    }

    public function notifications(Request $request)
    {
        $items = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json($items);
    }

    public function markNotificationRead(Request $request, AppNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['is_read' => true]);

        return response()->json($notification);
    }

    public function markAllNotificationsRead(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'Tüm bildirimler okundu.']);
    }

    public function logs(Request $request)
    {
        abort_unless($request->user()->isOperator(), 403);

        $logs = ActivityLog::query()
            ->with('user:id,name')
            ->latest()
            ->paginate(30);

        return response()->json($logs);
    }

    public function scans(Request $request)
    {
        $scans = Scan::query()
            ->with('project:id,name')
            ->when(! $request->user()->isAdmin(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->latest()
            ->paginate(20);

        return response()->json($scans);
    }
}
