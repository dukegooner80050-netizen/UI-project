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
    const DEFAULT_STUDENT_ID_LENGTH = 15;

    // Longest student ID number the system accepts (the AMO can change it).
    public static function studentIdMax(): int
    {
        $s = Setting::where('setting_key', 'student_id_max_length')->first();
        $n = $s ? (int) $s->setting_value : self::DEFAULT_STUDENT_ID_LENGTH;
        return $n >= 4 ? $n : self::DEFAULT_STUDENT_ID_LENGTH;
    }

    // Returns an error message, or null when the ID is fine. Digits and '-' only.
    public static function studentIdError(string $id): ?string
    {
        $max = self::studentIdMax();
        if (!preg_match('/^[0-9-]+$/', $id)) {
            return 'The student ID can only have numbers and a dash (-).';
        }
        if (mb_strlen($id) > $max) {
            return "The student ID can be at most {$max} characters.";
        }
        return null;
    }

    // Any authenticated role can read this (the forms need it).
    public function studentIdLength()
    {
        return response()->json(['student_id_max_length' => self::studentIdMax()]);
    }

    // AMO only (route middleware).
    public function updateStudentIdLength(Request $request)
    {
        $validated = $request->validate([
            'student_id_max_length' => 'required|integer|min:4|max:30',
        ]);
        Setting::updateOrCreate(
            ['setting_key' => 'student_id_max_length'],
            ['setting_value' => (string) $validated['student_id_max_length']]
        );
        $this->logActivity(request()->user()->idUsers, "Set the student ID length limit to {$validated['student_id_max_length']}");

        return response()->json([
            'message' => 'Student ID length saved.',
            'student_id_max_length' => (int) $validated['student_id_max_length'],
        ]);
    }

    // Any authenticated role can read this -- everyone except the AMO
    // needs to know their own limit.
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

    // Default stock levels (Critical / Minimum / Optimal) for School Equipment
    // and Non-Consumable Type B office supplies. They pre-fill the Add Item
    // forms and are used when a new item is saved without its own levels.
    // AMO only (route middleware).
    public const STOCK_GROUPS = [
        'school_equipment' => 'stock_levels_school_equipment',
        'type_b' => 'stock_levels_type_b',
    ];

    public static function stockLevelsFor(string $group): array
    {
        $row = Setting::where('setting_key', self::STOCK_GROUPS[$group])->first();
        $v = $row ? json_decode((string) $row->setting_value, true) : null;

        return [
            'critical' => (int) ($v['critical'] ?? \App\Models\Item::DEFAULT_CRITICAL),
            'minimum' => (int) ($v['minimum'] ?? \App\Models\Item::DEFAULT_MINIMUM),
            'optimal' => (int) ($v['optimal'] ?? \App\Models\Item::DEFAULT_OPTIMAL),
        ];
    }

    // The group an item belongs to, or null (consumables, uniforms, ...).
    public static function stockGroupFor(?string $category, ?string $itemType): ?string
    {
        if ($category === 'School Equipment') return 'school_equipment';
        if ($category === 'Office Supplies' && $itemType === \App\Models\Item::TYPE_B) return 'type_b';
        return null;
    }

    public function stockLevels()
    {
        return response()->json([
            'school_equipment' => self::stockLevelsFor('school_equipment'),
            'type_b' => self::stockLevelsFor('type_b'),
        ]);
    }

    public function updateStockLevels(Request $request)
    {
        $rules = ['apply_to_existing' => 'sometimes|boolean'];
        foreach (array_keys(self::STOCK_GROUPS) as $g) {
            foreach (['critical', 'minimum', 'optimal'] as $f) {
                $rules["$g.$f"] = 'required|integer|min:0|max:100000';
            }
        }
        $validated = $request->validate($rules);

        foreach (array_keys(self::STOCK_GROUPS) as $g) {
            $v = $validated[$g];
            if ($v['critical'] > $v['minimum'] || $v['minimum'] > $v['optimal']) {
                return response()->json([
                    'message' => 'Stock levels must satisfy: Critical <= Minimum <= Optimal.',
                ], 422);
            }
        }

        $updated = ['school_equipment' => 0, 'type_b' => 0];

        foreach (self::STOCK_GROUPS as $g => $key) {
            $v = $validated[$g];
            Setting::updateOrCreate(
                ['setting_key' => $key],
                ['setting_value' => json_encode([
                    'critical' => (int) $v['critical'],
                    'minimum' => (int) $v['minimum'],
                    'optimal' => (int) $v['optimal'],
                ])]
            );

            if (!empty($validated['apply_to_existing'])) {
                $query = $g === 'school_equipment'
                    ? \App\Models\Item::where('category', 'School Equipment')
                    : \App\Models\Item::where('category', 'Office Supplies')->where('item_type', \App\Models\Item::TYPE_B);
                $updated[$g] = $query->update([
                    'critical_stock' => (int) $v['critical'],
                    'minimum_stock' => (int) $v['minimum'],
                    'optimal_stock' => (int) $v['optimal'],
                ]);
            }
        }

        $this->logActivity(
            request()->user()->idUsers,
            'Updated default stock levels' . (!empty($validated['apply_to_existing'])
                ? " (applied to {$updated['school_equipment']} equipment and {$updated['type_b']} Type B item(s))"
                : '')
        );

        return response()->json([
            'message' => 'Stock levels updated.',
            'updated' => $updated,
            'school_equipment' => self::stockLevelsFor('school_equipment'),
            'type_b' => self::stockLevelsFor('type_b'),
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
