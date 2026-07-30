<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GetStock extends Model
{
    protected $connection = 'sqlsrv';   // ⚠️ match config/database.php
    protected $table      = 'GetStock';
    public    $timestamps = false;      // it's a view
}
