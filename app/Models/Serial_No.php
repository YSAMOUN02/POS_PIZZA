<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Serial_No extends Model
{
   protected $table = 'serial_no'; // 👈 important (not default plural)

    protected $fillable = [
        'prefix',
        'type',
        'current_no',
        'last_reset_date',
    ];
  protected $casts = [
        'last_reset_date' => 'date',
    ];

    /**
     * Central document-number generator for EVERY transaction (invoice, GRN, sale
     * order, quotation, adjustment, expense…). One persistent counter per $type in
     * this table, incremented under a row lock — so numbering never scans the big
     * transactional tables (or the item ledger) and can't produce duplicates.
     *
     * Format: PREFIX + 2-digit year + '-' + zero-padded number, e.g. INV26-0001.
     * The counter restarts at 1 at the start of each new year.
     *
     *   Serial_No::next('invoice', 'INV');       // INV26-0001
     *   Serial_No::next('quotation', 'QUOT', 3); // QUOT26-001
     *
     * @param string $type   Unique key for this counter (e.g. 'invoice', 'grn').
     * @param string $prefix Letter prefix shown before the year (e.g. 'INV').
     * @param int    $pad    Zero-pad width of the running number (default 4).
     */
    public static function next(string $type, string $prefix, int $pad = 4): string
    {
        return DB::transaction(function () use ($type, $prefix, $pad) {
            $yy = now()->format('y');

            $serial = static::where('type', $type)->lockForUpdate()->first();
            if (!$serial) {
                $serial = static::create([
                    'prefix'          => $prefix,
                    'type'            => $type,
                    'current_no'      => 0,
                    'last_reset_date' => now(),
                ]);
            }

            // New year → restart the running number at 1.
            if ($serial->last_reset_date
                && Carbon::parse($serial->last_reset_date)->format('y') !== $yy) {
                $serial->current_no = 0;
            }

            $serial->current_no += 1;
            $serial->last_reset_date = now();
            $serial->save();

            return $prefix . $yy . '-' . str_pad((int) $serial->current_no, $pad, '0', STR_PAD_LEFT);
        });
    }

    /**
     * DAILY counter (restarts at 1 each new day) — for the kitchen order docket.
     * Same locking/persistence as next(), but resets when last_reset_date is not
     * today, and the number carries NO year segment: ORDER-001, ORDER-002 …
     *
     *   Serial_No::nextDaily('order', 'ORDER');       // ['number'=>1,'document_no'=>'ORDER-001']
     *
     * @return array{number:int, document_no:string}
     */
    public static function nextDaily(string $type, string $prefix, int $pad = 3): array
    {
        return DB::transaction(function () use ($type, $prefix, $pad) {
            $today = now()->toDateString();

            $serial = static::where('type', $type)->lockForUpdate()->first();
            if (!$serial) {
                $serial = static::create([
                    'prefix'          => $prefix,
                    'type'            => $type,
                    'current_no'      => 0,
                    'last_reset_date' => now(),
                ]);
            }

            // New day → restart the running number at 1.
            if (!$serial->last_reset_date
                || Carbon::parse($serial->last_reset_date)->toDateString() !== $today) {
                $serial->current_no = 0;
            }

            $serial->current_no += 1;
            $serial->last_reset_date = now();
            $serial->save();

            $num = (int) $serial->current_no;

            return [
                'number'      => $num,
                'document_no' => $prefix . '-' . str_pad($num, $pad, '0', STR_PAD_LEFT),
            ];
        });
    }
}
