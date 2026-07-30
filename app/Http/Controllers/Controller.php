<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\Request;

abstract class Controller
{
    public function uploadFileToPublic(Request $request, $fieldName, $Name)
    {
        if ($request->hasFile($fieldName)) {
            $file = $request->file($fieldName);
            $folder = 'assets/startic_img';

            // Truly-unique filename. The old "rand(1,100)-Name" reused names (only
            // 100 prefixes) so two products with the same name could overwrite each
            // other's photo, and a re-upload landed on the same URL — which the
            // browser then served stale from cache. time()+uniqid() guarantees a
            // fresh URL per upload; the slug just keeps it human-readable.
            $ext  = $file->getClientOriginalExtension() ?: 'jpg';
            $slug = preg_replace('/[^A-Za-z0-9]+/', '-', trim((string) $Name)) ?: 'product';
            $filename = time() . '-' . uniqid() . '-' . $slug . '.' . $ext;

            // Move file to public folder
            $file->move(public_path($folder), $filename);

            // Build the resized thumbnails straight away so the first person to
            // view this product doesn't pay the conversion cost (and never gets
            // served the multi-MB original).
            self::warmThumbnails($filename);

            // Return relative path to use in DB
            return $filename;
        }

        return null;
    }

    /**
     * Generate the cached thumbnail set for a just-uploaded product image.
     * Failures are non-fatal — ImageController regenerates on demand, so a bad
     * source here must never break the save that triggered it.
     */
    public static function warmThumbnails(?string $filename): void
    {
        if (!$filename) {
            return;
        }
        $source = public_path('assets/startic_img/' . $filename);
        if (!is_file($source)) {
            return;
        }
        foreach ([96, 300, 600] as $size) {
            try {
                ImageController::generate($source, ImageController::thumbPath($filename, $size), $size);
            } catch (\Throwable $e) {
                // ignore — served on demand instead
            }
        }
    }
}
