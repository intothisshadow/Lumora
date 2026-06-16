<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Authentication
 *
 * Single-admin for V1; schema supports additional users/roles for future expansion.
 * Sessions are started by bootstrap.php before these functions are called.
 *
 * Persistent "Remember Me" uses a split-token scheme:
 *   - Cookie value:  selector (32 hex chars) + ':' + validator (64 hex chars)
 *   - DB stores:     selector plain, SHA-256(validator) as hashed_validator
 * Timing-safe comparison via hash_equals() prevents validator enumeration.
 * Validator mismatch on a known selector is treated as a theft attempt and
 * ALL tokens for the affected user are revoked immediately.
 * Tokens are rotated on every successful auto-login.
 * DB operations on {PREFIX}remember_tokens are wrapped in catch(\Throwable)
 * so that pre-v3 installations (table absent) are unaffected.
 *
 * Password Reset (DB version 7):
 *   - Same split-token scheme as remember-me.
 *   - Tokens expire after 1 hour and are single-use.
 *   - The reset URL is always written to lumora_recovery.txt in LUMORA_ROOT
 *     so admins without email can retrieve it via FTP / file manager.
 *   - If the admin account has an email address set, a best-effort send via
 *     mail() is attempted in addition to the recovery file.
 *   - DB operations on {PREFIX}password_reset_tokens are wrapped in
 *     catch(\Throwable) so that pre-v7 installations (table absent) are
 *     unaffected — lumora_create_reset_token() is the only function that
 *     intentionally re-throws on insert failure.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

define('LUMORA_SESSION_KEY',    'lumora_auth');
define('LUMORA_SESSION_TTL',    7200);           // seconds – idle/age timeout (2 h)
define('LUMORA_REMEMBER_COOKIE', 'lumora_remember');
define('LUMORA_REMEMBER_DAYS',   30);            // persistent-cookie lifetime

// ── Login / Logout ────────────────────────────────────────────────────────────

/**
 * Attempt to authenticate a user.
 *
 * When $remember is true a persistent remember-me token is created and a
 * 30-day cookie is set. Requires the {PREFIX}remember_tokens table (DB v3+);
 * on older installs the cookie/DB write fails silently and the user still
 * gets a normal session-only login.
 *
 * Returns the user row on success, null on failure.
 */
function lumora_login(string $username, string $password, bool $remember = false): ?array
{
    $user = LumoraDB::fetchOne(
        'SELECT * FROM `{PREFIX}users` WHERE username = ?',
        [trim($username)]
    );

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    // Update last login timestamp.
    LumoraDB::query('UPDATE `{PREFIX}users` SET last_login = NOW() WHERE id = ?', [$user['id']]);

    // Store session payload.
    $_SESSION[LUMORA_SESSION_KEY] = [
        'user_id'  => (int) $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'login_at' => time(),
    ];

    // Rotate session ID to prevent fixation.
    session_regenerate_id(true);

    if ($remember) {
        lumora_create_remember_token((int) $user['id']);
    }

    return $user;
}

/**
 * Log out the current user.
 *
 * When $clear_remember is true (explicit user-initiated logout) the persistent
 * cookie is cleared and all remember-me tokens for the user are revoked.
 * Session-expiry calls within lumora_is_logged_in() pass false so the cookie
 * survives and can auto-log the user back in on the next request.
 */
function lumora_logout(bool $clear_remember = false): void
{
    if ($clear_remember) {
        $user_id = (int) (($_SESSION[LUMORA_SESSION_KEY] ?? [])['user_id'] ?? 0);
        if ($user_id > 0) {
            lumora_clear_remember_tokens($user_id);
        }
        lumora_clear_remember_cookie();
    }

    unset($_SESSION[LUMORA_SESSION_KEY]);
    session_regenerate_id(true);
}

// ── Session checks ────────────────────────────────────────────────────────────

/**
 * Return true if a user is authenticated and their session has not expired.
 */
function lumora_is_logged_in(): bool
{
    if (!isset($_SESSION[LUMORA_SESSION_KEY])) return false;
    $s = $_SESSION[LUMORA_SESSION_KEY];
    if ((time() - ($s['login_at'] ?? 0)) > LUMORA_SESSION_TTL) {
        // Session expired; keep the remember-me cookie so bootstrap can
        // transparently re-authenticate via lumora_check_remember_cookie().
        lumora_logout(false);
        return false;
    }
    return true;
}

/**
 * Return true only for users with the 'admin' role.
 */
function lumora_is_admin(): bool
{
    return lumora_is_logged_in()
        && (($_SESSION[LUMORA_SESSION_KEY]['role'] ?? '') === 'admin');
}

/**
 * Return the current user's session payload array, or null if not logged in.
 */
function lumora_current_user(): ?array
{
    return lumora_is_logged_in() ? $_SESSION[LUMORA_SESSION_KEY] : null;
}

/**
 * Enforce admin access. Redirects to the login page if the check fails.
 * Call at the top of every admin page (after bootstrap).
 */
function lumora_require_admin(): void
{
    if (!lumora_is_admin()) {
        lumora_redirect(
            lumora_base_url() . 'admin/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '')
        );
    }
}

// ── CSRF ──────────────────────────────────────────────────────────────────────

/**
 * Return (and create if absent) the CSRF token for this session.
 */
function lumora_csrf_token(): string
{
    if (empty($_SESSION['lumora_csrf'])) {
        $_SESSION['lumora_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['lumora_csrf'];
}

/**
 * Return a hidden input field containing the CSRF token.
 */
function lumora_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(lumora_csrf_token()) . '">';
}

/**
 * Validate the CSRF token from a POST request.
 * Exits with 403 if validation fails.
 */
function lumora_csrf_validate(): void
{
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals(lumora_csrf_token(), $_POST['csrf_token'])
    ) {
        http_response_code(403);
        exit('CSRF validation failed.');
    }
}

// ── Remember Me ───────────────────────────────────────────────────────────────

/**
 * Create a new remember-me token for $user_id and set the persistent cookie.
 *
 * Split-token scheme:
 *   selector  — random 16 bytes as 32-char hex; stored plain; used for DB lookup.
 *   validator — random 32 bytes as 64-char hex; stored as SHA-256 in DB; full
 *               value travels in the cookie only.
 *
 * Fails silently on DB error so pre-v3 installs (table absent) are unaffected.
 */
function lumora_create_remember_token(int $user_id): void
{
    $selector  = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expires   = date('Y-m-d H:i:s', time() + (LUMORA_REMEMBER_DAYS * 86400));

    try {
        LumoraDB::insert('remember_tokens', [
            'user_id'          => $user_id,
            'selector'         => $selector,
            'hashed_validator' => hash('sha256', $validator),
            'expires_at'       => $expires,
        ]);
    } catch (\Throwable) {
        // {PREFIX}remember_tokens absent on pre-v3 installs; fail silently.
        return;
    }

    setcookie(LUMORA_REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires'  => time() + (LUMORA_REMEMBER_DAYS * 86400),
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Validate the remember-me cookie and, if valid, rebuild the admin session.
 *
 * Token is rotated on every successful call (old token deleted, new one issued)
 * to limit the exposure window if a token is ever compromised.
 *
 * Returns true when the session has been populated, false otherwise.
 * Silently ignores DB errors so pre-v3 installs are unaffected.
 */
function lumora_check_remember_cookie(): bool
{
    $cookie = $_COOKIE[LUMORA_REMEMBER_COOKIE] ?? '';
    if ($cookie === '') return false;

    // Cookie must be exactly "selector:validator".
    $parts = explode(':', $cookie, 2);
    if (count($parts) !== 2) {
        lumora_clear_remember_cookie();
        return false;
    }

    [$selector, $validator] = $parts;

    // Validate format before hitting the DB.
    if (
        !preg_match('/^[0-9a-f]{32}$/', $selector) ||
        !preg_match('/^[0-9a-f]{64}$/', $validator)
    ) {
        lumora_clear_remember_cookie();
        return false;
    }

    // Fetch token row by selector.
    try {
        $token = LumoraDB::fetchOne(
            'SELECT * FROM `{PREFIX}remember_tokens` WHERE selector = ?',
            [$selector]
        );
    } catch (\Throwable) {
        return false;
    }

    if (!$token) {
        lumora_clear_remember_cookie();
        return false;
    }

    // Check expiry.
    if (strtotime((string) $token['expires_at']) < time()) {
        try { LumoraDB::delete('remember_tokens', 'selector = ?', [$selector]); } catch (\Throwable) {}
        lumora_clear_remember_cookie();
        return false;
    }

    // Validate the hashed validator using constant-time comparison.
    $expected_hash = hash('sha256', $validator);
    if (!hash_equals((string) $token['hashed_validator'], $expected_hash)) {
        // Selector matched but validator did not — possible token theft.
        // Revoke every token for this user immediately.
        try { LumoraDB::delete('remember_tokens', 'user_id = ?', [(int) $token['user_id']]); } catch (\Throwable) {}
        lumora_clear_remember_cookie();
        return false;
    }

    // Load the user record.
    $user = LumoraDB::fetchOne(
        'SELECT * FROM `{PREFIX}users` WHERE id = ?',
        [(int) $token['user_id']]
    );

    if (!$user || $user['role'] !== 'admin') {
        try { LumoraDB::delete('remember_tokens', 'selector = ?', [$selector]); } catch (\Throwable) {}
        lumora_clear_remember_cookie();
        return false;
    }

    // Rotate: delete the consumed token and issue a fresh one.
    try { LumoraDB::delete('remember_tokens', 'selector = ?', [$selector]); } catch (\Throwable) {}
    lumora_create_remember_token((int) $user['id']);

    // Update last-login and rebuild the session.
    LumoraDB::query('UPDATE `{PREFIX}users` SET last_login = NOW() WHERE id = ?', [$user['id']]);

    $_SESSION[LUMORA_SESSION_KEY] = [
        'user_id'  => (int) $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'login_at' => time(),
    ];

    session_regenerate_id(true);

    return true;
}

/**
 * Expire the remember-me cookie immediately in the browser.
 */
function lumora_clear_remember_cookie(): void
{
    if (isset($_COOKIE[LUMORA_REMEMBER_COOKIE])) {
        setcookie(LUMORA_REMEMBER_COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * Delete all remember-me tokens for $user_id from the database.
 * Fails silently on pre-v3 installs where the table does not yet exist.
 */
function lumora_clear_remember_tokens(int $user_id): void
{
    try {
        LumoraDB::delete('remember_tokens', 'user_id = ?', [$user_id]);
    } catch (\Throwable) {
        // {PREFIX}remember_tokens absent on pre-v3 installs; fail silently.
    }
}

// ── User management ───────────────────────────────────────────────────────────

/**
 * Create a new admin user. Used by the installer.
 * Returns the new user ID.
 */
function lumora_create_admin(string $username, string $password, string $email = ''): int
{
    return (int) LumoraDB::insert('users', [
        'username'      => $username,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
        'email'         => $email,
        'role'          => 'admin',
    ]);
}

/**
 * Change the password for a user.
 * Returns true on success.
 */
function lumora_change_password(int $user_id, string $new_password): bool
{
    if (strlen($new_password) < 8) return false;
    return LumoraDB::update(
        'users',
        ['password_hash' => password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12])],
        'id = ?',
        [$user_id]
    ) > 0;
}

// ── Password Reset ────────────────────────────────────────────────────────────
//
// Split-token scheme (same as remember-me):
//   selector  — 32-char hex (16 random bytes); stored plain; used for DB lookup.
//   validator — 64-char hex (32 random bytes); stored as SHA-256 in DB; full
//               value travels in the reset URL only.
// Tokens expire after 1 hour and are single-use.
// The reset URL is written to lumora_recovery.txt in LUMORA_ROOT on every
// request so admins without email can retrieve it via FTP or a file manager.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a password-reset token for $user_id.
 *
 * Any existing reset tokens for the user are deleted before issuing a new one
 * (only one active token per user at a time).
 *
 * Throws on DB error — callers should wrap in try/catch and show an appropriate
 * error if the {PREFIX}password_reset_tokens table does not yet exist.
 *
 * @return array{selector: string, validator: string, expires_at: string}
 */
function lumora_create_reset_token(int $user_id): array
{
    $selector  = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $expires   = date('Y-m-d H:i:s', time() + 3600); // 1 hour

    // Revoke any outstanding reset tokens for this user first.
    try {
        LumoraDB::delete('password_reset_tokens', 'user_id = ?', [$user_id]);
    } catch (\Throwable) {
        // Table may be absent on pre-v7 installs; the INSERT below will surface
        // the real error to the caller.
    }

    LumoraDB::insert('password_reset_tokens', [
        'user_id'          => $user_id,
        'selector'         => $selector,
        'hashed_validator' => hash('sha256', $validator),
        'expires_at'       => $expires,
    ]);

    return ['selector' => $selector, 'validator' => $validator, 'expires_at' => $expires];
}

/**
 * Validate a password-reset token.
 *
 * Checks format, selector existence, expiry, and hashed validator.
 * Returns the user_id on success, null on any failure.
 *
 * Does NOT consume the token — call lumora_consume_reset_token() after the
 * password has been successfully changed.
 *
 * @return int|null  User ID on success, null on failure.
 */
function lumora_verify_reset_token(string $selector, string $validator): ?int
{
    // Validate token format before hitting the DB.
    if (
        !preg_match('/^[0-9a-f]{32}$/', $selector) ||
        !preg_match('/^[0-9a-f]{64}$/', $validator)
    ) {
        return null;
    }

    try {
        $token = LumoraDB::fetchOne(
            'SELECT * FROM `{PREFIX}password_reset_tokens` WHERE selector = ?',
            [$selector]
        );
    } catch (\Throwable) {
        return null;
    }

    if (!$token) {
        return null;
    }

    // Check expiry and prune expired token.
    if (strtotime((string) $token['expires_at']) < time()) {
        try { LumoraDB::delete('password_reset_tokens', 'selector = ?', [$selector]); } catch (\Throwable) {}
        return null;
    }

    // Constant-time validator check.
    if (!hash_equals((string) $token['hashed_validator'], hash('sha256', $validator))) {
        return null;
    }

    return (int) $token['user_id'];
}

/**
 * Consume (delete) a specific reset token by selector.
 * Called immediately after a successful password change.
 * Fails silently on DB errors.
 */
function lumora_consume_reset_token(string $selector): void
{
    try {
        LumoraDB::delete('password_reset_tokens', 'selector = ?', [$selector]);
    } catch (\Throwable) {}
}

/**
 * Delete all password-reset tokens for $user_id.
 * Fails silently on pre-v7 installs where the table does not yet exist.
 */
function lumora_clear_reset_tokens(int $user_id): void
{
    try {
        LumoraDB::delete('password_reset_tokens', 'user_id = ?', [$user_id]);
    } catch (\Throwable) {}
}
