<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\RequestItem;
use App\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Traits\ActivityLogger;

class ItemController extends Controller
{
    use ActivityLogger;
    public function index()
{
    $items = Item::all();

    // Units placed in rooms (Equipment Distribution), one query for all items.
    $deployed = DB::table('room_equipment')
        ->select('iditems', DB::raw('SUM(quantity) as total'))
        ->groupBy('iditems')
        ->pluck('total', 'iditems');

    /* Units out on approved requests per item, in one grouped query. */
    $borrowed = DB::table('requestitem')
        ->join('request', 'request.idrequest', '=', 'requestitem.idrequest')
        ->where('request.status', 'Approved')
        ->where('request.stock_held', false)
        ->groupBy('requestitem.iditems')
        ->selectRaw('requestitem.iditems AS iditems, SUM(requestitem.quantity - requestitem.returned_quantity) AS total')
        ->pluck('total', 'iditems');

    $items->each(function ($item) use ($deployed, $borrowed) {
        // Issued items (Consumable / Type A) are never borrowed.
        $item->borrowedQty = $item->isReturnable()
            ? (int) ($borrowed[$item->iditems] ?? 0)
            : 0;
        $item->deployedQty = (int) ($deployed[$item->iditems] ?? 0);
    });

    return response()->json($items);
}

    public function show($id)
    {
        $item = Item::find($id);
        
        if (!$item) {
            return response()->json([
                'message' => 'Item not found.'
            ], 404);
        }
        $item->borrowedQty = $this->getBorrowedQuantity($item->iditems, $item);
        $item->deployedQty = (int) DB::table('room_equipment')->where('iditems', $item->iditems)->sum('quantity');
        return response()->json($item);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_name' => 'required|string|max:255|unique:items,item_name',
            'description' => 'nullable|string|max:255',
            'category'  => 'required|string|max:255',
            'price' => 'required_if:category,Uniforms|nullable|numeric|min:0',
            'item_type' => 'required|string|max:255',
            'quantity'  => 'required|integer|min:0',
            'critical_stock' => 'nullable|integer|min:0',
            'minimum_stock'  => 'nullable|integer|min:0',
            'optimal_stock'  => 'nullable|integer|min:0',
            'return_days'    => 'nullable|integer|min:1|max:3650',
            'per_head_limit' => 'nullable|integer|min:1',
            /* Fixed assets (e.g. a projector) can opt out of per-head limits. Default: true. */
            'is_limitable'   => 'sometimes|boolean',
        ]);

        if ($error = $this->itemTypeError($validated['category'], $validated['item_type'])) {
            return $error;
        }

        $validated['is_limitable'] = $validated['is_limitable'] ?? true;

        if ($error = $this->perHeadLimitError($validated)) {
            return $error;
        }

        $validated['price'] = $validated['price'] ?? 0;
        /* Blank stock levels use the Settings defaults, else the built-in 2 / 5 / 20. */
        $group = \App\Http\Controllers\SettingController::stockGroupFor($validated['category'], $validated['item_type']);
        $defaults = $group
            ? \App\Http\Controllers\SettingController::stockLevelsFor($group)
            : ['critical' => Item::DEFAULT_CRITICAL, 'minimum' => Item::DEFAULT_MINIMUM, 'optimal' => Item::DEFAULT_OPTIMAL];
        $validated['critical_stock'] = $validated['critical_stock'] ?? $defaults['critical'];
        $validated['minimum_stock']  = $validated['minimum_stock']  ?? $defaults['minimum'];
        $validated['optimal_stock']  = $validated['optimal_stock']  ?? $defaults['optimal'];

        if ($error = $this->thresholdError($validated)) {
            return $error;
        }

        $item = new Item($validated);
        $item->refreshStatus();
        $item->save();
        $this->logActivity(
    request()->user()->idUsers,
    "Created Item '{$item->item_name}'"
);

        return response()->json([
            'message' => 'Item created successfully.',
            'item' => $item,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $item = Item::find($id);

        if (!$item) {
            return response()->json([
                'message' => 'Item not found.'
            ], 404);
        }

        /* Status is never taken from the client; it is recomputed from quantity and thresholds. */
        $validated = $request->validate([
            'item_name' => 'required|string|max:255|unique:items,item_name,' . $id . ',iditems',
            'description' => 'nullable|string|max:255',
            'category'  => 'required|string|max:255',
            'price' => 'required_if:category,Uniforms|nullable|numeric|min:0',
            'item_type' => 'required|string|max:255',
            'quantity'  => 'required|integer|min:0',
            'critical_stock' => 'nullable|integer|min:0',
            'minimum_stock'  => 'nullable|integer|min:0',
            'optimal_stock'  => 'nullable|integer|min:0',
            /* null clears the item's own return period (category default applies); omitted keeps it. */
            'return_days'    => 'nullable|integer|min:1|max:3650',
            /* null clears the limit (no cap); omitted keeps it. */
            'per_head_limit' => 'nullable|integer|min:1',
            // Roadmap #10: not sent keeps the item's current value.
            'is_limitable'   => 'sometimes|boolean',
        ]);

        if (!array_key_exists('price', $validated) || $validated['price'] === null) {
            unset($validated['price']);
        }

        // A threshold that was not sent keeps its current value.
        foreach (['critical_stock', 'minimum_stock', 'optimal_stock'] as $field) {
            if (!array_key_exists($field, $validated) || $validated[$field] === null) {
                $validated[$field] = $item->$field;
            }
        }

        if (!array_key_exists('is_limitable', $validated)) {
            $validated['is_limitable'] = $item->is_limitable;
        }

        if ($error = $this->thresholdError($validated)) {
            return $error;
        }

        if ($error = $this->itemTypeError($validated['category'], $validated['item_type'])) {
            return $error;
        }

        if ($error = $this->perHeadLimitError($validated, $item)) {
            return $error;
        }

        if ($validated['item_type'] !== $item->item_type) {
            $wasReturnable = $item->isReturnable();
            $willBeReturnable = !in_array($validated['item_type'], Item::nonReturnableTypes(), true);

            /* Only never-requested items can become tracked; past issuances would look unreturned. */
            if (
                $willBeReturnable && !$wasReturnable &&
                RequestItem::where('iditems', $item->iditems)->exists()
            ) {
                return response()->json([
                    'message' => 'This item has already been requested as an issued item, so it cannot be switched to a tracked (must-return) type.'
                ], 422);
            }

            // Becoming issued: units still out on loan would have no way back.
            if (!$willBeReturnable && $wasReturnable && $this->getBorrowedQuantity($item->iditems, $item) > 0) {
                return response()->json([
                    'message' => 'Cannot switch to an issued type while units are still borrowed. Have them returned first.'
                ], 422);
            }
        }

        $item->fill($validated);
        $item->refreshStatus();
        $item->save();
        $this->logActivity(
    request()->user()->idUsers,
    "Updated Item '{$item->item_name}'"
);

        return response()->json([
            'message' => 'Item updated successfully.',
            'item' => $item,
        ]);
    }

public function restock(Request $request, $id)
{
    $item = Item::find($id);

    if (!$item) {
        return response()->json([
            'message' => 'Item not found.'
        ], 404);
    }

    $validated = $request->validate([
        'quantity' => 'required|integer|min:1',
    ]);

    $item->quantity += $validated['quantity'];

$item->refreshStatus();

$item->save();

    $this->logActivity(
        request()->user()->idUsers,
        "Restocked '{$item->item_name}' (+{$validated['quantity']})"
    );

    return response()->json($item);
}

public function release(Request $request, $id)
{
    $item = Item::find($id);

    if (!$item) {
        return response()->json([
            'message' => 'Item not found.'
        ], 404);
    }

    $validated = $request->validate([
        'quantity' => 'required|integer|min:1',
    ]);

    if ($validated['quantity'] > $item->quantity) {
        return response()->json([
            'message' => 'Not enough stock.'
        ], 400);
    }

$item->quantity -= $validated['quantity'];

$item->refreshStatus();

$item->save();

    $this->logActivity(
        request()->user()->idUsers,
        "Released '{$item->item_name}' ({$validated['quantity']})"
    );

    return response()->json($item);
}

// AMO: outstanding Missing / Lost / Stolen write-offs and recovered history.
public function missingStock()
{
    $query = StockAdjustment::with([
        'item:iditems,item_name,category',
        'user:idUsers,full_name,username',
    ])->whereIn('reason', ['Missing', 'Lost', 'Stolen']);

    $open = (clone $query)
        ->whereColumn('found_quantity', '<', 'quantity')
        ->orderByDesc('created_at')
        ->get();

    $history = (clone $query)
        ->whereColumn('found_quantity', '>=', 'quantity')
        ->orderByDesc('found_at')
        ->limit(500)
        ->get();

    return response()->json([
        'open' => $open->map(fn ($record) => $this->formatMissingStock($record))->values(),
        'history' => $history->map(fn ($record) => $this->formatMissingStock($record))->values(),
        'missing_total' => (int) $open->sum(fn ($record) => max(0, (int) $record->quantity - (int) $record->found_quantity)),
    ]);
}

// Record units that have turned up again; return them to available inventory.
public function markStockFound(Request $request, $id)
{
    $validated = $request->validate([
        'quantity' => 'required|integer|min:1',
    ]);

    $user = $request->user();
    $userName = $user->full_name ?? $user->username;

    return DB::transaction(function () use ($id, $validated, $user, $userName) {
        $record = StockAdjustment::whereIn('reason', ['Missing', 'Lost', 'Stolen'])
            ->lockForUpdate()
            ->find($id);

        if (!$record) {
            return response()->json(['message' => 'Missing-stock record not found.'], 404);
        }

        $remaining = max(0, (int) $record->quantity - (int) $record->found_quantity);
        $quantity = (int) $validated['quantity'];

        if ($quantity > $remaining) {
            return response()->json([
                'message' => "Only {$remaining} unit(s) remain marked missing in this record.",
            ], 422);
        }

        $item = Item::lockForUpdate()->find($record->iditems);

        if (!$item) {
            return response()->json(['message' => 'The inventory item no longer exists.'], 422);
        }

        $item->quantity += $quantity;
        $item->refreshStatus();
        $item->save();

        $record->found_quantity = (int) $record->found_quantity + $quantity;
        $record->found_at = now();
        $record->found_by_name = $userName;
        $record->save();

        $itemName = $record->item?->item_name ?? $item->item_name;
        $this->logActivity(
            $user->idUsers,
            "Marked {$quantity} of '{$itemName}' found from {$record->reason} stock report #{$record->idstockadj}"
        );

        return response()->json([
            'message' => "{$quantity} unit(s) returned to available stock.",
            'remaining' => max(0, (int) $record->quantity - (int) $record->found_quantity),
        ]);
    });
}

private function formatMissingStock(StockAdjustment $record): array
{
    $sentAt = $record->created_at;
    $daysMissing = $sentAt
        ? (int) $sentAt->copy()->startOfDay()->diffInDays(now()->startOfDay())
        : null;

    return [
        'id' => $record->idstockadj,
        'item_id' => $record->iditems,
        'item_name' => $record->item?->item_name ?? 'Deleted item',
        'category' => $record->item?->category,
        'quantity' => (int) $record->quantity,
        'found_quantity' => (int) $record->found_quantity,
        'remaining' => max(0, (int) $record->quantity - (int) $record->found_quantity),
        'reason' => $record->reason,
        'notes' => $record->notes,
        'reported_at' => optional($sentAt)->format('Y-m-d H:i:s'),
        'reported_by' => $record->user?->full_name ?? $record->user?->username ?? 'Unknown user',
        'days_missing' => $daysMissing,
        'found_at' => optional($record->found_at)->format('Y-m-d H:i:s'),
        'found_by' => $record->found_by_name,
    ];
}

/* AMO writes off missing, lost or stolen stock; saved in stock_adjustments and the logs. */
public function adjustStock(Request $request, $id)
{
    $validated = $request->validate([
        'quantity' => 'required|integer|min:1',
        'reason' => 'required|in:Missing,Lost,Stolen,Other',
        'notes' => 'nullable|string|max:255|required_if:reason,Other',
    ]);

    $userId = $request->user()->idUsers;

    return DB::transaction(function () use ($id, $validated, $userId) {
        // Lock the row so two admins can't write off the same stock at once.
        $item = Item::lockForUpdate()->find($id);

        if (!$item) {
            return response()->json(['message' => 'Item not found.'], 404);
        }

        $available = (int) $item->quantity;

        if ($validated['quantity'] > $available) {
            return response()->json([
                'message' => "Only {$available} available. You can't write off more than the available stock."
            ], 422);
        }

        $newQuantity = $available - $validated['quantity'];

        StockAdjustment::create([
            'iditems' => $item->iditems,
            'idUsers' => $userId,
            'quantity' => $validated['quantity'],
            'previous_quantity' => $available,
            'new_quantity' => $newQuantity,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'Applied',
        ]);

        $item->quantity = $newQuantity;
        $item->refreshStatus();
        $item->save();

        $description = "Stock adjustment: removed {$validated['quantity']} of '{$item->item_name}' as {$validated['reason']} ({$available} -> {$newQuantity})";

        if (!empty($validated['notes'])) {
            $description .= " - {$validated['notes']}";
        }

        $this->logActivity($userId, $description);

        return response()->json([
            'message' => 'Stock adjusted.',
            'item' => $item,
        ]);
    });
}

public function borrow(Request $request, $id)
{
    $item = Item::find($id);

    if (!$item) {
        return response()->json([
            'message' => 'Item not found.'
        ], 404);
    }

    $validated = $request->validate([
        'quantity' => 'required|integer|min:1',
    ]);

    if ($validated['quantity'] > $item->quantity) {
        return response()->json([
            'message' => 'Not enough stock.'
        ], 400);
    }

$item->quantity -= $validated['quantity'];

$item->refreshStatus();

$item->save();

    $this->logActivity(
        request()->user()->idUsers,
        "Borrowed '{$item->item_name}' ({$validated['quantity']})"
    );

    return response()->json($item);
}

public function returnItem(Request $request, $id)
{
    $item = Item::find($id);

    if (!$item) {
        return response()->json([
            'message' => 'Item not found.'
        ], 404);
    }

    $validated = $request->validate([
        'quantity' => 'required|integer|min:1',
    ]);

$item->quantity += $validated['quantity'];

$item->refreshStatus();

$item->save();

    $this->logActivity(
        request()->user()->idUsers,
        "Returned '{$item->item_name}' ({$validated['quantity']})"
    );

    return response()->json($item);
}

private function getBorrowedQuantity($itemId, ?Item $item = null)
{
    /* Issued items (Consumable / Type A) are never borrowed. */
    $item = $item ?? Item::find($itemId);
    if ($item && !$item->isReturnable()) {
        return 0;
    }

    return RequestItem::where('iditems', $itemId)
        ->whereHas('request', function ($query) {
            $query->where('status', 'Approved')->where('stock_held', false);
        })
        ->get()
        ->sum(function ($requestItem) {
            return $requestItem->quantity - $requestItem->returned_quantity;
        });
}

private function itemTypeError($category, $type)
{
    if (
        $category === 'Office Supplies' &&
        !in_array($type, [Item::TYPE_CONSUMABLE, Item::TYPE_A, Item::TYPE_B], true)
    ) {
        return response()->json([
            'message' => 'Office supplies must be Consumable, Non-Consumable Type A or Non-Consumable Type B.'
        ], 422);
    }

    return null;
}

/* Fixed assets can't have a per-head limit; an unsent value keeps the item's current one. */
private function perHeadLimitError(array $data, ?Item $item = null)
{
    if (!empty($data['is_limitable'])) {
        return null;
    }

    $limit = array_key_exists('per_head_limit', $data)
        ? $data['per_head_limit']
        : optional($item)->per_head_limit;

    if ($limit !== null) {
        return response()->json([
            'message' => 'Fixed-asset items cannot have a per-head limit. Mark it limitable first, or clear the limit.'
        ], 422);
    }

    return null;
}

private function thresholdError(array $data)
{
    $critical = (int) $data['critical_stock'];
    $minimum  = (int) $data['minimum_stock'];
    $optimal  = (int) $data['optimal_stock'];

    if ($critical > $minimum || $minimum > $optimal) {
        return response()->json([
            'message' => 'Stock levels must satisfy: Critical <= Minimum <= Optimal.'
        ], 422);
    }

    return null;
}

    public function destroy($id)
    {
        $item = Item::find($id);

        if (!$item) {
            return response()->json([
                'message' => 'Item not found.'
            ], 404);
        }

        $name = $item->item_name;

$item->delete();

$this->logActivity(
    request()->user()->idUsers,
    "Deleted Item '{$name}'"
);

        return response()->json([
            'message' => 'Item deleted successfully.'
        ]);
    }
}