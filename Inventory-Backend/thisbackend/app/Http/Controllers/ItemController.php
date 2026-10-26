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

    // Units out on approved requests, per item, in ONE grouped query. This used
    // to run two queries for every single item on every page that lists items.
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
            // Roadmap #10: fixed assets (a room's one projector, an aircon)
            // opt out of having a per-head limit at all. Defaults to true.
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
        // Levels left blank fall back to the Settings defaults for this kind of
        // item (School Equipment / Type B), else the built-in 2 / 5 / 20.
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

        // 'status' is no longer accepted from the client: it is always
        // recomputed from quantity, item type and the stock thresholds.
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
            // Sent as null to clear the item's own period (falls back to the
            // category default); left out entirely to keep it unchanged.
            'return_days'    => 'nullable|integer|min:1|max:3650',
            // Sent as null to clear the limit (no cap); left out entirely to
            // keep it unchanged. Unlike the thresholds below, a null here is
            // meaningful on purpose, so it's not folded back to the old value.
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

            // Becoming tracked: every past issuance would suddenly look like
            // an unreturned loan, so only allow it for items never requested.
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

// Roadmap #11: write off stock that went missing / lost / stolen. Admin
// only and applied immediately; every adjustment is stored in
// stock_adjustments (status 'Applied', so an approval step can be added
// later) and written to the Activity Logs.
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
    // Issued items (Consumable / Type A) are never returned, so they are
    // never "borrowed".
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

// Roadmap #10: a fixed-asset item (is_limitable = false) can't carry a
// per-head limit at all. $item is passed on update() so an unsent
// per_head_limit still falls back to whatever the item currently has.
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