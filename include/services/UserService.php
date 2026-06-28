<?php
declare(strict_types=1);
/**
 * Lumora Gallery — User Service
 *
 * Manages user accounts (CRUD), the role hierarchy, and the permission
 * framework used by the admin panel and auth layer.
 *
 * Roles (DB version 9+):
 *   admin       — Full access to all gallery and administrative functions.
 *   moderator   — Content management: albums, images, comments, approved tools.
 *   contributor — Upload and manage own content; no administrative access.
 *
 * Permission constants are defined in ROLE_PERMISSIONS. The permission-checking
 * API (roleHasPermission / currentUserHasPermission) provides the foundation for
 * per-page role gates as non-admin roles gain admin panel access in future phases.
 *
 * All write methods validate their inputs and return `true` on success or a
 * human-readable error string on failure, so callers can flash the message
 * directly without knowing the reason.
 *
 * @copyright Copyright (C) 2025 Ariane
 * @license   GPL-3.0-or-later <https://www.gnu.org/licenses/gpl-3.0>
 */

if (!defined('LUMORA_ENTRY')) exit('Direct access denied.');

class UserService
{
    // ── Role constants ────────────────────────────────────────────────────────

    /** Canonical list of valid role slugs (order is display priority). */
    const ROLES = ['admin', 'moderator', 'contributor'];

    /** Human-readable label for each role. */
    const ROLE_LABELS = [
        'admin'       => 'Administrator',
        'moderator'   => 'Moderator',
        'contributor' => 'Contributor',
    ];

    /**
     * Permissions granted to each role.
     *
     * Permissions represent capabilities that individual admin pages and AJAX
     * endpoints can gate on. The 'admin' role is granted every permission
     * implicitly — the explicit list here also serves as documentation and
     * makes the admin set machine-readable for future UI.
     *
     * @var array<string, list<string>>
     */
    const ROLE_PERMISSIONS = [
        'admin' => [
            'site_configuration',   // Admin → Configuration, Installation Settings
            'user_management',      // Admin → Users
            'manage_albums',        // Admin → Albums, Categories
            'manage_images',        // Admin → Images, Batch Add
            'moderate_comments',    // Future comment moderation page
            'maintenance_tools',    // Admin → Tools
            'batch_add',            // Admin → Batch Add
            'view_updates',         // Admin → Updates
        ],
        'moderator' => [
            'manage_albums',
            'manage_images',
            'moderate_comments',
            'maintenance_tools',
        ],
        'contributor' => [
            'batch_add',
            'edit_own_images',
            'manage_assigned_albums',
        ],
    ];

    // ── Permission helpers ────────────────────────────────────────────────────

    /**
     * Return true when the given role has the named permission.
     */
    public static function roleHasPermission(string $role, string $permission): bool
    {
        return in_array($permission, self::ROLE_PERMISSIONS[$role] ?? [], true);
    }

    /**
     * Return all permission slugs granted to a role.
     *
     * @return list<string>
     */
    public static function getRolePermissions(string $role): array
    {
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    /**
     * Return true when the currently logged-in user has the named permission.
     * Returns false when no user is logged in.
     */
    public static function currentUserHasPermission(string $permission): bool
    {
        $user = lumora_current_user();
        if ($user === null) {
            return false;
        }
        return self::roleHasPermission((string) ($user['role'] ?? ''), $permission);
    }

    // ── Input validation helpers ──────────────────────────────────────────────

    /**
     * Validate a username string.
     *
     * @return true|string  true on success, error message on failure.
     */
    public static function validateUsername(string $username): true|string
    {
        if ($username === '') {
            return 'Username cannot be empty.';
        }
        if (!preg_match('/^[a-zA-Z0-9_.\-]{2,50}$/', $username)) {
            return 'Username may only contain letters, digits, underscores, hyphens, '
                 . 'and dots, and must be 2–50 characters.';
        }
        return true;
    }

    /**
     * Validate a password string.
     *
     * @return true|string  true on success, error message on failure.
     */
    public static function validatePassword(string $password): true|string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters long.';
        }
        return true;
    }

    /**
     * Return true when $username is already taken by a different user.
     */
    public static function usernameExists(string $username, int $exclude_id = 0): bool
    {
        return LumoraDB::fetchOne(
            'SELECT id FROM `{PREFIX}users` WHERE username = ? AND id != ?',
            [$username, $exclude_id]
        ) !== null;
    }

    /**
     * Return true when $email is already in use by a different user.
     * An empty email is never considered a duplicate.
     */
    public static function emailExists(string $email, int $exclude_id = 0): bool
    {
        if ($email === '') {
            return false;
        }
        return LumoraDB::fetchOne(
            "SELECT id FROM `{PREFIX}users` WHERE email = ? AND email != '' AND id != ?",
            [$email, $exclude_id]
        ) !== null;
    }

    // ── Read queries ──────────────────────────────────────────────────────────

    /**
     * Count all user accounts.
     */
    public static function countUsers(): int
    {
        return (int) LumoraDB::fetchValue('SELECT COUNT(*) FROM `{PREFIX}users`');
    }

    /**
     * Fetch a paginated list of users, sorted by role priority then username.
     * Requires Migration0003 (is_active column must exist).
     *
     * @return list<array{id: int, username: string, email: string, role: string,
     *                     is_active: int, last_login: string|null, created_at: string}>
     */
    public static function getPaginatedUsers(int $page, int $per_page): array
    {
        $page     = max(1, $page);
        $per_page = max(1, $per_page);
        $offset   = ($page - 1) * $per_page;

        return LumoraDB::fetchAll(
            "SELECT id, username, email, role, is_active, last_login, created_at
               FROM `{PREFIX}users`
              ORDER BY FIELD(role, 'admin', 'moderator', 'contributor'), username ASC
              LIMIT ? OFFSET ?",
            [$per_page, $offset]
        );
    }

    /**
     * Fetch a single user row by ID.
     * Returns null when not found.
     *
     * @return array{id: int, username: string, email: string, role: string,
     *               is_active: int, last_login: string|null, created_at: string}|null
     */
    public static function getUser(int $id): ?array
    {
        $row = LumoraDB::fetchOne(
            'SELECT id, username, email, role, is_active, last_login, created_at
               FROM `{PREFIX}users`
              WHERE id = ?',
            [$id]
        );
        return $row ?: null;
    }

    // ── Write operations ──────────────────────────────────────────────────────

    /**
     * Create a new user account.
     *
     * @return int|string  New user ID on success, error message string on failure.
     */
    public static function createUser(
        string $username,
        string $password,
        string $email,
        string $role
    ): int|string {
        $v = self::validateUsername($username);
        if ($v !== true) return $v;

        $v = self::validatePassword($password);
        if ($v !== true) return $v;

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Invalid email address format.';
        }

        if (!in_array($role, self::ROLES, true)) {
            return 'Invalid role selected.';
        }

        if (self::usernameExists($username)) {
            return 'That username is already taken.';
        }

        if (self::emailExists($email)) {
            return 'That email address is already in use by another account.';
        }

        $id = LumoraDB::insert('users', [
            'username'      => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
            'email'         => $email,
            'role'          => $role,
            'is_active'     => 1,
        ]);

        return (int) $id;
    }

    /**
     * Update a user's profile (username, email, and/or role).
     * Password changes must be made via resetPassword().
     *
     * @param array{username?: string, email?: string, role?: string} $data
     * @return true|string  true on success, error message on failure.
     */
    public static function updateUser(int $id, array $data): true|string
    {
        if (!self::getUser($id)) {
            return 'User not found.';
        }

        $updates = [];

        if (isset($data['username'])) {
            $username = trim($data['username']);
            $v = self::validateUsername($username);
            if ($v !== true) return $v;
            if (self::usernameExists($username, $id)) return 'That username is already taken.';
            $updates['username'] = $username;
        }

        if (array_key_exists('email', $data)) {
            $email = trim($data['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return 'Invalid email address format.';
            }
            if (self::emailExists($email, $id)) {
                return 'That email address is already in use by another account.';
            }
            $updates['email'] = $email;
        }

        if (isset($data['role'])) {
            if (!in_array($data['role'], self::ROLES, true)) {
                return 'Invalid role selected.';
            }
            $updates['role'] = $data['role'];
        }

        if (!empty($updates)) {
            LumoraDB::update('users', $updates, 'id = ?', [$id]);
        }

        return true;
    }

    /**
     * Enable or disable a user account.
     *
     * Guards:
     *   - Cannot deactivate the last active administrator account.
     *
     * @return true|string  true on success, error message on failure.
     */
    public static function setActive(int $id, bool $active, int $current_user_id): true|string
    {
        $user = self::getUser($id);
        if (!$user) {
            return 'User not found.';
        }

        // Guard: cannot deactivate the last active administrator.
        if (!$active && $user['role'] === 'admin') {
            $other_active_admins = (int) LumoraDB::fetchValue(
                "SELECT COUNT(*) FROM `{PREFIX}users`
                  WHERE role = 'admin' AND is_active = 1 AND id != ?",
                [$id]
            );
            if ($other_active_admins === 0) {
                return 'Cannot deactivate the last active administrator account.';
            }
        }

        LumoraDB::update('users', ['is_active' => $active ? 1 : 0], 'id = ?', [$id]);
        return true;
    }

    /**
     * Permanently delete a user account.
     *
     * Guards:
     *   - Cannot delete your own account.
     *   - Cannot delete the last remaining administrator account.
     *
     * Clears all remember-me and password-reset tokens for the deleted user.
     *
     * @return true|string  true on success, error message on failure.
     */
    public static function deleteUser(int $id, int $current_user_id): true|string
    {
        $user = self::getUser($id);
        if (!$user) {
            return 'User not found.';
        }

        if ($id === $current_user_id) {
            return 'You cannot delete your own account. '
                 . 'Use Account Management to make changes.';
        }

        if ($user['role'] === 'admin') {
            $other_admins = (int) LumoraDB::fetchValue(
                "SELECT COUNT(*) FROM `{PREFIX}users` WHERE role = 'admin' AND id != ?",
                [$id]
            );
            if ($other_admins === 0) {
                return 'Cannot delete the last administrator account.';
            }
        }

        // Revoke persistent tokens before deleting the row.
        lumora_clear_remember_tokens($id);
        lumora_clear_reset_tokens($id);

        LumoraDB::delete('users', 'id = ?', [$id]);
        return true;
    }

    /**
     * Reset a user's password (admin-initiated; no current-password check).
     *
     * Also revokes all remember-me tokens, forcing a fresh login on all
     * devices after the password is changed.
     *
     * @return true|string  true on success, error message on failure.
     */
    public static function resetPassword(int $id, string $new_password): true|string
    {
        $v = self::validatePassword($new_password);
        if ($v !== true) return $v;

        $affected = LumoraDB::update(
            'users',
            ['password_hash' => password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12])],
            'id = ?',
            [$id]
        );

        if ($affected === 0) {
            return 'User not found.';
        }

        // Invalidate all remember-me tokens to force a fresh login everywhere.
        lumora_clear_remember_tokens($id);

        return true;
    }

    // ── HTML helpers ──────────────────────────────────────────────────────────

    /**
     * Return an HTML <option> list for a role <select>.
     * The option matching $current is marked selected.
     */
    public static function roleOptions(string $current = ''): string
    {
        $html = '';
        foreach (self::ROLE_LABELS as $slug => $label) {
            $sel   = ($slug === $current) ? ' selected' : '';
            $html .= '<option value="' . h($slug) . '"' . $sel . '>'
                   . h($label) . '</option>';
        }
        return $html;
    }

    /**
     * Return a Bootstrap 5 badge for the given role slug.
     */
    public static function roleBadge(string $role): string
    {
        $label = self::ROLE_LABELS[$role] ?? $role;
        $cls   = match ($role) {
            'admin'       => 'bg-danger',
            'moderator'   => 'bg-warning text-dark',
            'contributor' => 'bg-secondary',
            default       => 'bg-light text-dark border',
        };
        return '<span class="badge ' . $cls . '">' . h($label) . '</span>';
    }
}
