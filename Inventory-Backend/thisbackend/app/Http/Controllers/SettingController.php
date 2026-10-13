<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\ReturnPolicy;
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

    // Default return period (in days) per category, used by borrowed items
    // that have no period of their own (roadmap #8). null = no default.
    // Any authenticated role can read it; admin only can change it.
    public function returnPeriods()
    {
        $defaults = ReturnPolicy::categoryDefaults();

        return response()->json([
            'office_supplies' => $defaults['Office Supplies'] ?? null,
            'school_equipment' => $defaults['School Equipment'] ?? null,
        ]);
    }

    public function updateReturnPeriods(Request $request)
    {
        $validated = $request->validate([
            'office_supplies' => 'present|nullable|integer|min:1|max:3650',
            'school_equipment' => 'present|nullable|integer|min:1|max:3650',
        ]);

        $map = [
            'office_supplies' => ReturnPolicy::CATEGORY_KEYS['Office Supplies'],
            'school_equipment' => ReturnPolicy::CATEGORY_KEYS['School Equipment'],
        ];

        foreach ($map as $field => $key) {
            if ($validated[$field] === null) {
                Setting::where('setting_key', $key)->delete();
            } else {
                Setting::updateOrCreate(
                    ['setting_key' => $key],
                    ['setting_value' => (string) $validated[$field]]
                );
            }
        }

        ReturnPolicy::forget();

        $this->logActivity(
            request()->user()->idUsers,
            'Updated default return periods'
        );

        return $this->returnPeriods();
    }
}
