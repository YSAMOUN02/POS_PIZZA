<?php

namespace App\Console\Commands;

use App\Http\Controllers\ImageController;
use Illuminate\Console\Command;

class GenerateProductThumbnails extends Command
{
    protected $signature = 'images:thumbs
                            {--sizes=96,300,600 : Comma-separated widths to generate}
                            {--force : Rebuild thumbnails even if they already exist}';

    protected $description = 'Pre-generate cached WebP thumbnails for all product images';

    public function handle(): int
    {
        $dir = public_path('assets/startic_img');
        if (!is_dir($dir)) {
            $this->error("Image directory not found: {$dir}");
            return self::FAILURE;
        }

        $sizes = array_filter(array_map('intval', explode(',', $this->option('sizes'))));
        $force = (bool) $this->option('force');

        $files = array_values(array_filter(scandir($dir), function ($f) use ($dir) {
            return is_file($dir . '/' . $f)
                && preg_match('/\.(jpe?g|png|gif|webp)$/i', $f);
        }));

        if (!$files) {
            $this->info('No product images found.');
            return self::SUCCESS;
        }

        $total = count($files) * count($sizes);
        $this->info(sprintf('Generating %d thumbnails (%d images × %d sizes)...', $total, count($files), count($sizes)));
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $made = $skipped = $failed = 0;
        $bytesIn = $bytesOut = 0;

        foreach ($files as $file) {
            $source = $dir . '/' . $file;
            foreach ($sizes as $size) {
                $thumb = ImageController::thumbPath($file, $size);

                if (!$force && is_file($thumb) && filemtime($thumb) >= filemtime($source)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                if (ImageController::generate($source, $thumb, $size)) {
                    $made++;
                    $bytesIn += filesize($source);
                    $bytesOut += filesize($thumb);
                } else {
                    $failed++;
                    $this->newLine();
                    $this->warn("  could not process: {$file} @ {$size}px");
                }
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Generated: {$made}   Skipped (up to date): {$skipped}   Failed: {$failed}");
        if ($made > 0) {
            $this->info(sprintf(
                'Source %s  ->  thumbnails %s  (%.1f%% smaller)',
                $this->human($bytesIn),
                $this->human($bytesOut),
                $bytesIn > 0 ? (1 - $bytesOut / $bytesIn) * 100 : 0
            ));
        }

        return self::SUCCESS;
    }

    private function human(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)       return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
