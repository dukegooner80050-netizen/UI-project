<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\RestockRequest;
use App\Traits\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestockRequestController extends Controller
{
    use ActivityLogger;

    // Only these categories are stocked through the Items table restock flow.
    private const CATEGORIES = ['Office Supplies', 'School Equipment'];

    // Statuses that mean "a restock is already on its way".
    private const ACTIVE = ['Open', 'Ordered'];

    /**
     * Items at or below their Minimum stock level, most critical first.
     * Level: Critical (<= critical_stock) or Minimum (<= minimum_stock).
     */
    public function lowStock()
    {
        $items = Item::whereIn('category', self::CATEGORIES)
            ->whereColumn('quantity', '<=', 'minimum_stock')
            ->orderBy('quantity')
            ->get()
            // School Equipment and Type B items with an empty shelf that are
            // only out on loan or placed in rooms are not out of stock, so
            // there is nothing to restock.
            ->reject(function ($item) {
                $away = $item->awayUnits();
                return $away !== null && ($away['borrowed'] > 0 || $away['deployed'] > 0);
            })
            ->values();

        $active = RestockRequest::whereIn('iditems', $items->pluck('iditems'))
            ->whereIn('status', self::ACTIVE)
            ->get()
            ->keyBy('iditems');

        return response()->json($items->map(function ($item) use ($active) {
            $req = $active->get($item->iditems);

            return [
                'iditems' => $item->iditems,
                'item_name' => $item->item_name,
                'category' => $item->category,
                'item_type' => $item->item_type,
                'quantity' => (int) $item->quantity,
                'critical_stock' => (int) $item->critical_stock,
                'minimum_stock' => (int) $item->minimum_stock,
                'optimal_stock' => (int) $item->optimal_stock,
                'level' => $item->quantity <= $item->critical_stock ? 'Critical' : 'Minimum',
                'suggested_quantity' => max((int) $item->optimal_stock - (int) $item->quantity, 1),
                'active_request_id' => $req?->idrestock,
                'active_request_status' => $req?->status,
            ];
        })->values());
    }

    public function index(Request $request)
    {
        $query = RestockRequest::with(['item', 'requester'])->latest('idrestock');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        } else {
            $query->whereIn('status', self::ACTIVE);
        }

        return response()->json($query->get()->map(fn ($r) => $this->format($r))->values());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'iditems' => 'required|exists:items,iditems',
            'quantity_needed' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:255',
        ]);

        $item = Item::find($validated['iditems']);

        if (!in_array($item->category, self::CATEGORIES, true)) {
            return response()->json([
                'message' => 'Restock requests are only for Office Supplies and School Equipment.',
            ], 422);
        }

        $exists = RestockRequest::where('iditems', $item->iditems)
            ->whereIn('status', self::ACTIVE)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => "There is already an active restock request for {$item->item_name}.",
            ], 422);
        }

        $restock = RestockRequest::create([
            'iditems' => $item->iditems,
            'requested_by' => $request->user()->idUsers,
            'quantity_needed' => $validated['quantity_needed'],
            'status' => 'Open',
            'notes' => $validated['notes'] ?? null,
        ]);

        $this->logActivity(
            $request->user()->idUsers,
            "Restock request #{$restock->idrestock}: '{$item->item_name}' x{$validated['quantity_needed']}"
        );

        return response()->json($this->format($restock->load(['item', 'requester'])), 201);
    }

    /**
     * Open -> Ordered | Received | Cancelled
     * Ordered -> Received | Cancelled
     * Receiving adds the stock to the item using the existing restock logic.
     */
    public function updateStatus(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:Ordered,Received,Cancelled',
            'quantity_received' => 'nullable|integer|min:1',
        ]);

        $result = DB::transaction(function () use ($validated, $id, $request) {
            $restock = RestockRequest::with('item')->lockForUpdate()->find($id);

            if (!$restock) {
                return ['error' => 'Restock request not found.', 'code' => 404];
            }

            if (!in_array($restock->status, self::ACTIVE, true)) {
                return ['error' => "This request is already {$restock->status}.", 'code' => 422];
            }

            $new = $validated['status'];

            if ($new === 'Ordered' && $restock->status !== 'Open') {
                return ['error' => 'This request is already marked as ordered.', 'code' => 422];
            }

            $userId = $request->user()->idUsers;

            if ($new === 'Ordered') {
                $restock->status = 'Ordered';
                $restock->ordered_at = now();
                $restock->save();
                $this->logActivity($userId, "Restock request #{$restock->idrestock}: '{$restock->item->item_name}' marked Ordered");
            } elseif ($new === 'Cancelled') {
                $restock->status = 'Cancelled';
                $restock->closed_at = now();
                $restock->closed_by = $userId;
                $restock->save();
                $this->logActivity($userId, "Restock request #{$restock->idrestock}: '{$restock->item->item_name}' Cancelled");
            } else {
                $qty = (int) ($validated['quantity_received'] ?? $restock->quantity_needed);

                // Reuse the existing item restock so stock status rules stay in one place.
                $response = app(ItemController::class)->restock(
                    new Request(['quantity' => $qty]),
                    $restock->iditems
                );

                if ($response->getStatusCode() >= 400) {
                    return ['error' => 'Unable to add stock to the item.', 'code' => 422];
                }

                $restock->status = 'Received';
                $restock->quantity_received = $qty;
                $restock->closed_at = now();
                $restock->closed_by = $userId;
                $restock->save();
                $this->logActivity($userId, "Restock request #{$restock->idrestock}: '{$restock->item->item_name}' Received (+{$qty})");
            }

            return ['restock' => $restock->fresh(['item', 'requester'])];
        });

        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['code']);
        }

        return response()->json($this->format($result['restock']));
    }

    private function format(RestockRequest $r): array
    {
        return [
            'id' => $r->idrestock,
            'iditems' => $r->iditems,
            'item_name' => optional($r->item)->item_name,
            'category' => optional($r->item)->category,
            'current_quantity' => optional($r->item)->quantity,
            'quantity_needed' => $r->quantity_needed,
            'quantity_received' => $r->quantity_received,
            'status' => $r->status,
            'notes' => $r->notes,
            'requested_by' => optional($r->requester)->full_name,
            'created_at' => $r->created_at,
            'ordered_at' => $r->ordered_at,
            'closed_at' => $r->closed_at,
        ];
    }
}
