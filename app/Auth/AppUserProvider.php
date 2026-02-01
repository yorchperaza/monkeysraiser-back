<?php

declare(strict_types=1);

namespace App\Auth;

use MonkeysLegion\Auth\Contract\AuthenticatableInterface;
use MonkeysLegion\Auth\Contract\UserProviderInterface;
use MonkeysLegion\Database\Contracts\ConnectionInterface;
use RuntimeException;

class AppUserProvider implements UserProviderInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private string $table = 'user',
        private string $modelClass = 'App\\Entity\\User'
    ) {}

    public function findById(int|string $id): ?AuthenticatableInterface
    {
        $stmt = $this->connection->pdo()->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $data ? $this->hydrate($data) : null;
    }

    public function findByEmail(string $email): ?AuthenticatableInterface
    {
        $stmt = $this->connection->pdo()->prepare("SELECT * FROM {$this->table} WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $data ? $this->hydrate($data) : null;
    }

    public function findByCredentials(array $credentials): ?AuthenticatableInterface
    {
        if (empty($credentials)) {
            return null;
        }

        $conditions = [];
        $params = [];
        foreach ($credentials as $key => $value) {
            if ($key === 'password') {
                continue;
            }
            $conditions[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        if (empty($conditions)) {
            return null;
        }

        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $data ? $this->hydrate($data) : null;
    }

    public function incrementTokenVersion(int|string $userId): void
    {
        $stmt = $this->connection->pdo()->prepare("UPDATE {$this->table} SET token_version = token_version + 1 WHERE id = :id");
        $stmt->execute(['id' => $userId]);
    }

    public function create(array $attributes): AuthenticatableInterface
    {
        $columns = array_keys($attributes);
        $placeholders = array_map(fn($col) => ':' . $col, $columns);

        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->connection->pdo()->prepare($sql);
        
        // Ensure array values are encoded for storage if necessary
        // (Though typically the Repo handles save, this simplified create() is for Auth registration)
        $dbAttributes = [];
        foreach ($attributes as $k => $v) {
            $dbAttributes[$k] = is_array($v) ? json_encode($v) : $v;
        }

        $stmt->execute($dbAttributes);

        $id = $this->connection->pdo()->lastInsertId();

        // Hydrate the return object
        $attributes['id'] = $id;
        if (!isset($attributes['token_version'])) {
            $attributes['token_version'] = 0;
        }

        return $this->hydrate($attributes);
    }

    public function updatePassword(int|string $userId, string $passwordHash): void
    {
        $stmt = $this->connection->pdo()->prepare("UPDATE {$this->table} SET password = :password WHERE id = :id");
        $stmt->execute(['password' => $passwordHash, 'id' => $userId]);
    }

    private function hydrate(array $data): AuthenticatableInterface
    {
        if (!class_exists($this->modelClass)) {
            throw new RuntimeException("User model class '{$this->modelClass}' not found.");
        }

        $user = new $this->modelClass();

        foreach ($data as $key => $value) {
            if (!property_exists($user, $key)) {
                continue;
            }

            $reflection = new \ReflectionProperty($user, $key);
            if (!$reflection->isPublic()) {
                $reflection->setAccessible(true);
            }

            // --- Custom Logic: Handle Type Conversion ---
            $type = $reflection->getType();
            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();
                
                // If expecting array but got string (likely JSON)
                if ($typeName === 'array' && is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $value = $decoded;
                    }
                }
                
                // If expecting int but got string (PDO default)
                if ($typeName === 'int' && is_string($value)) {
                    $value = (int) $value;
                }

                 // If expecting DateTime/DateTimeImmutable but got string
                 if (($typeName === 'DateTime' || $typeName === 'DateTimeImmutable') && is_string($value) && $value !== '') {
                    try {
                        $value = new $typeName($value);
                    } catch (\Exception $e) {
                         // ignore or log
                    }
                }
            }
            // ---------------------------------------------

            // Only set if value is compatible (simple check)
            if ($value === null && $type && !$type->allowsNull()) {
                 continue; 
            }

            $reflection->setValue($user, $value);
        }

        if (!$user instanceof AuthenticatableInterface) {
            throw new RuntimeException("User model '{$this->modelClass}' must implement AuthenticatableInterface.");
        }

        return $user;
    }
}
