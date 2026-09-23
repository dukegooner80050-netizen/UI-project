<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use App\Traits\ActivityLogger;

class SettingController extends Controller
{
    use ActivityLogger;

    const DEFAULT_MONTHLY_REQUEST_LIMIT = 5;

    // Any authenticated role can read this -- cashier/dean need to know
    // their own limit, not just admin.
    public function monthlyRequestLimit()
    {
        $setting = Setting::where('setting_key', 'monthly_request_limit')->first();

        return response()->json([
            'monthly_request_limit' => $setting
                ? (int) $setting->setting_value
                : self::DEFAULT_MONTHLY_REQUEST_LIMIT,
        ]);
    }

    // Admin only (enforced by route middleware).
    public function updateMonthlyRequestLimit(Request $request)
    {
        $validated = $request->validate([
            'monthly_request_limit' => 'required|integer|min:1',
        ]);

        $setting = Setting::updateOrCreate(
            ['setting_key' => 'monthly_request_limit'],
            ['setting_value' => (string) $validated['monthly_request_limit']]
        );

        $this->logActivity(
            request()->user()->idUsers,
            "Updated monthly request limit to {$validated['monthly_request_limit']}"
        );

        return response()->json([
            'message' => 'Monthly request limit updated successfully.',
            'monthly_request_limit' => (int) $setting->setting_value,
        ]);
    }
}
