# laravel-imageproxy
A pure-PHP image proxy for Laravel.

Publish the config file:
```
php artisan vendor:publish --tag=imageproxy-config
```
This'll create a new file at `app/config/imageproxy.php`.

Now, you can either directly edit that file, or add the following to your app's `.env`:
```
IMAGEPROXY_KEY=<your-secret-key>
IMAGEPROXY_USER_AGENT=<your-user-agent-of-choice>
```
Laravel will automatically pick these up. (Well, you may have to run `php artisan config:clear` if you've previously cached your app's config.)

Also, some remote servers **require** a (custom) user agent is set.

Then, in your app, use `https://example.org/imageproxy/<hash>/<original-image-url>` (the `imageproxy` route is auto-registered) to have images (or video) delivered through your domain (`example.org`, in this case) and over HTTPS.

To force-resize (only) images, use something like `https://example.org/imageproxy/<hash>/100x100/<original-image-url>` instead. Use of this function requires PHP's **Imagick** extension.

The hash is calculated as follows:
```
$hash = hash_hmac('sha1', $url, config('imageproxy.secret_key'));
```

Finally, there's the following two `.env` variables, too:
```
IMAGEPROXY_SSL_VERIFY_PEER=true
IMAGEPROXY_SSL_VERIFY_PEER_NAME=true
```
You might wish to, at your own risk, set both to false to temporarily work around remote hosts' faulty SSL certificates.
