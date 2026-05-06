<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Voucher product matching
    |--------------------------------------------------------------------------
    |
    | Keep both handles here because the storefront has two voucher products:
    | the normal Gutschein and the steuerfreier Essensgutschein. IDs can be
    | added in production through VOUCHER_PRODUCT_IDS when title/handle matching
    | is not enough or a product title changes in Shopify.
    |
    */
    'product_handles' => array_filter(array_map('trim', explode(',', env('VOUCHER_PRODUCT_HANDLES', 'gutschein,steuerfreier-essensgutschein')))),
    'product_ids' => array_filter(array_map('trim', explode(',', env('VOUCHER_PRODUCT_IDS', '')))),

    /*
    |--------------------------------------------------------------------------
    | Gift card issuing behavior
    |--------------------------------------------------------------------------
    |
    | Native Shopify gift-card products already create their own gift cards.
    | Creating another gift card for those same line items would double the
    | redeemable value, so native line items are safely recorded as unavailable
    | by default. If the voucher products are converted to normal products, this
    | service will create and store full codes for every purchased quantity.
    |
    */
    'enabled' => env('VOUCHER_CODES_ENABLED', true),
    'create_shopify_gift_cards' => env('VOUCHER_CREATE_SHOPIFY_GIFT_CARDS', true),
    'skip_native_gift_card_creation' => env('VOUCHER_SKIP_NATIVE_GIFT_CARD_CREATION', true),
    'notify_customer' => env('VOUCHER_NOTIFY_CUSTOMER', false),
    'code_prefix' => env('VOUCHER_CODE_PREFIX', 'SC'),
];
