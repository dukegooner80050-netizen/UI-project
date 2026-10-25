<?php

namespace App\Http\Controllers;

use App\Models\UniformReservation;
use App\Models\UniformVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Traits\ActivityLogger;

class UniformReservationController extends Controller
{
    use ActivityLogger;

    public function index()
    {
        $user = request()->user();
        $query = UniformReservation::with(['user', 'variant.type', 'variant.department'])
            ->orderBy('created_at');

        if (strtolower((string) $user->role) !== 'amo') {
            $query->where('idUsers', $user->idUsers);
        }

        return response()->json($query->get()->map(fn ($r) => $this->format($r)));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (strtolower((string) $user->role) !== 'cashier') {
            return response()->json(['message' => 'Only the cashier can reserve uniforms.'], 403);
        }

        $validated = $request->validate([
            'idUnifvariant' => 'required|integer|exists:uniformvariant,idUnifvariant',
            'quantity' => 'required|integer|min:1|max:1000',
            'student_name' => 'required|string|max:255',
            'student_number' => 'required|string|max:50',
            'paid' => 'accepted',
        ], [
            'student_name.required' => 'Enter the full name of the student this reservation is for.',
            'student_number.required' => 'Enter the student ID number.',
            'paid.accepted' => 'Confirm that the student has already paid for this uniform.',
        ]);
        $studentName = trim($validated['student_name']);
        $studentNumber = trim($validated['student_number']);
        if ($idErr = SettingController::studentIdError($studentNumber)) {
            return response()->json(['message' => $idErr, 'errors' => ['student_number' => [$idErr]]], 422);
        }

        $variant = UniformVariant::with(['type', 'department'])
            ->findOrFail($validated['idUnifvariant']);

        if (((int) $variant->quantity - (int) ($variant->reserved_quantity ?? 0)) > 0) {
            return response()->json([
                'message' => 'This uniform has stock available. Add it to a regular request instead.'
            ], 422);
        }

        $existing = UniformReservation::where('idUsers', $user->idUsers)
            ->where('idUnifvariant', $variant->idUnifvariant)
            ->where('status', 'Waiting')
            ->where('student_number', $studentNumber)
            ->first();

        if ($existing) {
            $existing->quantity = (int) $existing->quantity + (int) $validated['quantity'];
            $existing->save();
            $reservation = $existing;
        } else {
            $reservation = UniformReservation::create([
                'idUsers' => $user->idUsers,
                'idUnifvariant' => $variant->idUnifvariant,
                'quantity' => $validated['quantity'],
                'status' => 'Waiting',
                'student_name' => $studentName,
                'student_number' => $studentNumber,
                'paid_at' => now(),
            ]);
        }

        $this->logActivity(
            $user->idUsers,
            "Reserved {$validated['quantity']} {$variant->type->uniform_name} ({$variant->department->dept_name} - {$variant->gender} - {$variant->size}) for student {$studentNumber}"
        );

        return response()->json([
            'message' => 'Uniform reservation added to the queue.',
            'reservation' => $this->format($reservation->fresh(['user', 'variant.type', 'variant.department'])),
        ], 201);
    }

    public function cancel($id)
    {
        $user = request()->user();
        $reservation = UniformReservation::find($id);

        if (!$reservation) {
            return response()->json(['message' => 'Reservation not found.'], 404);
        }
        if ((int) $reservation->idUsers !== (int) $user->idUsers) {
            return response()->json(['message' => 'You can only cancel your own reservations.'], 403);
        }
        if (!in_array($reservation->status, ['Waiting', 'Ready'], true)) {
            return response()->json(['message' => 'This reservation can no longer be cancelled.'], 422);
        }

        if ($reservation->status === 'Ready') {
            // Free the stock that was being held for this reservation.
            $variant = UniformVariant::where('idUnifvariant', $reservation->idUnifvariant)->lockForUpdate()->first();
            if ($variant) {
                $variant->reserved_quantity = max(0, (int) $variant->reserved_quantity - (int) $reservation->quantity);
                $variant->save();
            }
        }

        $reservation->update(['status' => 'Cancelled', 'cancelled_at' => now()]);
        $this->logActivity($user->idUsers, "Cancelled uniform reservation #{$reservation->idreservation}");

        return response()->json(['message' => 'Reservation cancelled.']);
    }

    private function format(UniformReservation $r): array
    {
        $variant = $r->variant;
        return [
            'id' => $r->idreservation,
            'idUsers' => $r->idUsers,
            'cashier' => optional($r->user)->username,
            'uniformVariantId' => $r->idUnifvariant,
            'uniformName' => optional(optional($variant)->type)->uniform_name ?? 'Uniform',
            'department' => optional(optional($variant)->department)->dept_name ?? 'N/A',
            'gender' => optional($variant)->gender ?? 'Unisex',
            'size' => optional($variant)->size ?? 'N/A',
            'stock' => (int) (optional($variant)->quantity ?? 0),
            'quantity' => (int) $r->quantity,
            'studentName' => $r->student_name,
            'studentNumber' => $r->student_number,
            'paidAt' => optional($r->paid_at)->toDateTimeString(),
            'status' => $r->status,
            'notifiedAt' => optional($r->notified_at)->toDateTimeString(),
            'createdAt' => optional($r->created_at)->toDateTimeString(),
        ];
    }
}
