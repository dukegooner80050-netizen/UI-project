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

        if ($variant->quantity < $validated['quantity']) {
            return response()->json([
                'message' => 'Insufficient uniform stock.'
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