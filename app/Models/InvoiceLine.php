<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceLine extends Model
{
      use HasFactory;

    protected $table = 'sale_invoice_lines';


   protected $fillable = [
    'sale_invoice_id',
    'product_id',
    'document_no' ,
    'barcode',
    'item_code',
    'name',
    'variant',
    'addon_line_ids',
    'description',

    'quantity',
    'unit',
    'category_name',

    'cost',
    'unit_price',
    'sell_price',

    'discount_percent',
    'discount_amount',

    'line_amount',
    'vat_percent',
    'vat_amount',
    'net_amount',
    'grand_total_amount',

    'created_by',
    'remarks',

    'prepared_at',
    'prepared_by',
];

protected $casts = [
    'quantity'            => 'decimal:6',
    'addon_line_ids'      => 'array',
    'prepared_at'         => 'datetime',

    'cost'                => 'decimal:6',
    'unit_price'          => 'decimal:6',
    'sell_price'          => 'decimal:6',

    'discount_percent'    => 'decimal:4',
    'discount_amount'     => 'decimal:6',

    'line_amount'         => 'decimal:6',
    'vat_percent'         => 'decimal:4',
    'vat_amount'          => 'decimal:6',
    'net_amount'          => 'decimal:6',
    'grand_total_amount'  => 'decimal:6',
];
    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function invoice()
    {
        return $this->belongsTo(InvoiceHeader::class, 'sale_invoice_id');
    }

    public function item()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
