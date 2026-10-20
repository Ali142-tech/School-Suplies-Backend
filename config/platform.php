<?php

return [
    'domain' => env('PLATFORM_DOMAIN'),
    /*
     * How storefronts are addressed. Tenant identity is always the vendor (slug / verified domain mapping); this only decides the URL shape.
     *  - path:   {base_url}/vendor/{slug}      (default; needs no DNS and no PLATFORM_DOMAIN)
     *  - domain: https://{slug}.{PLATFORM_DOMAIN} or a verified custom domain (needs PLATFORM_DOMAIN and activated domain mappings)
     */
    'storefront' => [
        'mode' => env('STOREFRONT_ROUTING_MODE', 'path'),
        // Public origin of the web frontend, e.g. https://school-suplies.vercel.app (path mode). Falls back to FRONTEND_URL.
        'base_url' => env('STOREFRONT_BASE_URL', env('FRONTEND_URL')),
        'path_prefix' => 'vendor',
    ],
    // Page addresses vendors cannot use: platform-owned legal/security pages and application routes.
    'reserved_page_slugs' => [
        'privacy', 'privacy-policy', 'terms', 'terms-of-service', 'terms-and-conditions', 'terms-of-use', 'refund-policy', 'refunds', 'return-policy', 'returns',
        'shipping-policy', 'cookie-policy', 'cookies', 'legal', 'security', 'disclaimer', 'acceptable-use', 'data-protection', 'gdpr', 'dmca', 'accessibility',
        'products', 'product', 'cart', 'checkout', 'order-confirmation', 'printing', 'account', 'login', 'register', 'auth', 'forgot-password', 'reset-password',
        'school', 'vendor', 'stores', 'store', 'tenant-store', 'admin', 'superadmin', 'api', 'site', 'storage', 'up', '_next',
    ],
    // Upper bound of files in one vendor's CMS media folder (storage abuse guard).
    'max_media_per_vendor' => 300,
    // Also reserved because /vendor/{slug} shares its path prefix with the vendor portal routes of the frontend.
    'reserved_slugs' => ['www', 'admin', 'api', 'app', 'mail', 'cdn', 'support', 'ftp', 'localhost', 'root', 'status',
        'login', 'register', 'forgot-password', 'dashboard', 'categories', 'orders', 'pages', 'printing', 'products', 'profile', 'schools', 'store-settings', 'team', 'website'],
];
