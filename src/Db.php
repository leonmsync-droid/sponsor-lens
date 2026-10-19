<?php
declare(strict_types=1);

// Thin PDO wrapper.
final class Db
{
    private PDO $pdo;

    public function __construct(array $cfg)
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4');
        $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = [])
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    // INSERT ... ON DUPLICATE KEY UPDATE for every non-key column.
    public function upsert(string $table, array $row, array $keys = ['id']): void
    {
        $cols = array_keys($row);
        $updates = array_map(fn($c) => "`$c` = VALUES(`$c`)", array_diff($cols, $keys));
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)%s',
            $table,
            implode('`, `', $cols),
            implode(', ', array_fill(0, count($cols), '?')),
            $updates ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates) : ''
        );
        $this->run($sql, array_values($row));
    }

    public function transaction(callable $fn)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getKv(string $k): ?string
    {
        return $this->value('SELECT v FROM kv WHERE k = ?', [$k]);
    }

    public function setKv(string $k, string $v): void
    {
        $this->upsert('kv', ['k' => $k, 'v' => $v, 'updated_at' => gmdate('Y-m-d H:i:s')], ['k']);
    }
}
