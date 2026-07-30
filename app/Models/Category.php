<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{

    protected string $activitySection = 'category';

    protected $table = 'categories';

    // fg = finished goods (menu / sale items), rm = raw material, pm = packaging
    public const KINDS = ['fg', 'rm', 'pm'];

    protected $fillable = [
        'name',
        'kind',
        'description',
        'status',
        'created_by'
    ];

    // Which category kind classifies a given product type.
    public static function kindForProductType(?string $type): string
    {
        return match ($type) {
            'raw_material' => 'rm',
            'packaging_material' => 'pm',
            default => 'fg',
        };
    }

    protected $casts = [
        'status' => 'boolean',
    ];

    // Optional: relation to POS items
    public function posItems()
    {
        return $this->hasMany(Product::class, 'category_id');
    }
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
