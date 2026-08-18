<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $name, string $email, string $password, string $role = 'employee'): int
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $email, $hash, $role]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function allEmployees(): array
    {
        return Database::pdo()->query(
            "SELECT id, name, email, role FROM users ORDER BY name ASC"
        )->fetchAll();
    }

    public static function updateRole(int $id, string $role): void
    {
        $allowed = ['admin', 'editor', 'viewer', 'employee'];
        if (!in_array($role, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid role');
        }
        $stmt = Database::pdo()->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$role, $id]);
    }
}
