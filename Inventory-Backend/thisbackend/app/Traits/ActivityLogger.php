<?php

namespace App\Traits;

use App\Models\Log;

trait ActivityLogger
{
    protected function logActivity($userId, $description)
    {
        Log::create([
            'idUsers' => $userId,
            'activity_description' => $description,
            'timestamp' => now()->toDateTimeString(),
        ]);
    }
}