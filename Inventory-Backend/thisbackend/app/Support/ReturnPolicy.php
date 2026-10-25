<?php

namespace App\Support;

use App\Models\Item;
use App\Models\Request;
use App\Models\ReturnReport;
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

    // requestItem id => units waiting to be checked. Filled by
    // preloadPending() so a list of requests costs ONE query for this
    // instead of one per line.
    private static ?array $pendingMap = null;

    /** @param iterable<int> $requestItemIds */
    public static function preloadPending(iterable $requestItemIds): void
    {
        $ids = [];
        foreach ($requestItemIds as $id) {
            if ($id) {
                $ids[(int) $id] = 0;
            }
        }

        if ($ids) {
            $rows = ReturnReport::where('status', 'Pending')
                ->whereIn('idrequestItem', array_keys($ids))
                ->selectRaw('idrequestItem, SUM(quantity) AS qty')
                ->groupBy('idrequestItem')
                ->pluck('qty', 'idrequestItem');

            foreach ($rows as $id => $qty) {
                $ids[(int) $id] = (int) $qty;
            }
        }

        self::$pendingMap = $ids;
    }

    public static function forgetPending(): void
    {
        self::$pendingMap = null;
    }

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
     *   defaultReturnDays - the item's own / category default period, so the
     *                       approval screen can prefill a due date (null = none)
     *   returnDays   - allowed days (null = no due date)
     *   dueDate      - Y-m-d, or null
     *   overdue      - still has units out past the due date
     *   daysOverdue  - whole days past due (0 when not overdue)
     */
    public static function dueInfo(Request $request, RequestItem $requestItem): array
    {
        $default = self::daysFor($requestItem->item);

        // Units the borrower says they already brought back, still waiting
        // for the AMO to check. They are not counted as overdue meanwhile.
        $id = $requestItem->idrequestItem;
        if (!$id) {
            $pendingReturn = 0;
        } elseif (self::$pendingMap !== null && array_key_exists((int) $id, self::$pendingMap)) {
            $pendingReturn = self::$pendingMap[(int) $id];
        } else {
            $pendingReturn = ReturnReport::pendingQuantity($id);
        }

        $none = [
            'pendingReturnQty' => $pendingReturn,
            'defaultReturnDays' => $default,
            'returnDays' => null,
            'dueDate' => null,
            'overdue' => false,
            'daysOverdue' => 0,
        ];

        if (!$request->borrowed_at || in_array($request->status, ['Pending', 'Rejected', 'Cancelled'], true)) {
            return $none;
        }

        // The clock starts when the item is actually in the borrower's hands:
        // their receipt for this line, else the request's receipt, else the
        // release. Older approved requests (items deducted at approval, never
        // released) fall back to the approval date. Reserved items that have
        // not been handed over yet have no due date.
        $basis = $requestItem->received_at
            ?? $request->received_at
            ?? $request->released_at
            ?? ($request->stock_held ? null : $request->borrowed_at);
        if (!$basis) {
            return $none;
        }

        // A due date picked by the admin at approval wins over the default
        // period. Only tracked (returnable) items ever get one.
        $borrowed = Carbon::parse($basis)->startOfDay();
        if ($requestItem->due_date && $requestItem->item && $requestItem->item->isReturnable()) {
            // A date the AMO picked stays that exact date.
            $due = Carbon::parse($requestItem->due_date)->startOfDay();
            $days = (int) abs($borrowed->diffInDays($due));
        } elseif ($default !== null) {
            $days = $default;
            $due = $borrowed->copy()->addDays($days);
        } else {
            return $none;
        }
        $today = Carbon::today();
        $stillOut = ($requestItem->quantity - $requestItem->returned_quantity - $pendingReturn) > 0;
        $overdue = $stillOut && $request->status === 'Approved' && $today->gt($due);

        return [
            'pendingReturnQty' => $pendingReturn,
            'defaultReturnDays' => $default,
            'returnDays' => $days,
            'dueDate' => $due->toDateString(),
            'overdue' => $overdue,
            'daysOverdue' => $overdue ? (int) abs($due->diffInDays($today)) : 0,
        ];
    }
}
