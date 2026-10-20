<?php

namespace App\Http\Controllers;

use App\Models\Request;
use App\Models\ReturnReport;
use App\Models\User;
use App\Models\RequestItem;
use App\Models\RequestUniform;
use App\Models\Item;
use App\Models\UniformVariant;
use App\Support\ReturnPolicy;
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
    'uniforms.uniformVariant.department',
    'releasedByUser',
    'forwardedByUser'
])->get();

return response()->json(
    $requests->map(function ($request) {

        return [
            'idUsers' => $request->idUsers,
            'id' => $request->idrequest,

            'requester' => optional($request->user)->username,
            'requesterName' => optional($request->user)->full_name,
            'notedBy' => optional($request->forwardedByUser)->full_name,

            'role' => optional($request->user)->role,

            'purpose' => $request->purpose,
            'rejectReason' => $request->rejectReason,
            'approvalRemarks' => $request->approval_remarks,
            'location' => $request->location,
            'room' => $request->room,
            'status' => $request->status,
            'coordinatorForwardedAt' => $request->coordinator_forwarded_at,
            'notifiedAt' => $request->notified_at,
            'request_date' => $request->request_date,
            'borrowedAt' => $request->borrowed_at,
            'releaseMethod' => $request->release_method,
            'releasedAt' => $request->released_at,
            'releasedBy' => optional($request->releasedByUser)->username,
            'items' => $request->items->map(function ($item) use ($request) {

                return [
                    'requestItemId' => $item->idrequestItem,
                    'itemId' => $item->iditems,
                    'itemName' => optional($item->item)->item_name,
                    'category' => optional($item->item)->category,
                    'itemType' => optional($item->item)->item_type,
                    'qty' => $item->quantity,
                    'returnedQty' => $item->returned_quantity,
                    'borrowedQty' => $item->quantity - $item->returned_quantity,
                    ...ReturnPolicy::dueInfo($request, $item),
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

// Borrowed lines the signed-in user still has out past their due date
// (roadmap #8). Only ever the caller's own requests, so borrowers can be
// told what they have to return without seeing anybody else's. Overdue is
// decided by ReturnPolicy::dueInfo(), the same rule the admin's Overdue
// Returns card and every due badge use.
public function myOverdue()
{
    $requests = Request::where('idUsers', request()->user()->idUsers)
        ->where('status', 'Approved')
        ->with('items.item')
        ->get();

    $lines = [];

    foreach ($requests as $request) {
        foreach ($request->items as $line) {

            $due = ReturnPolicy::dueInfo($request, $line);

            if (!$due['overdue']) {
                continue;
            }

            $lines[] = [
                'key' => $request->idrequest . '-' . $line->idrequestItem,
                'requestId' => $request->idrequest,
                'requestItemId' => $line->idrequestItem,
                'itemName' => optional($line->item)->item_name,
                'category' => optional($line->item)->category,
                'borrowedQty' => $line->quantity - $line->returned_quantity,
                'dueDate' => $due['dueDate'],
                'daysOverdue' => $due['daysOverdue'],
                'location' => $request->location,
                'room' => $request->room,
            ];
        }
    }

    usort($lines, function ($a, $b) {
        return $b['daysOverdue'] <=> $a['daysOverdue'];
    });

    return response()->json($lines);
}

public function show($id)
{
    $request = Request::with([
        'user',
        'items.item',
        'uniforms.uniformVariant.type',
        'uniforms.uniformVariant.department',
        'releasedByUser'
    ])->find($id);

    if (!$request) {
        return response()->json([
            'message' => 'Request not found.'
        ], 404);
    }

    $currentUser = request()->user();

    if (
        strtolower((string) $currentUser->role) !== 'amo' &&
        (int) $request->idUsers !== (int) $currentUser->idUsers
    ) {
        return response()->json([
            'message' => 'You are not allowed to view this request.'
        ], 403);
    }

    return response()->json([
        'idUsers' => $request->idUsers,
        'id' => $request->idrequest,

        'requester' => optional($request->user)->username,

        'role' => optional($request->user)->role,

        'purpose' => $request->purpose,
        'rejectReason' => $request->rejectReason,
        'approvalRemarks' => $request->approval_remarks,
        'location' => $request->location,
        'room' => $request->room,
        'status' => $request->status,
        'request_date' => $request->request_date,
        'borrowedAt' => $request->borrowed_at,
        'releaseMethod' => $request->release_method,
        'releasedAt' => $request->released_at,
        'releasedBy' => optional($request->releasedByUser)->username,
        'items' => $request->items->map(function ($item) use ($request) {

            return [
                'requestItemId' => $item->idrequestItem,
                'itemId' => $item->iditems,
                'itemName' => optional($item->item)->item_name,
                'category' => optional($item->item)->category,
                'itemType' => optional($item->item)->item_type,
                'qty' => $item->quantity,
                'returnedQty' => $item->returned_quantity,
                'borrowedQty' => $item->quantity - $item->returned_quantity,
                ...ReturnPolicy::dueInfo($request, $item),
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
        'request_date' => 'required|date',
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
    if ($role !== 'amo') {
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

    DB::transaction(function () use ($validated, $idUsers, &$newRequest) {

        $newRequest = Request::create([
            'idUsers' => $idUsers,
            'approved_by' => null,
            'request_date' => $validated['request_date'],
            'status' => in_array(strtolower((string) request()->user()->role), ['amo', 'department_head'], true) ? 'With Inventory' : 'With Coordinator',
            'location' => $validated['location'],
            'room' => $validated['room'],
            'purpose' => $validated['purpose'],
        ]);

$requestedPerItem = [];

foreach ($validated['items'] ?? [] as $item) {
$itemModel = Item::find($item['iditems']);

if (!$itemModel) {
    throw new \Exception("Item not found.");
}

// Per-head limit (roadmap #9, revised): how much of this item the
// requester may hold/have pending at once, counting everything they've
// already requested - not just the lines in this submission. NULL = no
// limit. Lines for the same item within this submission are added
// together first (requestedPerItem), then on top of what they already
// have outstanding (perHeadUsage).
$requestedPerItem[$itemModel->iditems] =
    ($requestedPerItem[$itemModel->iditems] ?? 0) + (int) $item['quantity'];

if ($itemModel->per_head_limit !== null) {
    $already = $this->perHeadUsage($idUsers, $itemModel);
    $afterThis = $already + $requestedPerItem[$itemModel->iditems];

    if ($afterThis > $itemModel->per_head_limit) {
        abort(
            422,
            "You've already requested {$already} of {$itemModel->item_name} "
                . "(limit is {$itemModel->per_head_limit} per person). "
                . "This request would bring your total to {$afterThis}."
        );
    }
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
if ($newRequest->status === 'With Coordinator') {
    $coordinators = \App\Models\User::whereRaw('LOWER(role) = ?', ['department_head'])->pluck('idUsers');
    foreach ($coordinators as $coordinatorId) {
        $this->createRequestNotification($newRequest, (int) $coordinatorId, 'coordinator_review', 'Request #' . $newRequest->idrequest . ' is waiting for coordinator review.', (int) $idUsers);
    }
}

return response()->json([
    'message' => 'Request created successfully.',
    'request' => [
        'id' => $newRequest->idrequest,
        'idUsers' => $newRequest->idUsers,
        'status' => $newRequest->status,
        'coordinatorForwardedAt' => $newRequest->coordinator_forwarded_at,
        'notifiedAt' => $newRequest->notified_at,
        'request_date' => $newRequest->request_date,
        'location' => $newRequest->location,
        'room' => $newRequest->room,
        'purpose' => $newRequest->purpose,
        'rejectReason' => null,
        'items' => $newRequest->items->map(function ($item) use ($newRequest) {
            return [
                'requestItemId' => $item->idrequestItem,
                'itemId' => $item->iditems,
                'itemName' => optional($item->item)->item_name,
                'qty' => $item->quantity,
                'returnedQty' => $item->returned_quantity,
                'borrowedQty' => $item->quantity - $item->returned_quantity,
                ...ReturnPolicy::dueInfo($newRequest, $item),
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
    // Optional due dates chosen per borrowed line: { requestItemId: "Y-m-d" }.
    // A line left out (or blank) uses the item's default return period.
    $validated = $httpRequest->validate([
        'due_dates' => 'nullable|array',
        'due_dates.*' => 'nullable|date_format:Y-m-d|after_or_equal:today',
        'approval_remarks' => 'nullable|string|max:500',
    ]);
    $dueDates = $validated['due_dates'] ?? [];
    $remarks = trim((string) ($validated['approval_remarks'] ?? ''));
    $remarks = $remarks === '' ? null : $remarks;

    return DB::transaction(function () use ($id, $dueDates, $remarks) {

        $request = Request::with([
            'items',
            'uniforms'
        ])->find($id);

        if (!$request) {
            return response()->json([
                'message' => 'Request not found.'
            ], 404);
        }

        if (!in_array($request->status, ['With Inventory', 'Pending'], true)) {
            return response()->json([
                'message' => 'Only requests forwarded to Inventory can be approved.'
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

        // Deduct office supplies
        foreach ($request->items as $requestedItem) {

            $item = Item::find($requestedItem->iditems);

            $item->quantity -= $requestedItem->quantity;
            $item->refreshStatus();

            $item->save();

            // Due date only applies to items that must come back.
            $chosenDue = $dueDates[$requestedItem->idrequestItem] ?? null;
            if ($chosenDue && $item->isReturnable()) {
                $requestedItem->due_date = $chosenDue;
                $requestedItem->save();
            }
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
            'approval_remarks' => $remarks,
            'borrowed_at' => now(),
            'notified_at' => now(),
            'notified_by' => request()->user()->idUsers,
        ]);
        $this->createRequestNotification($request, (int) $request->idUsers, 'decision', 'Your request #' . $request->idrequest . ' was approved.' . ($remarks ? ' Remarks: ' . $remarks : ''));

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
        'approvalRemarks' => $request->approval_remarks,
        'releaseMethod' => $request->release_method,
        'releasedAt' => $request->released_at,
        'items' => $request->items->map(function ($item) use ($request) {
            return [
                'requestItemId' => $item->idrequestItem,
                'itemId' => $item->iditems,
                'itemName' => optional($item->item)->item_name,
                'qty' => $item->quantity,
                'returnedQty' => $item->returned_quantity,
                'borrowedQty' => $item->quantity - $item->returned_quantity,
                ...ReturnPolicy::dueInfo($request, $item),
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

        if (!in_array($request->status, ['With Inventory', 'Pending'], true)) {
            return response()->json([
                'message' => 'Only requests forwarded to Inventory can be rejected.'
            ], 400);
        }

$request->update([
    'status' => 'Rejected',
    'approved_by' => request()->user()->idUsers,
    'rejectReason' => $validated['rejectReason'],
    'notified_at' => now(),
    'notified_by' => request()->user()->idUsers,
]);
$this->createRequestNotification($request, (int) $request->idUsers, 'decision', 'Your request #' . $request->idrequest . ' was rejected. Reason: ' . $validated['rejectReason']);

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
        'approvalRemarks' => $request->approval_remarks,
        'releaseMethod' => $request->release_method,
        'releasedAt' => $request->released_at,
        'items' => $request->items->map(function ($item) use ($request) {
            return [
                'requestItemId' => $item->idrequestItem,
                'itemId' => $item->iditems,
                'itemName' => optional($item->item)->item_name,
                'qty' => $item->quantity,
                'returnedQty' => $item->returned_quantity,
                'borrowedQty' => $item->quantity - $item->returned_quantity,
                ...ReturnPolicy::dueInfo($request, $item),
            ];
        }),
    ]
]);
    });
}

public function coordinatorQueue()
{
    $user = request()->user();
    if (strtolower((string) $user->role) !== 'department_head') {
        return response()->json(['message' => 'Only the Dean/Academic Coordinator can forward requests.'], 403);
    }
    return response()->json(Request::with(['user', 'items.item', 'uniforms.uniformVariant.type', 'uniforms.uniformVariant.department'])
        ->where('status', 'With Coordinator')->orderBy('request_date')->get()->map(fn ($r) => $this->formatWorkflowRequest($r)));
}

public function forwardToInventory($id)
{
    $actor = request()->user();
    if (strtolower((string) $actor->role) !== 'department_head') {
        return response()->json(['message' => 'Only the Dean/Academic Coordinator can forward requests.'], 403);
    }
    return DB::transaction(function () use ($id, $actor) {
        $r = Request::where('idrequest', $id)->lockForUpdate()->first();
        if (!$r) return response()->json(['message' => 'Request not found.'], 404);
        if ($r->status !== 'With Coordinator') return response()->json(['message' => 'Only requests awaiting coordinator review can be forwarded.'], 422);
        $r->update(['status' => 'With Inventory', 'coordinator_forwarded_by' => $actor->idUsers, 'coordinator_forwarded_at' => now()]);
        $r->load('user');
        $this->createRequestNotification($r, (int) $r->idUsers, 'forwarded', 'Your request #' . $r->idrequest . ' was forwarded to Inventory Management.');
        $r->update(['notified_at' => now(), 'notified_by' => $actor->idUsers]);
        $this->logActivity($actor->idUsers, 'Forwarded Request #' . $r->idrequest . ' to Inventory Management');
        return response()->json(['message' => 'Request forwarded to Inventory Management.', 'status' => $r->status]);
    });
}

public function notifications()
{
    $userId = (int) request()->user()->idUsers;
    return response()->json(DB::table('request_notifications')->where('recipient_idUsers', $userId)->orderByDesc('created_at')->limit(100)->get());
}

// Clears the notification bell badge: marks all of the signed-in user's
// request updates as read. They stay listed; only read_at is set.
public function readNotifications()
{
    $updated = DB::table('request_notifications')
        ->where('recipient_idUsers', (int) request()->user()->idUsers)
        ->whereNull('read_at')
        ->update(['read_at' => now()]);

    return response()->json(['updated' => $updated]);
}

// ---------------------------------------------------------------------
// Return reports. The borrower reports that they brought something back;
// the AMO then confirms (which records the return as usual and sends the
// units to inspection) or disputes it. While a report is waiting the
// borrower is not shown as overdue for those units, and the AMO is
// reminded through the notification bell and the dashboard.
// ---------------------------------------------------------------------

// When true, returnEquipment() does not auto-settle pending reports (the
// confirm action below settles the one report it is dealing with itself).
private bool $skipReportSettle = false;

public function reportReturn(HttpRequest $httpRequest, $id)
{
    $validated = $httpRequest->validate([
        'quantity' => 'required|integer|min:1',
    ]);

    $user = request()->user();

    return DB::transaction(function () use ($id, $validated, $user) {
        $requestItem = RequestItem::lockForUpdate()->find($id);

        if (!$requestItem) {
            return response()->json(['message' => 'Request item not found.'], 404);
        }

        $requestItem->load('request', 'item');
        $request = $requestItem->request;

        if ((int) $request->idUsers !== (int) $user->idUsers) {
            return response()->json(['message' => 'You can only report returns for your own requests.'], 403);
        }

        if ($request->status !== 'Approved') {
            return response()->json(['message' => 'Only approved requests can have items returned.'], 422);
        }

        if ($requestItem->item && !$requestItem->item->isReturnable()) {
            return response()->json(['message' => 'This item was issued and does not need to be returned.'], 422);
        }

        $pending = ReturnReport::pendingQuantity($requestItem->idrequestItem);
        $open = $requestItem->quantity - $requestItem->returned_quantity - $pending;

        if ($open <= 0) {
            return response()->json(['message' => 'Nothing left to report: the rest is already returned or waiting to be checked.'], 422);
        }

        if ($validated['quantity'] > $open) {
            return response()->json(['message' => "Only {$open} unit(s) can still be reported."], 422);
        }

        $report = ReturnReport::create([
            'idrequestItem' => $requestItem->idrequestItem,
            'quantity' => $validated['quantity'],
            'status' => 'Pending',
            'reported_by' => $user->idUsers,
            'reported_at' => now(),
        ]);

        $itemName = optional($requestItem->item)->item_name ?? 'item';
        $who = $user->username ?? 'A borrower';
        $message = "{$who} reported returning {$validated['quantity']} x {$itemName} (Request #{$request->idrequest}). Please check it.";

        foreach (User::where('role', 'amo')->pluck('idUsers') as $amoId) {
            $this->createRequestNotification($request, (int) $amoId, 'return_reported', $message, (int) $user->idUsers);
        }

        $this->logActivity(
            $user->idUsers,
            "Reported returning {$validated['quantity']} x {$itemName} (Request #{$request->idrequest})"
        );

        return response()->json([
            'message' => 'Return reported. The administrator will check it.',
            'reportId' => $report->id,
            'pendingReturnQty' => $pending + (int) $validated['quantity'],
        ], 201);
    });
}

// AMO: returns the borrowers say they made that nobody has checked yet,
// oldest first.
public function returnReports()
{
    $reports = ReturnReport::where('status', 'Pending')
        ->with(['requestItem.request', 'requestItem.item', 'reporter'])
        ->orderBy('reported_at')
        ->get();

    return response()->json(
        $reports->map(function ($r) {
            $line = $r->requestItem;
            $request = optional($line)->request;

            return [
                'id' => $r->id,
                'requestId' => optional($request)->idrequest,
                'requestItemId' => $r->idrequestItem,
                'itemName' => optional(optional($line)->item)->item_name,
                'category' => optional(optional($line)->item)->category,
                'quantity' => (int) $r->quantity,
                'requester' => optional($r->reporter)->username,
                'location' => optional($request)->location,
                'room' => optional($request)->room,
                'reportedAt' => $r->reported_at,
                'hoursWaiting' => (int) \Carbon\Carbon::parse($r->reported_at)->diffInHours(now()),
            ];
        })->values()
    );
}

public function confirmReturnReport($id)
{
    $actor = request()->user();
    $report = ReturnReport::find($id);

    if (!$report) {
        return response()->json(['message' => 'Return report not found.'], 404);
    }

    if ($report->status !== 'Pending') {
        return response()->json(['message' => 'This report was already handled.'], 422);
    }

    $requestItem = RequestItem::with('request', 'item')->find($report->idrequestItem);

    if (!$requestItem) {
        return response()->json(['message' => 'The request item no longer exists.'], 404);
    }

    // If the AMO already recorded part of this return from another screen,
    // only the units still out can be recorded now.
    $open = max(0, $requestItem->quantity - $requestItem->returned_quantity);
    $qty = min((int) $report->quantity, $open);

    if ($qty > 0) {
        $this->skipReportSettle = true;

        try {
            $response = $this->returnEquipment(
                HttpRequest::create('/', 'PUT', ['quantity' => $qty]),
                $requestItem->idrequestItem
            );
        } finally {
            $this->skipReportSettle = false;
        }

        if ($response->getStatusCode() >= 400) {
            return $response;
        }
    }

    $report->update([
        'status' => 'Confirmed',
        'resolved_by' => $actor->idUsers,
        'resolved_at' => now(),
    ]);

    $itemName = optional($requestItem->item)->item_name ?? 'item';

    $this->createRequestNotification(
        $requestItem->request,
        (int) $report->reported_by,
        'return_confirmed',
        "Your return of {$report->quantity} x {$itemName} (Request #{$requestItem->request->idrequest}) was confirmed. It is now waiting for inspection.",
        (int) $actor->idUsers
    );

    return response()->json(['message' => 'Return confirmed and recorded.']);
}

public function rejectReturnReport(HttpRequest $httpRequest, $id)
{
    $validated = $httpRequest->validate([
        'note' => 'required|string|max:255',
    ]);

    $actor = request()->user();
    $report = ReturnReport::find($id);

    if (!$report) {
        return response()->json(['message' => 'Return report not found.'], 404);
    }

    if ($report->status !== 'Pending') {
        return response()->json(['message' => 'This report was already handled.'], 422);
    }

    $requestItem = RequestItem::with('request', 'item')->find($report->idrequestItem);

    $report->update([
        'status' => 'Rejected',
        'note' => $validated['note'],
        'resolved_by' => $actor->idUsers,
        'resolved_at' => now(),
    ]);

    if ($requestItem && $requestItem->request) {
        $itemName = optional($requestItem->item)->item_name ?? 'item';

        $this->createRequestNotification(
            $requestItem->request,
            (int) $report->reported_by,
            'return_rejected',
            "Your return report for {$report->quantity} x {$itemName} (Request #{$requestItem->request->idrequest}) was not confirmed: {$validated['note']} The item is still listed as borrowed.",
            (int) $actor->idUsers
        );

        $this->logActivity(
            $actor->idUsers,
            "Did not confirm return report for {$report->quantity} x {$itemName} (Request #{$requestItem->request->idrequest}): {$validated['note']}"
        );
    }

    return response()->json(['message' => 'Report marked as not received. The borrower was notified.']);
}

public function resendNotification($id)
{
    $actor = request()->user();
    if (strtolower((string) $actor->role) !== 'amo') return response()->json(['message' => 'Only Inventory Management can resend request notifications.'], 403);
    $r = Request::find($id);
    if (!$r) return response()->json(['message' => 'Request not found.'], 404);
    if (!in_array($r->status, ['Approved', 'Rejected'], true)) return response()->json(['message' => 'A notification can be resent after a final decision.'], 422);
    $message = $r->status === 'Approved' ? 'Your request #' . $r->idrequest . ' was approved.' . ($r->approval_remarks ? ' Remarks: ' . $r->approval_remarks : '') : 'Your request #' . $r->idrequest . ' was rejected. Reason: ' . ($r->rejectReason ?: 'No reason provided.');
    $this->createRequestNotification($r, (int) $r->idUsers, 'decision_resend', $message, (int) $actor->idUsers);
    $r->update(['notified_at' => now(), 'notified_by' => $actor->idUsers]);
    $this->logActivity($actor->idUsers, 'Resent in-app notification for Request #' . $r->idrequest);
    return response()->json(['message' => 'In-app notification recorded and made available to the requester.', 'notified_at' => $r->notified_at]);
}

// Roadmap #15 / #19: once a request is Approved, Inventory Management
// records how the items are going out -- picked up by the requester, or
// delivered to the request's location/room. This does not change the
// request's status (it stays "Approved"); it just attaches the release
// choice to it, same way "borrowed_at" already does for the approval
// itself.
public function release(HttpRequest $httpRequest, $id)
{
    $actor = request()->user();

    $validated = $httpRequest->validate([
        'release_method' => 'required|string|in:For Pick Up,For Delivery',
    ]);

    return DB::transaction(function () use ($id, $validated, $actor) {
        $r = Request::where('idrequest', $id)->lockForUpdate()->first();

        if (!$r) {
            return response()->json(['message' => 'Request not found.'], 404);
        }

        if ($r->status !== 'Approved') {
            return response()->json(['message' => 'Only Approved requests can be released.'], 422);
        }

        $wasAlreadyReleased = !is_null($r->released_at);

        $r->update([
            'release_method' => $validated['release_method'],
            'released_at' => now(),
            'released_by' => $actor->idUsers,
        ]);

        $this->createRequestNotification(
            $r,
            (int) $r->idUsers,
            'released',
            'Your request #' . $r->idrequest . ' is ready: ' . $validated['release_method'] . '.'
        );

        $this->logActivity(
            $actor->idUsers,
            ($wasAlreadyReleased ? 'Updated release method for' : 'Released') . ' Request #' . $r->idrequest . ' (' . $validated['release_method'] . ')'
        );

        return response()->json([
            'message' => $wasAlreadyReleased
                ? 'Release method updated.'
                : 'Request marked as released.',
            'releaseMethod' => $r->release_method,
            'releasedAt' => $r->released_at,
            'releasedBy' => $actor->username,
        ]);
    });
}

private function createRequestNotification($request, int $recipientId, string $type, string $message, ?int $actorId = null): void
{
    DB::table('request_notifications')->insert([
        'idrequest' => $request->idrequest,
        'recipient_idUsers' => $recipientId,
        'created_by' => $actorId ?? (request()->user()->idUsers ?? null),
        'event_type' => $type,
        'message' => mb_substr($message, 0, 500),
        'created_at' => now(),
    ]);
}

private function formatWorkflowRequest($r): array
{
    return [
        'id' => $r->idrequest, 'idUsers' => $r->idUsers,
        'requester' => optional($r->user)->username, 'role' => optional($r->user)->role,
        'purpose' => $r->purpose, 'location' => $r->location, 'room' => $r->room,
        'status' => $r->status, 'request_date' => $r->request_date,
        'coordinatorForwardedAt' => $r->coordinator_forwarded_at,
        'items' => $r->items->map(fn ($i) => ['requestItemId' => $i->idrequestItem, 'itemId' => $i->iditems, 'itemName' => optional($i->item)->item_name, 'category' => optional($i->item)->category, 'itemType' => optional($i->item)->item_type, 'qty' => $i->quantity]),
        'uniforms' => $r->uniforms->map(fn ($u) => ['requestUniformId' => $u->idrequestUniform, 'uniformVariantId' => $u->idUnifvariant, 'quantity' => $u->quantity, 'uniformName' => optional($u->uniformVariant->type)->uniform_name, 'department' => optional($u->uniformVariant->department)->dept_name, 'size' => optional($u->uniformVariant)->size]),
    ];
}

public function returnEquipment(HttpRequest $request, $id)
{
    $validated = $request->validate([
        'quantity' => 'required|integer|min:1',
    ]);

    return DB::transaction(function () use ($id, $validated) {

        $requestItem = RequestItem::findOrFail($id);
        $requestItem->load('request', 'item');

        if ($requestItem->item && !$requestItem->item->isReturnable()) {
            return response()->json([
                'message' => 'This item was issued and does not need to be returned.'
            ], 400);
        }
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

        // Anything the borrower had reported as returned for this line is
        // now covered, so it drops off the AMO's "to check" list.
        if (!$this->skipReportSettle) {
            ReturnReport::settle($requestItem->idrequestItem, (int) $qty, request()->user()->idUsers);
        }

$request = $requestItem->request;

// Only tracked items count: issued ones (Consumable / Type A) are never
// returned and must not keep the request open forever.
$allReturned = $request->items()
    ->whereColumn('returned_quantity', '<', 'quantity')
    ->whereHas('item', function ($query) {
        $query->whereNotIn('item_type', Item::nonReturnableTypes());
    })
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
            ...ReturnPolicy::dueInfo($requestItem->request, $requestItem),
            'availableQty' => $item->quantity,
        ]);
    });
}

/**
 * How much of $item the given requester currently has against their
 * per-head limit (roadmap #9).
 *
 *   - Non-returnable items (Consumable, Non-Consumable Type A): handed
 *     out for good, so every non-Rejected request ever made counts
 *     forever - it never frees back up.
 *   - Returnable items (Non-Consumable Type B, School Equipment):
 *     only what's currently outstanding counts - Pending or Approved
 *     requests, minus whatever's already been returned. Returning it
 *     frees up the allowance again.
 */
private function perHeadUsage(int $userId, Item $item): int
{
    $query = RequestItem::where('iditems', $item->iditems)
        ->whereHas('request', function ($q) use ($userId) {
            $q->where('idUsers', $userId);
        });

    if (!$item->isReturnable()) {
        return (int) $query->whereHas('request', function ($q) {
            $q->where('status', '!=', 'Rejected');
        })->sum('quantity');
    }

    return (int) $query->whereHas('request', function ($q) {
        // Requests still in review hold the allowance too.
        $q->whereIn('status', ['Pending', 'With Coordinator', 'With Inventory', 'Approved']);
    })->get()->sum(function ($requestItem) {
        return $requestItem->quantity - $requestItem->returned_quantity;
    });
}
}
