<?php

namespace App\Http\Controllers;

use App\Jobs\SyncStockJob;
use App\Models\StockSyncRun;
use Illuminate\Support\Facades\Auth;

class StockSyncController extends Controller
{
    public function start(\App\Services\StockSyncService $svc)
    {
        $run = StockSyncRun::create([
            'user_id' => Auth::id(),
            'status' => 'queued',
            'total' => 0,
            'done' => 0,
            'added' => 0,
            'skipped' => 0,
        ]);

        set_time_limit(1800);
        $svc->run($run);                 // runs fully, then returns
        $run->refresh();

        return response()->json([        // final result, all at once
            'status'        => $run->status,
            'added'         => $run->added,
            'skipped'       => $run->skipped,
            'message'       => $run->message,
            'skipped_items' => array_slice($run->skipped_items ?? [], 0, 50),
        ]);
    }
    public function status(StockSyncRun $run)
    {
        abort_unless((int) $run->user_id === (int) Auth::id(), 403);
        return response()->json([
            'status'        => $run->status,
            'total'         => $run->total,
            'done'          => $run->done,
            'added'         => $run->added,
            'skipped'       => $run->skipped,
            'percent'       => $run->percent(),
            'message'       => $run->message,
            'skipped_items' => array_slice($run->skipped_items ?? [], 0, 50),
        ]);
    }
}
