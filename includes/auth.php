<?php
require_once __DIR__ . '/db.php';

class Auth {
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function login(string $username, string $password): bool {
        self::startSession();
        $user = Database::fetchOne(
            "SELECT u.*, r.name as role_name, r.display_name as role_label
             FROM users u JOIN roles r ON u.role_id = r.id
             WHERE u.username = ? AND u.is_active = 1",
            [$username]
        );
        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user'] = $user;
            $_SESSION['login_time'] = time();
            Database::query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
            self::auditLog('login', 'auth', null, null, ['username' => $username]);
            return true;
        }
        return false;
    }

    public static function logout(): void {
        self::startSession();
        self::auditLog('logout', 'auth', null, null, []);
        session_destroy();
    }

    public static function isLoggedIn(): bool {
        self::startSession();
        if (!isset($_SESSION['user'], $_SESSION['login_time'])) return false;
        if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
            self::logout();
            return false;
        }
        return true;
    }

    public static function requireLogin(): void {
        if (!self::isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function user(): ?array {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }

    public static function role(): string {
        return self::user()['role_name'] ?? '';
    }

    public static function userId(): int {
        return (int)(self::user()['id'] ?? 0);
    }

    public static function hasAccess(string $module): bool {
        $role = self::role();
        $access = ROLE_ACCESS[$role] ?? [];
        return in_array('*', $access) || in_array($module, $access) ||
               in_array(explode('.', $module)[0], $access);
    }

    public static function can(string $module): bool {
        return self::hasAccess($module);
    }

    public static function homeUrl(): string {
        switch (self::role()) {
            case 'manager':
                return '/pages/reports.php';
            case 'mechanic':
                return '/pages/maintenance.php';
            case 'accountant':
                return 'rzd_ais/logout.php';
            default:
                return '/index.php';
        }
    }

    public static function auditLog(string $action, string $module, ?string $entityType, ?int $entityId, array $data): void {
        try {
            Database::query(
                "INSERT INTO audit_log (user_id, action, module, entity_type, entity_id, new_data, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    self::userId() ?: null,
                    $action, $module, $entityType, $entityId,
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]
            );
        } catch (Exception $e) {}
    }
}
