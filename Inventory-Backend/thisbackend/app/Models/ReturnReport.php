<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnReport extends Model
{
    protected $table = 'return_reports';

    public $timestamps = false;

    protected $guarded = [];

    public function requestItem()
    {
        return $this->belongsTo(RequestItem::class, 'idrequestItem', 'idrequestItem');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by', 'idUsers');
    }

    /** Units on this request line the borrower has reported but AMO has not yet checked. */
    public static function pendingQuantity($idrequestItem): int
    {
        return (int) static::where('idrequestItem', $idrequestItem)
            ->where('status', 'Pending')
            ->sum('quantity');
    }

    /**
     * Called whenever units of a line are actually recorded as returned
     * (by the AMO, from any screen). Pending reports for that line are
     * settled oldest first so the AMO is never asked to confirm something
     * they already recorded.
     */
    public static function settle($idrequestItem, int $returnedQty, $actorId): void
    {
        $remaining = $returnedQty;

        $reports = static::where('idrequestItem', $idrequestItem)
            ->where('status', 'Pending')
            ->orderBy('id')
            ->get();

        foreach ($reports as $report) {
            if ($remaining <= 0) {
                break;
            }

            if ($report->quantity <= $remaining) {
                $remaining -= $report->quantity;
                $report->update([
                    'status' => 'Confirmed',
                    'resolved_by' => $actorId,
                    'resolved_at' => now(),
                ]);
            } else {
                // Partly covered: the rest of the report is still waiting.
                $report->update(['quantity' => $report->quantity - $remaining]);
                $remaining = 0;
            }
        }
    }
}
