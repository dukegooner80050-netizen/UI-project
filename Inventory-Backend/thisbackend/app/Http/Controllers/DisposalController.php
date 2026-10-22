<?php

namespace App\Http\Controllers;

use App\Models\DisposalRecord;
use App\Models\Item;
use App\Traits\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Disposal step: units flagged for disposal at inspection (items.damaged_quantity)
// are formally disposed of here. Each disposal leaves a permanent record in
// disposal_records, so the history survives even if the item is deleted.
class DisposalController extends Controller
{
    use ActivityLogger;

    private const METHODS = ['Discarded', 'Sold as scrap', 'Donated', 'Recycled', 'Other'];

    // AMO only (route middleware).
    public function dispose(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
            'method' => 'required|in:' . implode(',', self::METHODS),
            'disposed_on' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            'notes' => 'nullable|string|max:255|required_if:method,Other',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($id, $validated, $user) {
            // Lock the row so two people can't dispose of the same units twice.
            $item = Item::lockForUpdate()->find($id);

            if (!$item) {
                return response()->json(['message' => 'Item not found.'], 404);
            }

            $waiting = (int) $item->damaged_quantity;

            if ($validated['quantity'] > $waiting) {
                return response()->json([
                    'message' => "Only {$waiting} unit(s) of {$item->item_name} are waiting for disposal.",
                ], 422);
            }

            $item->damaged_quantity = $waiting - $validated['quantity'];
            $item->save();

            $record = DisposalRecord::create([
                'iditems' => $item->iditems,
                'item_name' => $item->item_name,
                'category' => $item->category,
                'item_type' => $item->item_type,
                'quantity' => $validated['quantity'],
                'method' => $validated['method'],
                'notes' => isset($validated['notes']) ? trim($validated['notes']) : null,
                'disposed_on' => $validated['disposed_on'] ?? now()->toDateString(),
                'disposed_by' => $user->idUsers,
                'disposed_by_name' => $user->full_name ?? $user->username,
            ]);

            $this->logActivity(
                $user->idUsers,
                "Disposed {$validated['quantity']} of '{$item->item_name}' ({$validated['method']}), {$item->damaged_quantity} still waiting for disposal"
            );

            return response()->json([
                'message' => 'Disposal recorded.',
                'record' => $this->format($record),
                'remaining' => (int) $item->damaged_quantity,
            ], 201);
        });
    }

    // AMO only (route middleware): the permanent disposal history.
    public function index()
    {
        $records = DisposalRecord::orderByDesc('disposed_on')
            ->orderByDesc('iddisposal')
            ->limit(2000)
            ->get();

        return response()->json($records->map(fn ($r) => $this->format($r))->values());
    }

    private function format(DisposalRecord $r): array
    {
        return [
            'id' => $r->iddisposal,
            'item_name' => $r->item_name,
            'category' => $r->category,
            'item_type' => $r->item_type,
            'quantity' => (int) $r->quantity,
            'method' => $r->method,
            'notes' => $r->notes,
            'disposed_on' => optional($r->disposed_on)->format('Y-m-d'),
            'disposed_by' => $r->disposed_by_name,
        ];
    }
}
