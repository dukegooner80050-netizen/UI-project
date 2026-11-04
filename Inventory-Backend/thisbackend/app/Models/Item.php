<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    /*
     * Office Supplies types: Consumable = used up; Type A = issued, never returned; Type B = must be returned.
     */
    public const TYPE_CONSUMABLE = 'Consumable';
    public const TYPE_A = 'Non-Consumable Type A';
    public const TYPE_B = 'Non-Consumable Type B';

    // stock_level values (roadmap #6)
    public const LEVEL_OUT = 'Out of Stock';
    public const LEVEL_DEPLOYED = 'Deployed';
    public const LEVEL_BORROWED = 'Borrowed';
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
        'reserved_quantity',
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

    /* Computed from quantity and thresholds, so it never goes stale; included in every JSON response. */
    protected $appends = ['stock_level', 'effective_return_days'];

    public $timestamps = false;

    public function requestItems()
    {
        return $this->hasMany(RequestItem::class, 'iditems', 'iditems');
    }

    /**
     * tells whether an empty shelf is really out of stock.
     * School Equipment and Type B units may be borrowed or deployed in rooms; returns ['borrowed'
     * => n, 'deployed' => n] when the shelf is empty, else null (queries only then).
     */
    public function awayUnits(): ?array
    {
        if ((int) $this->quantity > 0) {
            return null;
        }
        $isEquipment = $this->category === 'School Equipment';
        $isTypeB = $this->category === 'Office Supplies' && $this->item_type === self::TYPE_B;
        if (!$isEquipment && !$isTypeB) {
            return null;
        }

        $borrowed = \App\Models\RequestItem::where('iditems', $this->iditems)
            ->whereHas('request', fn ($q) => $q->where('status', 'Approved')->where('stock_held', false))
            ->get()
            ->sum(fn ($ri) => (int) $ri->quantity - (int) $ri->returned_quantity);

        $deployed = $isEquipment
            ? (int) \Illuminate\Support\Facades\DB::table('room_equipment')->where('iditems', $this->iditems)->sum('quantity')
            : 0;

        return ['borrowed' => max(0, (int) $borrowed), 'deployed' => $deployed];
    }

    public function isFullyDeployed(): bool
    {
        $away = $this->awayUnits();
        return $away !== null && $away['borrowed'] === 0 && $away['deployed'] > 0;
    }

    /* Shows "Deployed" instead of "Borrowed" when the shelf is empty only because units are in rooms. */
    public function getStatusAttribute($value)
    {
        if ($value === 'Borrowed' && $this->isFullyDeployed()) {
            return self::LEVEL_DEPLOYED;
        }

        return $value;
    }

    public function getStockLevelAttribute(): string
    {
        $qty = (int) $this->quantity;

        if ($qty <= 0) {
            $away = $this->awayUnits();
            if ($away && $away['borrowed'] > 0) {
                return self::LEVEL_BORROWED;
            }
            if ($away && $away['deployed'] > 0) {
                return self::LEVEL_DEPLOYED;
            }

            return self::LEVEL_OUT;
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
     * how many days a borrower may keep this item before it is overdue.
     * Uses the item's own return_days, else the category default, else null (no due date); always
     * null for issued types.
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
     * tells whether a per-head limit applies to this item.
     * False for fixed assets (e.g. a room's projector); true for every other item.
     */
    public function canHaveLimit(): bool
    {
        return (bool) $this->is_limitable;
    }

    /**
     * the single place that works out items.status (does not save).
     * Consumable / Type A: Out of Stock, Low Stock (at or below minimum_stock) or Available.
     * Everything else: Borrowed (nothing on the shelf) or Available.
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
