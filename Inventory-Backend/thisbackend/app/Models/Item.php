<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    // Office Supplies item types.
    //   Consumable            - used up, never returned
    //   Non-Consumable Type A - issued: handed over for good, never returned
    //   Non-Consumable Type B - tracked: lent out, must be returned
    // Any other item_type (e.g. School Equipment's "Equipment") is tracked.
    public const TYPE_CONSUMABLE = 'Consumable';
    public const TYPE_A = 'Non-Consumable Type A';
    public const TYPE_B = 'Non-Consumable Type B';

    // stock_level values (roadmap #6)
    public const LEVEL_OUT = 'Out of Stock';
    public const LEVEL_DEPLOYED = 'Deployed';
    public const LEVEL_CRITICAL = 'Critical';
    public const LEVEL_MINIMUM = 'Minimum';
    public const LEVEL_ADEQUATE = 'Adequate';
    public const LEVEL_OPTIMAL = 'Optimal';

    // Defaults for new items; keep in sync with the migration defaults.
    public const DEFAULT_CRITICAL = 2;
    public const DEFAULT_MINIMUM = 5;
    public const DEFAULT_OPTIMAL = 20;

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
        'critical_stock',
        'minimum_stock',
        'optimal_stock',
        'return_days',
        'per_head_limit',
        'is_limitable',
        'status',
    ];

    protected $casts = [
        'is_limitable' => 'boolean',
    ];

    // Computed from quantity + thresholds, so it can never go stale when an
    // admin edits the levels. Included in every JSON response.
    protected $appends = ['stock_level', 'effective_return_days'];

    public $timestamps = false;

    public function requestItems()
    {
        return $this->hasMany(RequestItem::class, 'iditems', 'iditems');
    }

    /**
     * School Equipment with nothing on the shelf but units placed in rooms is
     * not "out of stock": it is deployed. Only checked when the shelf is
     * empty, so listing items costs no extra queries in the normal case.
     */
    public function isFullyDeployed(): bool
    {
        if ($this->category !== 'School Equipment' || (int) $this->quantity > 0) {
            return false;
        }

        return (int) \Illuminate\Support\Facades\DB::table('room_equipment')
            ->where('iditems', $this->iditems)
            ->sum('quantity') > 0;
    }

    // Shown instead of "Borrowed" when the empty shelf is because the units
    // are in rooms, and nobody is borrowing any of them.
    public function getStatusAttribute($value)
    {
        if ($value === 'Borrowed' && $this->isFullyDeployed()) {
            $borrowed = \App\Models\RequestItem::where('iditems', $this->iditems)
                ->whereHas('request', fn ($q) => $q->where('status', 'Approved'))
                ->get()
                ->sum(fn ($ri) => (int) $ri->quantity - (int) $ri->returned_quantity);

            return $borrowed > 0 ? $value : self::LEVEL_DEPLOYED;
        }

        return $value;
    }

    public function getStockLevelAttribute(): string
    {
        $qty = (int) $this->quantity;

        if ($qty <= 0) {
            return $this->isFullyDeployed() ? self::LEVEL_DEPLOYED : self::LEVEL_OUT;
        }
        if ($qty <= (int) $this->critical_stock) {
            return self::LEVEL_CRITICAL;
        }
        if ($qty <= (int) $this->minimum_stock) {
            return self::LEVEL_MINIMUM;
        }
        if ($qty >= (int) $this->optimal_stock) {
            return self::LEVEL_OPTIMAL;
        }

        return self::LEVEL_ADEQUATE;
    }

    /**
     * Days a borrower may keep this item before it is overdue: the item's own
     * return_days, else its category default, else null (no due date).
     * Always null for issued (never-returned) types. Roadmap #8.
     */
    public function getEffectiveReturnDaysAttribute(): ?int
    {
        return \App\Support\ReturnPolicy::daysFor($this);
    }

    /** Types that are handed over for good and never come back. */
    public static function nonReturnableTypes(): array
    {
        return [self::TYPE_CONSUMABLE, self::TYPE_A];
    }

    /** True when units of this item are lent out and must be returned. */
    public function isReturnable(): bool
    {
        return !in_array($this->item_type, self::nonReturnableTypes(), true);
    }

    /**
     * False for "fixed asset" equipment (roadmap #10) - unique/large-ticket
     * School Equipment like a room's projector or aircon, where a per-head
     * limit (roadmap #9) doesn't make sense. Defaults to true for every
     * other item.
     */
    public function canHaveLimit(): bool
    {
        return (bool) $this->is_limitable;
    }

    /**
     * The single place that sets items.status (previously copy-pasted into
     * four controllers). Does not save; callers save as before.
     *
     * Office Supplies, Consumable or Type A: Out of Stock / Low Stock (at or
     *   below minimum_stock) / Available
     * Everything else: Borrowed (nothing on the shelf) / Available
     */
    public function refreshStatus(): void
    {
        if ($this->category === 'Office Supplies' && !$this->isReturnable()) {
            if ($this->quantity <= 0) {
                $this->status = 'Out of Stock';
            } elseif ($this->quantity <= (int) $this->minimum_stock) {
                $this->status = 'Low Stock';
            } else {
                $this->status = 'Available';
            }

            return;
        }

        $this->status = $this->quantity <= 0 ? 'Borrowed' : 'Available';
    }
}
