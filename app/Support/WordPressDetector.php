<?php

namespace App\Support;

class WordPressDetector
{
    /**
     * Strong signals: any single match is enough to classify as WordPress.
     * All matched against a lower-cased body.
     *
     * @var array<int, string>
     */
    private array $strongSignals = [
        '<meta name="generator" content="wordpress',
        '/wp-content/',
        '/wp-includes/',
        '/wp-admin/',
        'wp-login.php',
        '/wp-json/',
        'wp-json/wp/v2',
        'wp-emoji-release.min.js',
        'wp-block-library',
        'dashicons.min.css',
        'rel="pingback"',
    ];

    /**
     * Weak signals: at least two distinct matches are required, because each on
     * its own is shared with non-WordPress sites or copied markup.
     *
     * @var array<int, string>
     */
    private array $weakSignals = [
        'class="wp-',
        'id="wp-',
        'wp-block-',
        'wp-site-blocks',
        'wp-container-',
        'wp-image-',
        'wp-caption',
        'comment-form',
        'comment-list',
        'comment-body',
        'commentform',
        'wp-block-image',
        'wp-block-gallery',
    ];

    /**
     * Decide whether a successful HTTP response belongs to a WordPress site.
     *
     * @param  string  $body  The (possibly truncated) response body.
     * @param  array<string, array<int, string>>  $headers  Guzzle-style headers.
     */
    public function detect(string $body, array $headers = []): bool
    {
        if ($this->headersIndicateWordPress($headers)) {
            return true;
        }

        if ($body === '') {
            return false;
        }

        $body = strtolower($body);

        foreach ($this->strongSignals as $signal) {
            if (str_contains($body, $signal)) {
                return true;
            }
        }

        // RSD / XML-RPC discovery is only meaningful alongside xmlrpc.php.
        if (str_contains($body, 'xmlrpc.php') &&
            (str_contains($body, 'rsd+xml') || str_contains($body, 'really simple discovery'))) {
            return true;
        }

        // A shortlink permalink (rel="shortlink" ... ?p=ID) is WordPress-specific.
        if (str_contains($body, 'rel="shortlink"') && str_contains($body, '?p=')) {
            return true;
        }

        $weakMatches = 0;
        foreach ($this->weakSignals as $signal) {
            if (str_contains($body, $signal)) {
                $weakMatches++;
                if ($weakMatches >= 2) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Inspect HTTP headers for WordPress-specific fingerprints. Header names are
     * matched case-insensitively, as required by RFC 7230.
     *
     * @param  array<string, array<int, string>>  $headers
     */
    private function headersIndicateWordPress(array $headers): bool
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            $normalized[strtolower($name)] = (array) $values;
        }

        foreach ($normalized['x-powered-by'] ?? [] as $value) {
            if (stripos($value, 'WordPress') !== false) {
                return true;
            }
        }

        foreach ($normalized['x-pingback'] ?? [] as $value) {
            if (str_contains($value, 'xmlrpc.php')) {
                return true;
            }
        }

        foreach ($normalized['link'] ?? [] as $value) {
            if (str_contains($value, 'wp-json') || str_contains($value, 'api.w.org')) {
                return true;
            }
        }

        return false;
    }
}
