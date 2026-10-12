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

    const RETURN_DAYS_CATEGORIES = ['Office Supplies', 'School Equipment'];

    // Admin only (route middleware). Default return period per category.
    public function returnDays()
    {
        $out = [];
        foreach (self::RETURN_DAYS_CATEGORIES as $category) {
            $s = Setting::where('setting_key', 'return_days:' . $category)->first();
            $out[$category] = $s ? (int) $s->setting_value : null;
        }

        return response()->json(['return_days' => $out]);
    }

    // Admin only (route middleware). Null clears the category default.
    public function updateReturnDays(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|in:' . implode(',', self::RETURN_DAYS_CATEGORIES),
            'return_days' => 'nullable|integer|min:1|max:365',
        ]);

        $key = 'return_days:' . $validated['category'];

        if (empty($validated['return_days'])) {
            Setting::where('setting_key', $key)->delete();
            $msg = "Cleared default return period for {$validated['category']}";
        } else {
            Setting::updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => (string) $validated['return_days']]
            );
            $msg = "Set default return period for {$validated['category']} to {$validated['return_days']} days";
        }

        $this->logActivity(request()->user()->idUsers, $msg);

        return $this->returnDays();
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
