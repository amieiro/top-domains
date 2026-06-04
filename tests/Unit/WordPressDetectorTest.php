<?php

namespace Tests\Unit;

use App\Support\WordPressDetector;
use PHPUnit\Framework\TestCase;

class WordPressDetectorTest extends TestCase
{
    private WordPressDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new WordPressDetector;
    }

    public function test_detects_wp_content_path(): void
    {
        $body = '<html><head><link rel="stylesheet" href="/wp-content/themes/x/style.css"></head></html>';
        $this->assertTrue($this->detector->detect($body));
    }

    public function test_detects_generator_meta_case_insensitively(): void
    {
        $body = '<META NAME="Generator" CONTENT="WordPress 6.5">';
        $this->assertTrue($this->detector->detect($body));
    }

    public function test_detects_via_x_pingback_header(): void
    {
        $body = '<html><body>nothing here</body></html>';
        $headers = ['X-Pingback' => ['https://example.com/xmlrpc.php']];
        $this->assertTrue($this->detector->detect($body, $headers));
    }

    public function test_detects_via_rest_api_link_header(): void
    {
        $body = '<html><body>nothing here</body></html>';
        $headers = ['Link' => ['<https://example.com/wp-json/>; rel="https://api.w.org/"']];
        $this->assertTrue($this->detector->detect($body, $headers));
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $body = '<html><body>nothing here</body></html>';
        $headers = ['x-pingback' => ['https://example.com/xmlrpc.php']];
        $this->assertTrue($this->detector->detect($body, $headers));
    }

    public function test_versioned_asset_query_alone_is_not_wordpress(): void
    {
        // Drupal/Joomla/custom sites commonly version assets like this.
        $body = '<html><head><link href="/sites/all/style.css?ver=1.2.3"><script src="/app.js?ver=4.5"></script></head></html>';
        $this->assertFalse($this->detector->detect($body));
    }

    public function test_single_weak_signal_is_not_wordpress(): void
    {
        $body = '<form class="comment-form" action="/submit"></form>';
        $this->assertFalse($this->detector->detect($body));
    }

    public function test_two_weak_signals_are_wordpress(): void
    {
        $body = '<form class="comment-form"></form><div class="wp-block-image"></div>';
        $this->assertTrue($this->detector->detect($body));
    }

    public function test_plain_html_is_not_wordpress(): void
    {
        $body = '<html><head><title>Just a site</title></head><body><h1>Hello</h1></body></html>';
        $this->assertFalse($this->detector->detect($body));
    }

    public function test_empty_body_without_headers_is_not_wordpress(): void
    {
        $this->assertFalse($this->detector->detect(''));
    }
}
