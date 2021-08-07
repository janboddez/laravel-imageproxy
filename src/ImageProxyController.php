<?php

namespace janboddez\ImageProxy;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Imagick;

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
        // Use `$_SERVER` rather than `$url` or any of Laravel's URL functions
        // to avoid any processing that could lead to checksum errors. To do:
        // actually drop the `$hash` and `$url` vars?
        $path = ltrim(str_replace('imageproxy', '', $_SERVER['REQUEST_URI']), '/');
        $path = explode('/', $path);

        // First item's the checksum.
        $hash = array_shift($path);

        if (isset($path[0]) && preg_match('~^\d+x\d+~', $path[0], $matches)) {
            // New first item's a set of dimensions.
            list($width, $height) = explode('x', array_shift($path));
        }

        // Whatever's left would have to be the source URL.
        $url = implode('/', $path);

        if (! $this->verifyUrl($url, $hash)) {
            Log::error('Checksum verification failed for '.$url);
            // Invalid URL, hash, or both.
            abort(400);
        }

        if ($request->headers->has('if-modified-since') || $request->headers->has('if-none-match')) {
            // It would seem the client already has the requested item. To do:
            // also return the other headers a client would typically expect.
            // (Seems to work, though.)
            return response('', 304);
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
        ], function ($v) {
            return ! empty($v);
        });

        if (empty($width) || empty($height) || ! class_exists(Imagick::class)) {
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
                Log::debug(stream_get_contents($stream));

                // Return an empty response.
                fclose($stream);

                return response('', $status, $headers);
            }

            $headers['Cache-Control'] = 'public, max-age=31536000';

            if ($status >= 301) {
                $status = 200; // Not sure how we can get the status of the final URL, after forwarding, yet.
            }

            return response()->stream(function () use ($stream) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                fpassthru($stream);
            }, $status, $headers);
        } else {
            // Resize, and cache, the image (like in `storage/app/imageproxy`).
            // Don't bother with file extensions.
            $file = 'imageproxy/'.base64_encode($url)."_{$width}x{$height}";

            if (Storage::exists($file)) {
                // Return existing image. Uses `fpassthru()` under the hood and
                // is thus not all that different from the code above.
                return Storage::response($file);
            }

            // Set up Imagick.
            $im = new Imagick();
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

            // Resize and crop.
            $im->cropThumbnailImage((int) $width, (int) $height);
            $im->setImagePage(0, 0, 0, 0);
            $im->setImageCompressionQuality(82);

            // Store to disk.
            Storage::put($file, $im->getImageBlob());

            // Return newly stored image.
            return Storage::response($file);
        }
    }

    /**
     * Verify URL.
     *
     * @param  string  $url
     * @param  string  $hash
     * @return bool
     */
    protected function verifyUrl($url, $hash)
    {
        if (strpos($url, 'http') !== 0 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            // Invalid (for this purpose) URL.
            return false;
        }

        if ($hash === hash_hmac('sha1', $url, config('imageproxy.secret_key', ''))) {
            return true;
        }

        // Try again swapping some encoded entities (which ones?) for their actual counterparts.
        if ($hash === hash_hmac('sha1', str_replace(['%5B', '%5D'], ['[', ']'], $url), config('imageproxy.secret_key', ''))) {
            return true;
        }

        // Either the URL's malformed, or the hash is invalid.
        return false;
    }

    /**
     * Create a stream context.
     *
     * @param  array  $headers
     * @return resource
     */
    protected function createStreamContext($headers, $bindTo = null)
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

        $status = $metadata['wrapper_data'][0];
        $status = (int) explode(' ', $status)[1];

        $headers = [];

        foreach ($metadata['wrapper_data'] as $line) {
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
}
