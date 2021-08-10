<?php

return [
    'secret_key' => env('IMAGEPROXY_KEY', ''),
    'user_agent' => env('IMAGEPROXY_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:76.0) Gecko/20100101 Firefox/76.0'),
    'ssl_verify_peer' => env('IMAGEPROXY_SSL_VERIFY_PEER', false),
    'ssl_verify_peer_name' => env('IMAGEPROXY_SSL_VERIFY_PEER_NAME', false),
];
