<?php

namespace App\Support;

use App\Models\Item;
use App\Models\Request;
use App\Models\RequestItem;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * Return due dates for borrowed items (roadmap #8).
 *
 * A borrowed line is due `return_days` after the request was approved
 * (request.borrowed_at). return_days comes from the item itself, falling
 * back to the default for the item's category (Settings page).
 */
class ReturnPolicy
{
    // category => settings key holding that category's default (in days)
    public const CATEGORY_KEYS = [
        'Office Supplies' => 'default_return_days_office_supplies',
        'School Equipment' => 'default_return_days_school_equipment',
    ];

    private static ?array $defaults = null;

    /** @return array<string, int|null> category => default days (null = none) */
    public static function categoryDefaults(): array
    {
        if (self::$defaults === null) {
            $stored = Setting::whereIn('setting_key', array_values(self::CATEGORY_KEYS))
                ->pluck('setting_value', 'setting_key');

            self::$defaults = [];
            foreach (self::CATEGORY_KEYS as $category => $key) {
                $value = $stored->get($key);
                self::$defaults[$category] = ($value === null || $value === '') ? null : (int) $value;
            }
        }

        return self::$defaults;
    }

    /** Call after the defaults are edited. */
    public static function forget(): void
    {
        self::$defaults = null;
    }

    /** Days allowed for this item, or null when it has no return period. */
    public static function daysFor(?Item $item): ?int
    {
        if (!$item || !$item->isReturnable()) {
            return null;
        }

        if ($item->return_days !== null) {
            return (int) $item->return_days;
        }

        $days = self::categoryDefaults()[$item->category] ?? null;

        return $days !== null && $days > 0 ? $days : null;
    }

    /**
     * Fields merged into every request-item payload.
     *
     *   returnDays   - allowed days (null = no due date)
     *   dueDate      - Y-m-d, or null
     *   overdue      - still has units out past the due date
     *   daysOverdue  - whole days past due (0 when not overdue)
     */
    public static function dueInfo(Request $request, RequestItem $requestItem): array
    {
        $none = ['returnDays' => null, 'dueDate' => null, 'overdue' => false, 'daysOverdue' => 0];

        $days = self::daysFor($requestItem->item);

        if ($days === null || !$request->borrowed_at || $request->status === 'Pending' || $request->status === 'Rejected') {
            return $none;
        }

        $due = Carbon::parse($request->borrowed_at)->startOfDay()->addDays($days);
        $today = Carbon::today();
        $stillOut = ($requestItem->quantity - $requestItem->returned_quantity) > 0;
        $overdue = $stillOut && $request->status === 'Approved' && $today->gt($due);

        return [
            'returnDays' => $days,
            'dueDate' => $due->toDateString(),
            'overdue' => $overdue,
            'daysOverdue' => $overdue ? (int) abs($due->diffInDays($today)) : 0,
        ];
    }
}
