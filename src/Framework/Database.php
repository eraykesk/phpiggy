<?php

declare(strict_types=1);

namespace Framework;

use PDO, PDOException;

class Database
{
    private PDO $connection;

    public function __construct(
        string $driver,
        array $config,
        string $username,
        string $password
    ) {
        $config = http_build_query(data: $config, arg_separator: ';');

        $dsn = "{$driver}:{$config}";

        try {
            $this->connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            die("Unable to connect to database");
        }
    }

    public function query(string $query, array $params = []): Statement
    {
        $stmt = $this->connection->prepare($query);

        $stmt->execute($params);

        return new Statement($stmt);
    }

    public function id(): string|false
    {
        return $this->connection->lastInsertId();
    }
}
