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
            'request_limit' => 'nullable|integer|min:1',
        ]);

        // Per-request cap only applies to School Equipment.
        if ($validated['category'] !== 'School Equipment') {
            $validated['request_limit'] = null;
        }

        $item = new Item($validated);
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
            'status'    => 'required|string|max:255',
            'request_limit' => 'nullable|integer|min:1',
        ]);

        // Per-request cap only applies to School Equipment.
        if ($validated['category'] !== 'School Equipment') {
            $validated['request_limit'] = null;
        }

        $item->update($validated);
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

// Record missing / lost / stolen stock. Admin-only and applied immediately
// (the approval question is still open with the client). Only AVAILABLE
// stock can be written off; items currently out on loan are handled
// through the return/inspection flow instead.
public function adjustStock(Request $request, $id)
{
    $validated = $request->validate([
        'quantity' => 'required|integer|min:1',
        'reason'   => 'required|in:Missing,Lost,Stolen,Other',
        'notes'    => 'required_if:reason,Other|nullable|string|max:255',
    ]);

    $result = DB::transaction(function () use ($validated, $id) {
        $item = Item::lockForUpdate()->find($id);

        if (!$item) {
            return ['error' => 'Item not found.', 'code' => 404];
        }

        if ($validated['quantity'] > $item->quantity) {
            return [
                'error' => "Only {$item->quantity} available. You can't write off more than the available stock.",
                'code' => 422,
            ];
        }

        $previous = (int) $item->quantity;
        $item->quantity = $previous - (int) $validated['quantity'];
        $this->updateItemStatus($item);
        $item->save();

        $adjustment = StockAdjustment::create([
            'iditems' => $item->iditems,
            'idUsers' => request()->user()->idUsers,
            'quantity' => $validated['quantity'],
            'previous_quantity' => $previous,
            'new_quantity' => $item->quantity,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'Applied',
        ]);

        $note = !empty($validated['notes']) ? " - {$validated['notes']}" : '';
        $this->logActivity(
            request()->user()->idUsers,
            "Stock adjustment: '{$item->item_name}' -{$validated['quantity']} ({$validated['reason']}){$note}"
        );

        return ['item' => $item, 'adjustment' => $adjustment];
    });

    if (isset($result['error'])) {
        return response()->json(['message' => $result['error']], $result['code']);
    }

    return response()->json([
        'message' => 'Stock adjusted.',
        'item' => $result['item'],
        'adjustment' => $result['adjustment'],
    ]);
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
    return RequestItem::where('iditems', $itemId)
        ->whereHas('request', function ($query) {
            $query->where('status', 'Approved');
        })
        ->get()
        ->sum(function ($requestItem) {
            return $requestItem->quantity - $requestItem->returned_quantity;
        });
}

private function updateItemStatus(Item $item)
{
    if (
        $item->category === "Office Supplies" &&
        $item->item_type === "Consumable"
    ) {

        if ($item->quantity <= 0) {
            $item->status = "Out of Stock";
        } elseif ($item->quantity <= 5) {
            $item->status = "Low Stock";
        } else {
            $item->status = "Available";
        }

        return;
    }

    if ($item->quantity <= 0) {
        $item->status = "Borrowed";
    } else {
        $item->status = "Available";
    }
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