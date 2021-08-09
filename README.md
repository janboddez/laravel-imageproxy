# laravel-imageproxy
A pure-PHP image proxy for Laravel.

## Configuration
Publish the config file:
```
php artisan vendor:publish --tag=imageproxy-config
```
This'll create a new file at `config/imageproxy.php`.

Now, you can either directly edit that file, or add the following to your app's `.env`:
```
IMAGEPROXY_KEY=<your-secret-key>
```
You may have to run `php artisan config:clear` if you've previously cached your app's config.

Then, in your app, use either `https://example.org/imageproxy/<hash>/<original-image-url>` or something like `https://example.org/imageproxy/<hash>/100x100/<original-image-url>` to have images (or video) delivered through your domain (`example.org`, in this case) and over HTTPS.

The hash is calculated as follows:
```
$hash = hash_hmac('sha1', $url, config('imageproxy.secret_key'));
```

## Some Notes
This package is designed to stream unedited resources (i.e., those linked to using the first URL type) right through to the client, so as to minimally burden your server. It passes on headers mostly unchanged, too, which helps prevent large videos blocking page load, and should make video scrubbing work.

The second URL type will try to resize remote images before delivering them to the client. Use it on (remote) user avatars, or Open Graph images. Use of this function requires PHP's **Imagick** extension. Resized images are cached (indefinitely, for now) using Laravel's `Storage::put()`.

Finally, there's the following `.env` variables, too:
```
IMAGEPROXY_USER_AGENT=<your-user-agent-of-choice>
IMAGEPROXY_SSL_VERIFY_PEER=true
IMAGEPROXY_SSL_VERIFY_PEER_NAME=true
```
You might wish to, at your own risk, set the latter two to `false` to temporarily work around remote hosts' faulty SSL certificates.
