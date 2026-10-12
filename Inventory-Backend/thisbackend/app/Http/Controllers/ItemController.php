<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\RequestItem;
use Illuminate\Http\Request;
use App\Traits\ActivityLogger;

class ItemController extends Controller
{
    use ActivityLogger;
    public function index()
{
    $items = Item::all();

    $items->each(function ($item) {
        $item->borrowedQty = $this->getBorrowedQuantity($item->iditems);
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
        $item->borrowedQty = $this->getBorrowedQuantity($item->iditems);
        return response()->json($item);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_name' => 'required|string|max:255|unique:items,item_name',
            'description' => 'nullable|string|max:255',
            'category'  => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'item_type' => 'required|string|max:255',
            'quantity'  => 'required|integer|min:0',
            'return_days' => 'nullable|integer|min:1|max:365',
            'limit_per_requester' => 'nullable|integer|min:1|max:1000',
            'critical_stock' => 'nullable|integer|min:0',
            'minimum_stock' => 'nullable|integer|min:0',
            'optimal_stock' => 'nullable|integer|min:0',
        ]);

        if ($error = $this->thresholdError($validated)) {
            return response()->json(['message' => $error], 422);
        }

        $validated['price'] = $validated['price'] ?? 0;

        $item = new Item($validated);

        if (!$item->canHaveLimit() && !empty($validated['limit_per_requester'])) {
            return response()->json([
                'message' => 'Fixed asset equipment cannot have a per-requester limit. Make it Limited Equipment first.'
            ], 422);
        }
        $this->updateItemStatus($item);
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

        $validated = $request->validate([
            'item_name' => 'required|string|max:255|unique:items,item_name,' . $id . ',iditems',
            'description' => 'nullable|string|max:255',
            'category'  => 'required|string|max:255',
            'price' => 'nullable|numeric|min:0',
            'item_type' => 'required|string|max:255',
            'quantity'  => 'required|integer|min:0',
            'return_days' => 'nullable|integer|min:1|max:365',
            'limit_per_requester' => 'nullable|integer|min:1|max:1000',
            'critical_stock' => 'nullable|integer|min:0',
            'minimum_stock' => 'nullable|integer|min:0',
            'optimal_stock' => 'nullable|integer|min:0',
            'status'    => 'sometimes|string|max:255',
        ]);

        if ($error = $this->thresholdError($validated, $item)) {
            return response()->json(['message' => $error], 422);
        }

        // Omitted price keeps its current value; status is always recomputed.
        if (array_key_exists('price', $validated) && $validated['price'] === null) {
            unset($validated['price']);
        }
        foreach (['critical_stock', 'minimum_stock', 'optimal_stock'] as $k) {
            if (array_key_exists($k, $validated) && $validated[$k] === null) {
                unset($validated[$k]);
            }
        }

        $item->fill($validated);

        // Fixed assets cannot have a limit: refuse an explicit one, and drop
        // any old one when an item is switched to a fixed asset.
        if (!$item->canHaveLimit()) {
            if (!empty($validated['limit_per_requester'])) {
                return response()->json([
                    'message' => 'Fixed asset equipment cannot have a per-requester limit. Make it Limited Equipment first.'
                ], 422);
            }
            $item->limit_per_requester = null;
        }

        $this->updateItemStatus($item);
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

$this->updateItemStatus($item);

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

$this->updateItemStatus($item);

$item->save();

    $this->logActivity(
        request()->user()->idUsers,
        "Released '{$item->item_name}' ({$validated['quantity']})"
    );

    return response()->json($item);
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

$this->updateItemStatus($item);

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

$this->updateItemStatus($item);

$item->save();

    $this->logActivity(
        request()->user()->idUsers,
        "Returned '{$item->item_name}' ({$validated['quantity']})"
    );

    return response()->json($item);
}

private function getBorrowedQuantity($itemId)
{
    // Issued items (Consumable and Type A) are handed over for good, so
    // none of them is ever out on loan.
    $type = Item::where('iditems', $itemId)->value('item_type');

    if (in_array($type, ['Consumable', 'Non-Consumable Type A'], true)) {
        return 0;
    }

    return RequestItem::where('iditems', $itemId)
        ->whereHas('request', function ($query) {
            $query->where('status', 'Approved');
        })
        ->get()
        ->sum(function ($requestItem) {
            return $requestItem->quantity - $requestItem->returned_quantity;
        });
}

// Critical <= Minimum <= Optimal, using the item's current values for
// anything the request did not send.
private function thresholdError(array $v, ?Item $item = null): ?string
{
    $c = (int) ($v['critical_stock'] ?? $item?->critical_stock ?? 2);
    $m = (int) ($v['minimum_stock'] ?? $item?->minimum_stock ?? 5);
    $o = (int) ($v['optimal_stock'] ?? $item?->optimal_stock ?? 20);

    return ($c > $m || $m > $o)
        ? 'Stock levels must satisfy: Critical <= Minimum <= Optimal.'
        : null;
}

private function updateItemStatus(Item $item)
{
    $item->status = $item->computeStatus();
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