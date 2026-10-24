<?php
/**
 * The Cocoon Collective™ — parent portal
 * Shared bootstrap: configuration, database, session, CSRF, small helpers.
 *
 * Plain PHP and MySQL on purpose. The portal has to run on a cPanel host with
 * no build step, no package manager and no deploy pipeline, and it has to be
 * readable by somebody evaluating whether the data boundary is real. A
 * framework would hide the one thing this repository exists to show.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Portal is not configured. Copy config.sample.php to config.php and fill it in.');
}
/** @var array $CONFIG */
$CONFIG = require $configFile;

// ---------------------------------------------------------------------------
// Session — cookie hardened, no third-party anything
// ---------------------------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($CONFIG['https_only']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('ccportal');
    session_start();
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------

function db(): PDO
{
    static $pdo = null;
    global $CONFIG;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $CONFIG['db']['host'],
        (int) $CONFIG['db']['port'],
        $CONFIG['db']['name']
    );

    $pdo = new PDO($dsn, $CONFIG['db']['user'], $CONFIG['db']['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    return isset($_POST['_csrf'])
        && is_string($_POST['_csrf'])
        && hash_equals(csrf_token(), $_POST['_csrf']);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $step): never
{
    header('Location: ?step=' . rawurlencode($step));
    exit;
}

/**
 * Append-only audit. Never takes free text about a child, and never a name.
 */
function audit(?int $parentId, string $kind, ?string $detail = null): void
{
    try {
        $st = db()->prepare(
            'INSERT INTO audit_event (parent_id, kind, detail) VALUES (?, ?, ?)'
        );
        $st->execute([$parentId, $kind, $detail]);
    } catch (Throwable $t) {
        // An audit failure must never take the parent's signup down with it.
        error_log('[portal] audit failed: ' . $t->getMessage());
    }
}

/**
 * The join code.
 *
 * Six characters a parent can read down the phone to a child in the next room.
 * The alphabet drops every glyph that is ambiguous when spoken or read:
 * no O/0, no I/1/L, no S/5, no B/8, no U/V, and no vowels at all — which also
 * means the code cannot accidentally spell a word.
 */
function generate_join_code(): string
{
    $alphabet = 'CDFGHJKMNPQRTWXY3479';
    $len      = strlen($alphabet);
    $out      = '';
    for ($i = 0; $i < 6; $i++) {
        $out .= $alphabet[random_int(0, $len - 1)];
    }
    return $out;
}

/**
 * The cipher token.
 *
 * 256 bits from the CSPRNG. NOT derived from anything — not a hash of the
 * parent id, not an HMAC of the email. A derived token leaks its input to
 * anybody who works out the scheme, and the whole point of the play side is
 * that it holds nothing reversible.
 */
function generate_cipher_token(): string
{
    return bin2hex(random_bytes(32));
}

function hash_code(string $code): string
{
    return hash('sha256', strtoupper($code));
}

/**
 * The flow, in order. Used for the progress bar and for refusing to let
 * somebody skip a step by editing the query string.
 */
function flow(): array
{
    return [
        'account'  => 'Your account',
        'consent'  => 'What we collect',
        'pay'      => 'Confirm it is you',
        'child'    => 'Your child',
        'keys'     => 'The keys',
    ];
}

function current_stage(): string
{
    return $_SESSION['stage'] ?? 'account';
}

function advance_to(string $stage): void
{
    $_SESSION['stage'] = $stage;
}

/** Has the parent got far enough to be allowed on this step? */
function may_view(string $want): bool
{
    $order = array_keys(flow());
    $at    = array_search(current_stage(), $order, true);
    $to    = array_search($want, $order, true);
    if ($at === false || $to === false) {
        return false;
    }
    return $to <= $at;
}

/** The terms version a consent record points at. Changing this asks everyone again. */
function terms_version(): string
{
    global $CONFIG;
    return $CONFIG['terms_version'];
}
