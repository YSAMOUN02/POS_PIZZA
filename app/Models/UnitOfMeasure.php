<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    protected $fillable = [
        'code',
        'name',
        'category',
        'status',
        'created_by',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function conversions()
    {
        return $this->hasMany(ProductUnitConversion::class, 'unit_id');
    }
}
