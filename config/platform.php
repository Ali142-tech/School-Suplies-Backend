<?php

return [
    'domain' => env('PLATFORM_DOMAIN'),
    // Page addresses vendors cannot use: platform-owned legal/security pages and application routes.
    'reserved_page_slugs' => [
        'privacy', 'privacy-policy', 'terms', 'terms-of-service', 'terms-and-conditions', 'terms-of-use', 'refund-policy', 'refunds', 'return-policy', 'returns',
        'shipping-policy', 'cookie-policy', 'cookies', 'legal', 'security', 'disclaimer', 'acceptable-use', 'data-protection', 'gdpr', 'dmca', 'accessibility',
        'products', 'product', 'cart', 'checkout', 'order-confirmation', 'printing', 'account', 'login', 'register', 'auth', 'forgot-password', 'reset-password',
        'school', 'vendor', 'stores', 'store', 'tenant-store', 'admin', 'superadmin', 'api', 'site', 'storage', 'up', '_next',
    ],
    // Upper bound of files in one vendor's CMS media folder (storage abuse guard).
    'max_media_per_vendor' => 300,
    'reserved_slugs' => ['www', 'admin', 'api', 'app', 'mail', 'cdn', 'support', 'ftp', 'localhost', 'root', 'status'],
];
