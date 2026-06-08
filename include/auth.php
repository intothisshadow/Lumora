<?php
declare(strict_types=1);
/**
 * Lumora Gallery — Authentication
 *
 * Single-admin for V1; schema supports additional users/roles for future expansion.
 * Sessions are started by bootstrap.php before these functions are called.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

define('LUMORA_SESSION_KEY', 'lumora_auth');
define('LUMORA_SESSION_TTL', 7200); // seconds (2 hours idle timeout)

// ── Login / Logout ────────────────────────────────────────────────────────────

/**
 * Attempt to authenticate a user.
 * Returns the user row on success, null on failure.
 */
function lumora_login(string $username, string $password): ?array
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

    return $user;
}

/**
 * Log out the current user.
 */
function lumora_logout(): void
{
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
        lumora_logout();
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
