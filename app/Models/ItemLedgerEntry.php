<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class ItemLedgerEntry extends Model
{


    protected $table = 'item_ledger_entries';

    protected $fillable = [
        'entry_no',
        'posting_date',

        'document_type',
        'document_no',
        'source_no',
        'source_id',
        'source_table',

        'product_id',
        'barcode',
        'item_code',
        'name',
        'variant',
        'description',
        'unit',
        'category_name',
        'type',

        'warehouse_id',
        'warehouse_name',
        'bin_id',
        'bin_name',
        'lot',
        'expire_date',

        'quantity',
        'remaining_quantity',
        'entry_type',

        'unit_cost',
        'cost_amount',
        'unit_price',
        'sell_price',
        'discount_percent',
        'discount_amount',
        'vat',
        'vat_amount',
        'line_amount',
        'net_amount',
        'grand_total_amount',
        'factor',
        'currency_name',
        'customer_id',
        'customer_name',
        'customer_phone',
        'customer_address',
        'vendor_id',
        'vendor_name',
        'payment_method',
        'remark',
        'created_by',
        'created_user_id'
    ];

   protected $casts = [
    'posting_date'         => 'date',
    'expire_date'          => 'date',
    'returned_at'          => 'datetime',

    'quantity'             => 'decimal:6',
    'remaining_quantity'   => 'decimal:6',

    'unit_cost'            => 'decimal:6',
    'cost_amount'          => 'decimal:6',
    'unit_price'           => 'decimal:6',
    'sell_price'           => 'decimal:6',

    'discount_percent'     => 'decimal:4',
    'discount_amount'      => 'decimal:6',

    'vat'                  => 'decimal:4',
    'vat_amount'           => 'decimal:6',

    'line_amount'          => 'decimal:6',
    'net_amount'           => 'decimal:6',
    'grand_total_amount'   => 'decimal:6',
];
    /**
     * One rule, every feature: the cost value of the movement is derived from
     * entry_type (the reliable sign) × |quantity| × unit_cost, so no write site
     * has to remember to set it and it can never disagree with the entry's
     * direction. Recomputed on every save because it's a pure function of those
     * three fields — cheap, and it self-heals if unit_cost is edited later.
     *
     *   positive → +value (stock in): purchase, purchase(kitchen), stock sync,
     *              sale return, transfer receipt, kitchen production, adjustment +
     *   negative → -value (stock out): sale, recipe consumption, purchase return,
     *              transfer shipment, adjustment -
     */
    protected static function booted(): void
    {
        static::saving(function (self $entry) {
            $sign = $entry->entry_type === 'negative' ? -1 : 1;
            $qty  = abs((float) $entry->quantity);
            $cost = (float) $entry->unit_cost;
            $entry->cost_amount = round($sign * $qty * $cost, 6);
        });
    }

    // ItemLedgerEntry.php
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
