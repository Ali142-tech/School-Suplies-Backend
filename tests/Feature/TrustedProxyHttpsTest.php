<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyHttpsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_proto-probe', fn () => asset('css/app.css'));
    }

    public function test_assets_are_https_when_cloudfront_reports_https(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '80', 'CloudFront-Forwarded-Proto' => 'https'])
            ->get('http://d1example.cloudfront.net/_proto-probe')
            ->assertSee('https://d1example.cloudfront.net/css/app.css', false);
    }

    public function test_assets_are_https_when_alb_forwards_https(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get('http://d1example.cloudfront.net/_proto-probe')
            ->assertSee('https://d1example.cloudfront.net/css/app.css', false);
    }

    public function test_plain_http_stays_http_without_proxy_headers(): void
    {
        $this->get('http://d1example.cloudfront.net/_proto-probe')
            ->assertSee('http://d1example.cloudfront.net/css/app.css', false);
    }
}
