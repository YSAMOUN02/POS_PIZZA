<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves cached, resized product thumbnails.
 *
 * Product photos are uploaded straight from a phone/camera (some are 15-17 MB)
 * but are displayed in ~150 px tiles, so the browser was downloading megabytes
 * to paint a thumbnail. This generates a small WebP once, caches it on disk, and
 * serves it with immutable far-future cache headers so repeat views never hit
 * the network at all.
 */
class ImageController extends Controller
{
    /** Whitelisted output widths — keeps this from being used as a resize farm. */
    private const SIZES = [96, 300, 600];

    private const SOURCE_DIR = 'assets/startic_img';
    private const CACHE_DIR  = 'assets/thumbs';

    public function thumb(Request $request)
    {
        $file = basename((string) $request->query('f', ''));   // basename() blocks path traversal
        $size = (int) $request->query('s', 300);

        if ($file === '' || !in_array($size, self::SIZES, true)) {
            return $this->placeholder($request);
        }

        $source = public_path(self::SOURCE_DIR . '/' . $file);
        if (!is_file($source)) {
            return $this->placeholder($request);
        }

        $thumb = self::thumbPath($file, $size);

        // Regenerate if missing or the source has been re-uploaded since.
        if (!is_file($thumb) || filemtime($thumb) < filemtime($source)) {
            if (!self::generate($source, $thumb, $size)) {
                // Generation failed (corrupt/unsupported source) — fall back to
                // the original rather than showing a broken image.
                return $this->serve($source, $request);
            }
        }

        return $this->serve($thumb, $request);
    }

    /** Absolute path of the cached thumbnail for a source file at a given width. */
    public static function thumbPath(string $file, int $size): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        return public_path(self::CACHE_DIR . '/' . $size . '/' . $name . '.webp');
    }

    /**
     * Resize $source into a WebP at $thumb, capped to $maxWidth (aspect kept,
     * never upscaled). Returns false if the source can't be decoded.
     */
    public static function generate(string $source, string $thumb, int $maxWidth): bool
    {
        // No GD / WebP support on this PHP (common on some hosts)? Don't fatally
        // crash with "call to undefined function" — return false so the caller
        // serves the original image instead. Thumbnails just won't be generated.
        if (!function_exists('imagewebp') || !function_exists('imagecreatetruecolor')) {
            return false;
        }

        $info = @getimagesize($source);
        if (!$info) {
            return false;
        }

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_GIF  => @imagecreatefromgif($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default        => null,
        };
        if (!$src) {
            return false;
        }

        [$w, $h] = $info;
        $scale = min($maxWidth / max($w, 1), 1);   // never upscale
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        // Preserve transparency for PNG/GIF/WebP sources.
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        $dir = dirname($thumb);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $ok = @imagewebp($dst, $thumb, 82);

        imagedestroy($src);
        imagedestroy($dst);

        return $ok && is_file($thumb);
    }

    /**
     * Serve a file with immutable far-future caching.
     *
     * `immutable` means the browser reuses its cached copy for a year without
     * revalidating — so lazy-loaded tiles never flicker/reload when a grid
     * re-renders. Freshness comes from the URL instead of the cache: every upload
     * now writes a UNIQUE filename (see Controller::uploadFileToPublic and
     * ProductController::update), so a changed photo changes `?f=...` and the
     * browser fetches the new bytes once. (We briefly tried `no-cache` here, but it
     * revalidated on every img and made the kitchen grid reload images repeatedly.)
     * The ETag/Last-Modified pair only matters for hard refresh / cleared cache —
     * isNotModified() turns those into an empty 304 instead of re-sending bytes.
     */
    private function serve(string $path, ?Request $request = null): Response
    {
        $response = new BinaryFileResponse($path);
        $response->setMaxAge(31536000);           // 1 year
        $response->setPublic();
        $response->headers->addCacheControlDirective('immutable');
        $response->setAutoEtag();
        $response->setAutoLastModified();

        if ($request) {
            $response->isNotModified($request);   // flips to 304 + empty body when it matches
        }

        return $response;
    }

    private function placeholder(?Request $request = null): Response
    {
        $path = public_path('assets/defult/placeholder.png');
        if (!is_file($path)) {
            return response('', 404);
        }
        return $this->serve($path, $request);
    }
}
