<?php

namespace App\Http\Controllers;

use App\Models\Request;
use App\Models\RequestItem;
use App\Models\RequestUniform;
use App\Models\Item;
use App\Models\UniformVariant;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use App\Traits\ActivityLogger;

class RequestController extends Controller
{
    use ActivityLogger;

public function index()
{
$requests = Request::with([
    'user',
    'items.item',
    'uniforms.uniformVariant.type',
    'uniforms.uniformVariant.department'
])->get();

return response()->json(
    $requests->map(function ($request) {

        return [
            'idUsers' => $request->idUsers,
            'id' => $request->idrequest,

            'requester' => optional($request->user)->username,

            'role' => optional($request->user)->role,

            'purpose' => $request->purpose,
            'rejectReason' => $request->rejectReason,
            'location' => $request->location,
            'room' => $request->room,
            'status' => $request->status,
            'request_date' => $request->request_date,
            'borrowedAt' => $request->borrowed_at,
            'items' => $request->items->map(function ($item) {

                return [
                    'requestItemId' => $item->idrequestItem,
                    'itemId' => $item->iditems,
                    'itemName' => optional($item->item)->item_name,
                    'category' => optional($item->item)->category,
                    'itemType' => optional($item->item)->item_type,
                    'qty' => $item->quantity,
                    'returnedQty' => $item->returned_quantity,
                    'borrowedQty' => $item->quantity - $item->returned_quantity,
                'dueAt' => optional($item->due_at)->format('Y-m-d'),
                'suggestedDueDays' => $this->suggestedDueDays($item->item),
                ];

            }),
            'uniforms' => $request->uniforms->map(function ($uniform) {

    return [
        'requestUniformId' => $uniform->idrequestUniform,
        'uniformVariantId' => $uniform->idUnifvariant,
        'quantity' => $uniform->quantity,
        'uniformName' => optional($uniform->uniformVariant->type)->uniform_name,
        'department' => optional($uniform->uniformVariant->department)->dept_name,
        'size' => optional($uniform->uniformVariant)->size,
        'price' => optional($uniform->uniformVariant)->price,
    ];

}),

        ];

    })
);
}

public function show($id)
{
    $request = Request::with([
        'user',
        'items.item',
        'uniforms.uniformVariant.type',
        'uniforms.uniformVariant.department'
    ])->find($id);

    if (!$request) {
        return response()->json([
            'message' => 'Request not found.'
        ], 404);
    }

    return response()->json([
        'idUsers' => $request->idUsers,
        'id' => $request->idrequest,

        'requester' => optional($request->user)->username,

        'role' => optional($request->user)->role,

        'purpose' => $request->purpose,
        'rejectReason' => $request->rejectReason,
        'location' => $request->location,
        'room' => $request->room,
        'status' => $request->status,
        'request_date' => $request->request_date,
        'borrowedAt' => $request->borrowed_at,
        'items' => $request->items->map(function ($item) {

            return [
                'requestItemId' => $item->idrequestItem,
                'itemId' => $item->iditems,
                'itemName' => optional($item->item)->item_name,
                'category' => optional($item->item)->category,
                'itemType' => optional($item->item)->item_type,
                'qty' => $item->quantity,
                'returnedQty' => $item->returned_quantity,
                'borrowedQty' => $item->quantity - $item->returned_quantity,
                'dueAt' => optional($item->due_at)->format('Y-m-d'),
                'suggestedDueDays' => $this->suggestedDueDays($item->item),
            ];

        }),
        'uniforms' => $request->uniforms->map(function ($uniform) {

    return [
        'requestUniformId' => $uniform->idrequestUniform,
        'uniformVariantId' => $uniform->idUnifvariant,
        'quantity' => $uniform->quantity,
        'uniformName' => optional($uniform->uniformVariant->type)->uniform_name,
        'department' => optional($uniform->uniformVariant->department)->dept_name,
        'size' => optional($uniform->uniformVariant)->size,
        'price' => optional($uniform->uniformVariant)->price,
    ];

}),

    ]);
}

public function store(HttpRequest $request)
{
    $validated = $request->validate([
        'request_date' => 'nullable|date', // ignored: the server stamps the real time
        'items' => 'nullable|array',
        'items.*.iditems' => 'required|exists:items,iditems',
        'items.*.quantity' => 'required|integer|min:1',
        'uniforms' => 'nullable|array',
        'uniforms.*.idUnifvariant' => 'required|exists:uniformvariant,idUnifvariant',
        'uniforms.*.quantity' => 'required|integer|min:1',
        'location' => 'required|string|max:255',
        'room' => 'required|string|max:255',
        'purpose' => 'required|string',
    ]);
if (
    empty($validated['items']) &&
    empty($validated['uniforms'])
) {
    return response()->json([
        'message' => 'A request must contain at least one item or uniform.'
    ], 422);
}
    // Get the authenticated user's ID
    $idUsers = $request->user()->idUsers;
    $role = $request->user()->role;

    // Monthly request limit applies to everyone except admin.
    if ($role !== 'admin') {
        $limitSetting = \App\Models\Setting::where('setting_key', 'monthly_request_limit')->first();
        $limit = $limitSetting
            ? (int) $limitSetting->setting_value
            : \App\Http\Controllers\SettingController::DEFAULT_MONTHLY_REQUEST_LIMIT;

        $requestsThisMonth = Request::where('idUsers', $idUsers)
            ->whereMonth('request_date', now()->month)
            ->whereYear('request_date', now()->year)
            ->count();

        if ($requestsThisMonth >= $limit) {
            return response()->json([
                'message' => "You have reached your monthly request limit of {$limit}. Please try again next month."
            ], 429);
        }
    }

    // Per-item limit: the most one requester may have of an item at once.
    // Same exemption as the monthly limit: admins are not capped.
    if ($role !== 'admin') {
        $wanted = [];
        foreach ($validated['items'] ?? [] as $line) {
            $wanted[$line['iditems']] = ($wanted[$line['iditems']] ?? 0) + $line['quantity'];
        }

        foreach ($wanted as $itemId => $quantity) {
            $limited = Item::find($itemId);

            if (!$limited || !$limited->limit_per_requester) {
                continue;
            }

            $held = $this->heldQuantity($idUsers, $limited);

            if ($held + $quantity > $limited->limit_per_requester) {
                $left = max($limited->limit_per_requester - $held, 0);

                return response()->json([
                    'message' => "Limit reached for {$limited->item_name}: at most {$limited->limit_per_requester} per requester. "
                        . "You already have {$held} requested, so you can request {$left} more."
                ], 422);
            }
        }
    }

    DB::transaction(function () use ($validated, $idUsers, &$newRequest) {

        $newRequest = Request::create([
            'idUsers' => $idUsers,
            'approved_by' => null,
            'request_date' => now(),
            'status' => 'Pending',
            'location' => $validated['location'],
            'room' => $validated['room'],
            'purpose' => $validated['purpose'],
        ]);

foreach ($validated['items'] ?? [] as $item) {
$itemModel = Item::find($item['iditems']);

if (!$itemModel) {
    throw new \Exception("Item not found.");
}

if ($itemModel->quantity < $item['quantity']) {
    abort(
        400,
        "Insufficient stock for {$itemModel->item_name}."
    );
}
    RequestItem::create([
        'idrequest' => $newRequest->idrequest,
        'iditems' => $item['iditems'],
        'quantity' => $item['quantity'],
        'returned_quantity' => 0,
    ]);

}

foreach ($validated['uniforms'] ?? [] as $uniform) {

    RequestUniform::create([
        'idrequest' => $newRequest->idrequest,
        'idUnifvariant' => $uniform['idUnifvariant'],
        'quantity' => $uniform['quantity'],
    ]);

}

    });

    $newRequest->load([
    'user',
    'items.item',
    'uniforms.uniformVariant.type',
    'uniforms.uniformVariant.department'
]);

$this->logActivity(
    $newRequest->idUsers,
    "Created Request #{$newRequest->idrequest}"
);

return response()->json([
    'message' => 'Request created successfully.',
    'request' => [
        'id' => $newRequest->idrequest,
        'idUsers' => $newRequest->idUsers,
        'status' => $newRequest->status,
        'request_date' => $newRequest->request_date,
        'location' => $newRequest->location,
        'room' => $newRequest->room,
        'purpose' => $newRequest->purpose,
        'rejectReason' => null,
        'items' => $newRequest->items->map(function ($item) {
            return [
                'requestItemId' => $item->idrequestItem,
                'itemId' => $item->iditems,
                'itemName' => optional($item->item)->item_name,
                'qty' => $item->quantity,
                'returnedQty' => $item->returned_quantity,
                'borrowedQty' => $item->quantity - $item->returned_quantity,
                'dueAt' => optional($item->due_at)->format('Y-m-d'),
            ];
        }),
        'uniforms' => $newRequest->uniforms->map(function ($uniform) {

    return [
        'requestUniformId' => $uniform->idrequestUniform,
        'uniformVariantId' => $uniform->idUnifvariant,
        'quantity' => $uniform->quantity,
        'uniformName' => optional($uniform->uniformVariant->type)->uniform_name,
        'department' => optional($uniform->uniformVariant->department)->dept_name,
        'size' => optional($uniform->uniformVariant)->size,
        'price' => optional($uniform->uniformVariant)->price,
    ];

}),
    ]
], 201);
}

public function approve(HttpRequest $httpRequest, $id)
{
    $httpRequest->validate([
        'due_dates' => 'nullable|array',
        'due_dates.*' => 'nullable|date_format:Y-m-d|after_or_equal:today',
    ]);

    $dueDates = $httpRequest->input('due_dates', []);

    return DB::transaction(function () use ($id, $dueDates) {

        $request = Request::with([
            'items',
            'uniforms'
        ])->find($id);

        if (!$request) {
            return response()->json([
                'message' => 'Request not found.'
            ], 404);
        }

        if ($request->status !== 'Pending') {
            return response()->json([
                'message' => 'Only pending requests can be approved.'
            ], 400);
        }

        // Check office supply stock
        foreach ($request->items as $requestedItem) {

            $item = Item::find($requestedItem->iditems);

            if (!$item || $item->quantity < $requestedItem->quantity) {
                return response()->json([
                    'message' => "Insufficient stock for item ID {$requestedItem->iditems}."
                ], 400);
            }
        }

        // Check uniform stock
        foreach ($request->uniforms as $requestedUniform) {

            $uniform = UniformVariant::find($requestedUniform->idUnifvariant);

            if (!$uniform || $uniform->quantity < $requestedUniform->quantity) {
                return response()->json([
                    'message' => "Insufficient stock for uniform variant ID {$requestedUniform->idUnifvariant}."
                ], 400);
            }
        }

        // School equipment and Type B items are lent out, so each needs a
        // due date picked on the Pending Requests page.
        foreach ($request->items as $requestedItem) {

            $item = Item::find($requestedItem->iditems);

            if ($this->requiresDueDate($item) && empty($dueDates[$requestedItem->idrequestItem])) {
                return response()->json([
                    'message' => "Please set a due date for {$item->item_name}."
                ], 422);
            }
        }

        // Deduct office supplies
        foreach ($request->items as $requestedItem) {

            $item = Item::find($requestedItem->iditems);

            $item->quantity -= $requestedItem->quantity;
            $this->updateItemStatus($item);

            $item->save();

            // Store the due date chosen at approval (borrowed items only).
            $requestedItem->due_at = $this->requiresDueDate($item)
                ? $dueDates[$requestedItem->idrequestItem]
                : null;
            $requestedItem->save();
        }

        // Deduct uniforms
        foreach ($request->uniforms as $requestedUniform) {

            $uniform = UniformVariant::find($requestedUniform->idUnifvariant);

            $uniform->quantity -= $requestedUniform->quantity;

            $uniform->save();
        }

        $request->update([
            'status' => 'Approved',
            'approved_by' => request()->user()->idUsers,
            'borrowed_at' => now(),
        ]);

        $this->logActivity(
            request()->user()->idUsers,
            "Approved Request #{$request->idrequest}"
        );

        $request->load([
            'user',
            'items.item',
            'uniforms.uniformVariant.type',
            'uniforms.uniformVariant.department'
        ]);

        return response()->json([
    'message' => 'Request approved successfully.',
    'request' => [
        'id' => $request->idrequest,
        'idUsers' => $request->idUsers,
        'status' => $request->status,
        'request_date' => $request->request_date,
        'location' => $request->location,
        'room' => $request->room,
        'purpose' => $request->purpose,
        'rejectReason' => $request->rejectReason,
        'items' => $request->items->map(function ($item) {
            return [
                'requestItemId' => $item->idrequestItem,
                'itemId' => $item->iditems,
                'itemName' => optional($item->item)->item_name,
                'qty' => $item->quantity,
                'returnedQty' => $item->returned_quantity,
                'borrowedQty' => $item->quantity - $item->returned_quantity,
                'dueAt' => optional($item->due_at)->format('Y-m-d'),
            ];
        }),
        'uniforms' => $request->uniforms->map(function ($uniform) {
    return [
        'requestUniformId' => $uniform->idrequestUniform,
        'uniformVariantId' => $uniform->idUnifvariant,
        'quantity' => $uniform->quantity,
        'uniformName' => optional($uniform->uniformVariant->type)->uniform_name,
        'department' => optional($uniform->uniformVariant->department)->dept_name,
        'size' => optional($uniform->uniformVariant)->size,
        'price' => optional($uniform->uniformVariant)->price,
    ];
}),
        
    ]
]);
    });
}

// How much of an item this user already has: pending + approved requests.
// Borrowed types count what is still out (not yet returned). Issued types
// (Consumable, Type A) are never returned, so they count this month's
// requests, the same window as the monthly request limit.
private function heldQuantity(int $userId, Item $item): int
{
    $issued = $item->isIssuedType();

    return (int) RequestItem::where('iditems', $item->iditems)
        ->whereHas('request', function ($q) use ($userId, $issued) {
            $q->where('idUsers', $userId)
                ->whereIn('status', ['Pending', 'Approved']);

            if ($issued) {
                $q->whereYear('request_date', now()->year)
                    ->whereMonth('request_date', now()->month);
            }
        })
        ->get()
        ->sum(function ($requestItem) use ($issued) {
            return $issued
                ? $requestItem->quantity
                : $requestItem->quantity - $requestItem->returned_quantity;
        });
}

// Per-item limits with how much the logged-in user already has, so the
// Request page can show "you have 3 of 5". Only items that have a limit.
public function itemUsage(HttpRequest $httpRequest)
{
    $userId = $httpRequest->user()->idUsers;

    $rows = Item::whereNotNull('limit_per_requester')->get()->map(function ($item) use ($userId) {
        $held = $this->heldQuantity($userId, $item);
        $limit = (int) $item->limit_per_requester;

        return [
            'itemId' => $item->iditems,
            'limit' => $limit,
            'held' => $held,
            'remaining' => max($limit - $held, 0),
        ];
    })->values();

    return response()->json($rows);
}

// Only school equipment and Type B non-consumables are lent out and
// expected back, so only they get a due date.
private function requiresDueDate(?Item $item): bool
{
    if (!$item) {
        return false;
    }

    return $item->category === 'School Equipment'
        || $item->item_type === 'Non-Consumable Type B';
}

// Suggested days for pre-filling the due date field: the item's own return
// period, else the category default from Settings. Null = no suggestion.
private function suggestedDueDays(?Item $item): ?int
{
    if (!$this->requiresDueDate($item)) {
        return null;
    }

    if ($item->return_days) {
        return (int) $item->return_days;
    }

    $setting = \App\Models\Setting::where('setting_key', 'return_days:' . $item->category)->first();

    return $setting ? (int) $setting->setting_value : null;
}

private function updateItemStatus(Item $item)
{
    $item->status = $item->computeStatus();
}

public function reject(HttpRequest $httpRequest, $id)
{
    
    return DB::transaction(function () use ($id, $httpRequest) {

        $request = Request::find($id);

        if (!$request) {
            return response()->json([
                'message' => 'Request not found.'
            ], 404);
        }
$validated = $httpRequest->validate([
    'rejectReason' => 'required|string|max:500',
]);

        if ($request->status !== 'Pending') {
            return response()->json([
                'message' => 'Only pending requests can be rejected.'
            ], 400);
        }

$request->update([
    'status' => 'Rejected',
    'approved_by' => request()->user()->idUsers,
    'rejectReason' => $validated['rejectReason'],
]);

        $this->logActivity(
            request()->user()->idUsers,
            "Rejected Request #{$request->idrequest}"
        );

        $request->load([
    'user',
    'items.item',
    'uniforms.uniformVariant.type',
    'uniforms.uniformVariant.department'
]);

return response()->json([
    'message' => 'Request rejected successfully.',
    'request' => [
        'id' => $request->idrequest,
        'idUsers' => $request->idUsers,
        'status' => $request->status,
        'request_date' => $request->request_date,
        'location' => $request->location,
        'room' => $request->room,
        'purpose' => $request->purpose,
        'rejectReason' => $request->rejectReason,
        'items' => $request->items->map(function ($item) {
            return [
                'requestItemId' => $item->idrequestItem,
                'itemId' => $item->iditems,
                'itemName' => optional($item->item)->item_name,
                'qty' => $item->quantity,
                'returnedQty' => $item->returned_quantity,
                'borrowedQty' => $item->quantity - $item->returned_quantity,
                'dueAt' => optional($item->due_at)->format('Y-m-d'),
            ];
        }),
    ]
]);
    });
}

public function returnEquipment(HttpRequest $request, $id)
{
    $validated = $request->validate([
        'quantity' => 'required|integer|min:1',
    ]);

    return DB::transaction(function () use ($id, $validated) {

        $requestItem = RequestItem::findOrFail($id);
        $requestItem->load('request');
if ($requestItem->request->status !== 'Approved') {
    return response()->json([
        'message' => 'Only approved requests can be returned.'
    ], 400);
}
        $qty = $validated['quantity'];

        $borrowed =
            $requestItem->quantity -
            $requestItem->returned_quantity;

        if ($qty > $borrowed) {
            return response()->json([
                'message' => 'Return quantity exceeds borrowed quantity.'
            ], 400);
        }

        $requestItem->returned_quantity += $qty;
$requestItem->save();

$request = $requestItem->request;

// Issued items (Consumable and Type A) are handed over for good and are
// never returned, so only the items that are lent out decide whether the
// request is finished.
$allReturned = $request->items()
    ->whereHas('item', function ($q) {
        $q->whereNotIn('item_type', ['Consumable', 'Non-Consumable Type A']);
    })
    ->whereColumn('returned_quantity', '<', 'quantity')
    ->doesntExist();

if ($allReturned) {
    $request->update([
        'status' => 'Returned'
    ]);
}

        $item = Item::findOrFail($requestItem->iditems);

        // CHANGED: returned items no longer go straight back into
        // available stock. Instead they sit in pending_inspections until
        // an admin confirms their condition (see InspectionController).
        \App\Models\PendingInspection::create([
            'idrequestItem' => $requestItem->idrequestItem,
            'iditems' => $item->iditems,
            'quantity' => $qty,
            'status' => 'Pending',
            'returned_at' => now(),
        ]);

        $this->logActivity(
            request()->user()->idUsers,
            "Returned {$qty} {$item->item_name}"
        );

        return response()->json([
            'message' => 'Equipment returned successfully.',
            'requestItemId' => $requestItem->idrequestItem,
'status' => $requestItem->request->status,
            'returnedQty' => $requestItem->returned_quantity,
            'borrowedQty' => $requestItem->quantity - $requestItem->returned_quantity,
            'availableQty' => $item->quantity,
        ]);
    });
}
}
