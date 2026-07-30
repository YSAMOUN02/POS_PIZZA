<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockSyncRun extends Model
{
    protected $guarded = [];
    protected $casts = ['skipped_items' => 'array'];

    public function percent(): int
    {
        return $this->total > 0 ? (int) round(min(100, $this->done / $this->total * 100)) : 0;
    }
}
