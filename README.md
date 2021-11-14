# laravel-imageproxy
A pure-PHP image (and video, and audio) proxy for Laravel.

## Installation
Until this package becomes available on Packagist, you can simply clone the repository and install it using Composer's [path](https://getcomposer.org/doc/05-repositories.md#path) option. It should be automatically discovered by Laravel. The `/imageproxy` route is automaticaly registered.

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

Then, in your app, use `https://example.org/imageproxy/<hash>/<original-asset-url>` to have images (or video, or audio) delivered through your domain (`example.org`, in this case) and over HTTPS.

Or use or something like `https://example.org/imageproxy/<hash>/100x100/<original-image-url>` to resize images (and cache the result) before delivery.

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
