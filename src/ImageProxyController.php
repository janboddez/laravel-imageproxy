<?php

namespace janboddez\ImageProxy;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Camo-like "image proxy" for Laravel.
 */
class ImageProxyController
{
    /**
     * Get requested image.
     *
     * @return Illuminate\Http\Response|null
     */
    public function proxy(Request $request, string $hash = '', string $url = '')
    {
        if ($request->headers->has('if-modified-since') || $request->headers->has('if-none-match')) {
            // It would seem the client already has the requested item.
            return response('', 304);
        }

        if (empty($hash)) {
            abort(400);
        }

        if (empty($url)) {
            abort(400);
        }

        // Use `$_SERVER` rather than `$url` or any of Laravel's URL functions
        // to avoid any processing that could lead to checksum errors. To do:
        // actually drop the `$hash` and `$url` vars?
        $url = ltrim(str_replace('imageproxy/'.$hash, '', $_SERVER['REQUEST_URI']), '/');

        $path = explode('/', parse_url($url, PHP_URL_PATH));

        if (isset($path[0]) && preg_match('~^\d+x\d+$~', $path[0])) {
            // First item's a set of dimensions.
            list($width, $height) = explode('x', $path[0]);
            $url = substr($url, strlen($path[0]) + 1);
        }

        if (! $this->verifyUrl($url, $hash)) {
            // Invalid URL, hash, or both.
            abort(400);
        }

        $headers = array_filter([
            // 'Accept' => 'image/*', // Some remote hosts don't handle this correctly.
            'Accept-Encoding' => $request->header('accept-encoding', null),
            'Connection' => 'close',
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'",
            'User-Agent' => config('imageproxy.user_agent', $request->header('user-agent', null)),
            'Range' => $request->header('range', null),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'deny',
            'X-XSS-Protection' => '1; mode=block',
        ]);

        if ((empty($width) && empty($height)) || ! class_exists('Imagick')) {
            // Just passing the requested image along.
            $stream = $this->openFile($url, $headers);

            // Newly received headers.
            list($status, $headers) = $this->getHttpHeaders($stream);

            $headers = array_combine(
                array_map('strtolower', array_keys($headers)),
                $headers
            );

            // if (empty($headers['content-type']) || ! preg_match('~^(image|video)/.+$~i', $headers['content-type'])) {
            //     Log::error('Not an image? ('.$url.')');
            //     abort(400);
            // }

            if (! in_array($status, [200, 201, 202, 206, 301, 302, 307], true)) {
                Log::error('Error occurred for '.$url);

                // Return an empty response.
                fclose($stream);

                return response('', $status, $headers);
            }

            $headers['Cache-Control'] = 'public, max-age=31536000';

            return response()->stream(function () use ($stream) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                fpassthru($stream);
            }, $status, $headers);
        } else {
            // Resize, and cache, the image (like in `storage/app/imageproxy`).
            // Don't bother with file extensions.
            $file = 'imageproxy/'.hash('sha256', $url)."_{$width}x{$height}";

            if (Storage::exists($file)) {
                // Return existing image. Uses `fpassthru()` under the hood and
                // is thus not all that different from the code above.
                return Storage::response($file, basename($url) ?: 'image');
            }

            // Set up Imagick.
            $im = new \Imagick();
            $im->setBackgroundColor(new \ImagickPixel('transparent'));

            try {
                // Read remote image.
                $handle = $this->openFile($url, $headers);
                $im->readImageFile($handle);
            } catch (\Exception $e) {
                // Something went wrong.
                Log::debug("Failed to read the image at $url: ".$e->getMessage());
                abort(500);
            }

            if (! empty($width) && ! empty($height)) {
                // Resize and crop.
                $im->cropThumbnailImage((int) $width, (int) $height);
                $im->setImagePage(0, 0, 0, 0);
            } else {
                $im->scaleImage(
                    min($im->getImageWidth(), $width),
                    min($im->getImageHeight(), $height)
                ); // If either width or height is zero, scale proportionally.
            }

            $im->setImageCompressionQuality(config('imageproxy.quality', 82));

            // Store to disk.
            Storage::put($file, $im->getImageBlob());

            // Return newly stored image.
            return Storage::response($file, basename($url) ?: 'image');
        }
    }

    /**
     * Verify URL.
     */
    protected function verifyUrl(string $url, string $hash): bool
    {
        if (strpos($url, 'http') !== 0) {
            // No longer also using `filter_var($url, FILTER_VALIDATE_URL) ===
            // false` as it seems incompatible with certain weird chars.
            Log::error('Not a valid URL: '.$url);
            return false;
        }

        if ($hash === hash_hmac('sha1', $url, config('imageproxy.secret_key', ''))) {
            return true;
        }

        // Let's not be too strict and try re-encoding some characters. (Web
        // browsers and servers alike do the weirdest things to non-ASCII URL
        // characters.
        if ($hash === hash_hmac('sha1', $this->safeEncodeUrl($url), config('imageproxy.secret_key', ''))) {
            // Most likely okay, too.
            return true;
        }

        // Either the URL's malformed, or the hash is invalid.
        Log::error('Checksum verification failed for '.$url);
        return false;
    }

    /**
     * Create a stream context.
     *
     * @return resource
     */
    protected function createStreamContext(array $headers, string $bindTo)
    {
        $args = [
            'http' => [
                'header' => array_map(
                    function ($k, $v) {
                        return $k.': '.$v;
                    },
                    array_keys($headers),
                    $headers
                ),
                'follow_location' => true,
                'ignore_errors' => true, // "Allow," i.e., don't crash on, HTTP errors (4xx, 5xx).
                'timeout' => 11,
            ],
            'ssl' => [
                'verify_peer' => config('imageproxy.ssl_verify_peer', true), // Work around possible SSL errors.
                'verify_peer_name' => config('imageproxy.ssl_verify_peer_name', true),
            ],
            'socket' => [
                'bindto' => $bindTo,
            ],
        ];

        return stream_context_create($args);
    }

    /**
     * Get HTTP headers.
     *
     * @param  resource  $streamContext
     * @return array
     */
    protected function getHttpHeaders($streamContext)
    {
        $metadata = stream_get_meta_data($streamContext);

        $status = 0;

        $headers = [];

        foreach ($metadata['wrapper_data'] as $line) {
            if (preg_match('~^http/(?:.+?) (\d+) (?:.+?)$~i', $line, $match)) {
                // Covers also subsequent statuses, like after a redirect.
                $status = (int) $match[1];
                continue;
            }

            $row = explode(': ', $line);

            if (count($row) > 1) {
                $headers[array_shift($row)] = implode(': ', $row);
            }
        }

        return [$status, $headers];
    }

    /**
     * Get remote file handle.
     *
     * To work around possible IPv6 issues, essentially.
     *
     * @param  resource  $streamContext
     * @return resource|null
     */
    protected function openFile(string $url, array $headers)
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (empty($host)) {
            // Not a proper URL.
            return null;
        }

        $hasIpv6 = false;
        $hasIpv4 = false;

        $dnsRecords = dns_get_record($host, DNS_AAAA + DNS_A);

        foreach ($dnsRecords as $dnsRecord) {
            if (empty($dnsRecord['type'])) {
                continue;
            }

            switch ($dnsRecord['type']) {
                case 'AAAA':
                    $hasIpv6 = true;
                    break;

                case 'A':
                    $hasIpv4 = true;
                    break;
            }
        }

        if ($hasIpv6) {
            $bindTo = '[0]:0';
        } elseif ($hasIpv4) {
            $bindTo = '0:0';
        }

        try {
            // Try IPv6 if it exists, and IPv4 otherwise.
            return fopen($url, 'rb', false, $this->createStreamContext($headers, $bindTo));
        } catch (\Exception $e) {
            // That didn't work.
            if ($bindTo === '[0]:0' && $hasIpv4) {
                // Try IPv4.
                $bindTo = '0:0';

                try {
                    return fopen($url, 'rb', false, $this->createStreamContext($headers, $bindTo));
                } catch (\Exception $e) {
                    // Giving up.
                    Log::error("Failed to open the image at $url: ".$e->getMessage());
                    abort(500);
                }
            }
        }

        return null;
    }

    protected function safeEncodeUrl(string $url): string
    {
        $dontEncode = [
            '%2F' => '/',
            '%40' => '@',
            '%3A' => ':',
            '%3B' => ';',
            '%2C' => ',',
            '%3D' => '=',
            '%2B' => '+',
            '%21' => '!',
            '%2A' => '*',
            '%7C' => '|',
            '%3F' => '?',
            '%26' => '&',
            '%23' => '#',
            '%25' => '%',
        ];

        return strtr(rawurlencode($url), $dontEncode);
    }
}
