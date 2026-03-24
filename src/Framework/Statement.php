<?php

declare(strict_types=1);

namespace Framework;

use PDOStatement;

class Statement
{
    public function __construct(private PDOStatement $stmt)
    {
    }

    public function count(): int|string|false
    {
        return $this->stmt->fetchColumn();
    }

    public function find(): array|false
    {
        return $this->stmt->fetch();
    }

    public function findAll(): array
    {
        return $this->stmt->fetchAll();
    }
}
