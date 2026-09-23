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
            'price' => 'required|numeric|min:0',
            'item_type' => 'required|string|max:255',
            'quantity'  => 'required|integer|min:0',
        ]);

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
            'price' => 'required|numeric|min:0',
            'item_type' => 'required|string|max:255',
            'quantity'  => 'required|integer|min:0',
            'status'    => 'required|string|max:255',
        ]);

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