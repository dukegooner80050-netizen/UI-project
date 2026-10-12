<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $table = 'items';

    protected $primaryKey = 'iditems';

    protected $fillable = [
        'item_name',
        'description',
        'price',
        'category',
        'item_type',
        'quantity',
        'damaged_quantity',
        'return_days',
        'limit_per_requester',
        'critical_stock',
        'minimum_stock',
        'optimal_stock',
        'status',
    ];

    // Included in every JSON response so the UI badges always have it.
    protected $appends = ['stock_level'];

    public $timestamps = false;

    // School Equipment kinds. Both are lent out and returned.
    //   'Equipment'           = fixed asset (projector, aircon): NO per-requester limit
    //   'Equipment (Limited)' = limited equipment (accessories): CAN have a limit
    // 'Equipment' is the value existing rows already have, so they need no data change.
    const EQUIPMENT_LIMITED = 'Equipment (Limited)';

    // Fixed assets can never carry a per-requester limit.
    public function canHaveLimit(): bool
    {
        return $this->category !== 'School Equipment'
            || $this->item_type === self::EQUIPMENT_LIMITED;
    }

    // Out of Stock / Critical / Minimum / Adequate / Optimal, from quantity
    // against the item's own thresholds (defaults 2 / 5 / 20).
    public function getStockLevelAttribute(): string
    {
        $qty = (int) $this->quantity;
        $critical = (int) ($this->critical_stock ?? 2);
        $minimum = (int) ($this->minimum_stock ?? 5);
        $optimal = (int) ($this->optimal_stock ?? 20);

        if ($qty <= 0) return 'Out of Stock';
        if ($qty <= $critical) return 'Critical';
        if ($qty <= $minimum) return 'Minimum';
        if ($qty >= $optimal) return 'Optimal';

        return 'Adequate';
    }

    // Issued types are handed over for good and never come back.
    public function isIssuedType(): bool
    {
        return in_array($this->item_type, ['Consumable', 'Non-Consumable Type A'], true);
    }

    // Single source of truth for the item's status text.
    public function computeStatus(): string
    {
        $qty = (int) $this->quantity;

        if ($this->category === 'Office Supplies' && $this->isIssuedType()) {
            if ($qty <= 0) return 'Out of Stock';
            if ($qty <= (int) ($this->minimum_stock ?? 5)) return 'Low Stock';
            return 'Available';
        }

        return $qty <= 0 ? 'Borrowed' : 'Available';
    }

    public function requestItems()
{
    return $this->hasMany(RequestItem::class, 'iditems', 'iditems');
}
}
