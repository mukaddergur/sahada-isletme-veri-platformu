<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\User;

class LogService
{
    public function log(
        string $action,
        string $message,
        ?int $userId = null,
        ?int $projectId = null,
        string $level = 'info',
        array $context = [],
        ?string $ip = null
    ): ActivityLog {
        return ActivityLog::create([
            'user_id' => $userId,
            'project_id' => $projectId,
            'action' => $action,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'ip_address' => $ip,
        ]);
    }

    public function notify(
        User $user,
        string $type,
        string $title,
        string $message,
        ?int $projectId = null,
        array $data = []
    ): AppNotification {
        return AppNotification::create([
            'user_id' => $user->id,
            'project_id' => $projectId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }
}
