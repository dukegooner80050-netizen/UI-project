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
            'price' => 'required_if:category,Uniforms|nullable|numeric|min:0',
            'item_type' => 'required|string|max:255',
            'quantity'  => 'required|integer|min:0',
            'critical_stock' => 'nullable|integer|min:0',
            'minimum_stock'  => 'nullable|integer|min:0',
            'optimal_stock'  => 'nullable|integer|min:0',
            'return_days'    => 'nullable|integer|min:1|max:3650',
        ]);

        if ($error = $this->itemTypeError($validated['category'], $validated['item_type'])) {
            return $error;
        }

        $validated['price'] = $validated['price'] ?? 0;
        $validated['critical_stock'] = $validated['critical_stock'] ?? Item::DEFAULT_CRITICAL;
        $validated['minimum_stock']  = $validated['minimum_stock']  ?? Item::DEFAULT_MINIMUM;
        $validated['optimal_stock']  = $validated['optimal_stock']  ?? Item::DEFAULT_OPTIMAL;

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

        if ($error = $this->thresholdError($validated)) {
            return $error;
        }

        if ($error = $this->itemTypeError($validated['category'], $validated['item_type'])) {
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
            if (!$willBeReturnable && $wasReturnable && $this->getBorrowedQuantity($item->iditems) > 0) {
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

private function getBorrowedQuantity($itemId)
{
    // Issued items (Consumable / Type A) are never returned, so they are
    // never "borrowed".
    $item = Item::find($itemId);
    if ($item && !$item->isReturnable()) {
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