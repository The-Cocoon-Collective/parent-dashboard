<?php
/**
 * The Cocoon Collective™ — parent portal configuration
 *
 * Copy this file to config.php and fill it in. config.php is the only file
 * holding secrets; it sits OUTSIDE public/ so the web server can never serve
 * it even if PHP stops running.
 */

return [

    // ---- database (its own, separate from everything else) -----------------
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',     // the database created for the portal
        'user' => '',
        'pass' => '',
    ],

    // ---- the terms the consent record points at ----------------------------
    // Changing this asks every parent again rather than carrying their old
    // consent silently forward. Use a date, so it sorts.
    'terms_version' => '2026-10-10',

    // ---- what the keys screen tells the parent -----------------------------
    'server_address'    => 'play.cocoon-collective.org',
    'minecraft_version' => '1.21.8',
    'dashboard_url'     => '/dashboard/',

    // ---- payment -----------------------------------------------------------
    // The payment transaction is the verifiable parental consent mechanism.
    // While this is false the flow records consent and charges nothing, so the
    // whole sign-up can be walked end to end before a processor exists.
    'payments_live' => false,
    'processor'     => 'stripe',

    // ---- environment --------------------------------------------------------
    'https_only' => true,   // set false ONLY on a local machine without TLS
];
