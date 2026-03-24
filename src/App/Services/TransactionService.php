<?php

declare(strict_types=1);

namespace App\Services;

use Framework\Database;

class TransactionService
{
    public function __construct(private Database $db)
    {
    }
    public function create(array $formData, int $userId)
    {
        $formattedDate = "{$formData['date']} 00:00:00";
        $this->db->query(
            "INSERT INTO transactions(user_id, description, amount, date)
            VALUES(:user_id, :description, :amount, :date)",
            [
                'user_id' => $userId,
                'description' => $formData['description'],
                'amount' => $formData['amount'],
                'date' => $formattedDate
            ]
        );
    }

    public function getUserTransactions(int $length, int $offset, int $userId)
    {
        $searchTerm = addcslashes($_GET['s'] ?? '', '%_');
        $params = [
            'user_id' => $userId,
            'description' => "%{$searchTerm}%"
        ];

        $transactions = $this->db->query(
            "SELECT *, DATE_FORMAT(date, '%Y-%m-%d') as formatted_date 
            FROM transactions 
            WHERE user_id = :user_id
            AND description LIKE :description
            LIMIT {$length} OFFSET {$offset}",
            $params
        )->findAll();

        $transactionIds = array_column($transactions, 'id');

        $receipts = [];
        if (!empty($transactionIds)) {
            $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
            $receipts = $this->db->query(
                "SELECT * FROM receipts WHERE transaction_id IN ($placeholders)",
                $transactionIds
            )->findAll();
        }

        $receiptsByTransaction = [];
        foreach ($receipts as $receipt) {
            $receiptsByTransaction[$receipt['transaction_id']][] = $receipt;
        }

        $transactions = array_map(
            fn(array $t) => [...$t, 'receipts' => $receiptsByTransaction[$t['id']] ?? []],
            $transactions
        );

        $transactionCount = $this->db->query(
            "SELECT COUNT(*)
            FROM transactions 
            WHERE user_id = :user_id
            AND description LIKE :description",
            $params
        )->count();

        return [$transactions, $transactionCount];
    }
    public function getUserTransaction(string $id, int $userId)
    {
        return $this->db->query(
            "SELECT *, DATE_FORMAT(date, '%Y-%m-%d') as formatted_date
            FROM transactions
            WHERE id = :id AND user_id = :user_id",
            [
                'id' => $id,
                'user_id' => $userId
            ]
        )->find();
    }
    public function update(array $formData, int $id, int $userId)
    {
        $formattedDate = "{$formData['date']} 00:00:00";
        $this->db->query(
            "UPDATE transactions
            SET description = :description,
            amount = :amount,
            date = :date
            WHERE id = :id
            AND user_id = :user_id",
            [
                'description' => $formData['description'],
                'amount' => $formData['amount'],
                'date' => $formattedDate,
                'id' => $id,
                'user_id' => $userId
            ]
        );
    }
    public function delete(int $id, int $userId)
    {
        $this->db->query(
            "DELETE FROM transactions WHERE id = :id AND user_id = :user_id",
            [
                'id' => $id,
                'user_id' => $userId
            ]
        );
    }
}
