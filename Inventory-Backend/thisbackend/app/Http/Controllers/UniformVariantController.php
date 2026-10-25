<?php

namespace App\Http\Controllers;

use App\Models\UniformVariant;
use Illuminate\Http\Request;
use App\Traits\ActivityLogger;

class UniformVariantController extends Controller
{
    use ActivityLogger;

    public function index()
    {
        return response()->json(
            UniformVariant::with(['department', 'type'])->get()
        );
    }

    public function show($id)
    {
        $variant = UniformVariant::with(['department', 'type'])
            ->find($id);

        if (!$variant) {
            return response()->json([
                'message' => 'Uniform variant not found.'
            ], 404);
        }

        return response()->json($variant);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'idUniftype' => 'required|exists:uniformType,idUniftype',
            'iddept'     => 'required|exists:dept,iddept',
            'size'       => 'required|string|max:50',
            'gender'     => 'required|in:Male,Female,Unisex',
            'price'      => 'required|numeric|min:0',
            'quantity'   => 'required|integer|min:0',
        ]);

        $variant = UniformVariant::create($validated);

        $variant->load(['department', 'type']);

        $this->logActivity(
            request()->user()->idUsers,
            "Created Uniform Variant {$variant->type->uniform_name} ({$variant->department->dept_name} - {$variant->size})"
        );

        return response()->json([
            'message' => 'Uniform variant created successfully.',
            'variant' => $variant,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $variant = UniformVariant::find($id);

        if (!$variant) {
            return response()->json([
                'message' => 'Uniform variant not found.'
            ], 404);
        }

        $validated = $request->validate([
            'idUniftype' => 'required|exists:uniformType,idUniftype',
            'iddept'     => 'required|exists:dept,iddept',
            'size'       => 'required|string|max:50',
            'gender'     => 'required|in:Male,Female,Unisex',
            'price'      => 'required|numeric|min:0',
            'quantity'   => 'required|integer|min:0',
        ]);

        $variant->update($validated);

        $variant->load(['department', 'type']);

        $this->logActivity(
            request()->user()->idUsers,
            "Updated Uniform Variant {$variant->type->uniform_name} ({$variant->department->dept_name} - {$variant->size})"
        );

        return response()->json([
            'message' => 'Uniform variant updated successfully.',
            'variant' => $variant,
        ]);
    }

    public function destroy($id)
    {
        $variant = UniformVariant::find($id);

        if (!$variant) {
            return response()->json([
                'message' => 'Uniform variant not found.'
            ], 404);
        }

        $variant->load(['department', 'type']);

        $description =
            $variant->type->uniform_name .
            " (" .
            $variant->department->dept_name .
            " - " .
            $variant->size .
            ")";

        $variant->delete();

        $this->logActivity(
            request()->user()->idUsers,
            "Deleted Uniform Variant {$description}"
        );

        return response()->json([
            'message' => 'Uniform variant deleted successfully.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RESTOCK UNIFORM
    |--------------------------------------------------------------------------
    */

    public function restock(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $variant = UniformVariant::find($id);

        if (!$variant) {
            return response()->json([
                'message' => 'Uniform variant not found.'
            ], 404);
        }

        $variant->quantity += $validated['quantity'];
        $variant->save();

        // Fulfil the oldest waiting reservations that the new stock covers.
        // The student already paid, so each one becomes an Approved request
        // for the AMO right away (stock held), ready for pickup. The cashier
        // is notified through the bell.
        $available = max(0, (int) $variant->quantity - (int) ($variant->reserved_quantity ?? 0));
        $waiting = \App\Models\UniformReservation::where('idUnifvariant', $variant->idUnifvariant)
            ->where('status', 'Waiting')
            ->orderBy('created_at')
            ->orderBy('idreservation')
            ->get();
        $actorId = request()->user()->idUsers ?? null;
        $variant->load(['type', 'department']);
        $label = $variant->type->uniform_name . ' (' . $variant->department->dept_name . ' - ' . ($variant->gender ?? 'Unisex') . ' - ' . $variant->size . ')';
        foreach ($waiting as $reservation) {
            if ((int) $reservation->quantity > $available) {
                continue;
            }
            $available -= (int) $reservation->quantity;
            // Hold the stock until the AMO hands the uniform over.
            $variant->reserved_quantity = (int) ($variant->reserved_quantity ?? 0) + (int) $reservation->quantity;

            \Illuminate\Support\Facades\DB::transaction(function () use ($reservation, $actorId, $label) {
                $req = \App\Models\Request::create([
                    'idUsers' => $reservation->idUsers,
                    'approved_by' => $actorId,
                    'request_date' => now(),
                    'status' => 'Approved',
                    'location' => 'AMO Office',
                    'room' => 'N/A',
                    'purpose' => 'Reserved uniform (paid) - stock arrived',
                    'approval_remarks' => 'Created automatically from a paid reservation.',
                    'borrowed_at' => now(),
                    'notified_at' => now(),
                    'notified_by' => $actorId,
                    'student_name' => $reservation->student_name,
                    'student_number' => $reservation->student_number,
                    'paid_at' => $reservation->paid_at ?? now(),
                    'paid_by' => $reservation->idUsers,
                ]);
                \App\Models\RequestUniform::create([
                    'idrequest' => $req->idrequest,
                    'idUnifvariant' => $reservation->idUnifvariant,
                    'quantity' => $reservation->quantity,
                ]);
                $reservation->update(['status' => 'Submitted', 'notified_at' => now(), 'submitted_at' => now()]);
                \Illuminate\Support\Facades\DB::table('request_notifications')->insert([
                    'idrequest' => $req->idrequest,
                    'recipient_idUsers' => (int) $reservation->idUsers,
                    'created_by' => $actorId,
                    'event_type' => 'reservation_ready',
                    'message' => mb_substr("Your reserved uniform arrived: {$reservation->quantity} x {$label}. Request #{$req->idrequest} is ready; the student can pick it up at the AMO office.", 0, 500),
                    'created_at' => now(),
                ]);
            });
        }
        $variant->save();

        $variant->load(['department', 'type']);

        $this->logActivity(
            request()->user()->idUsers,
            "Restocked {$validated['quantity']} {$variant->type->uniform_name} ({$variant->department->dept_name} - {$variant->size})"
        );

        return response()->json([
            'message' => 'Uniform restocked successfully.',
            'variant' => $variant,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RELEASE UNIFORM
    |--------------------------------------------------------------------------
    */

    public function release(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $variant = UniformVariant::find($id);

        if (!$variant) {
            return response()->json([
                'message' => 'Uniform variant not found.'
            ], 404);
        }

        if (((int) $variant->quantity - (int) ($variant->reserved_quantity ?? 0)) < $validated['quantity']) {
            return response()->json([
                'message' => 'Insufficient unreserved uniform stock. Some units are reserved for approved requests.'
            ], 400);
        }

        $variant->quantity -= $validated['quantity'];
        $variant->save();

        $variant->load(['department', 'type']);

        $this->logActivity(
            request()->user()->idUsers,
            "Released {$validated['quantity']} {$variant->type->uniform_name} ({$variant->department->dept_name} - {$variant->size})"
        );

        return response()->json([
            'message' => 'Uniform released successfully.',
            'variant' => $variant,
        ]);
    }
}
