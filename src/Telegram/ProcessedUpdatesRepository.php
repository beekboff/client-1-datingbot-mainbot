<?php

declare(strict_types=1);

namespace App\Telegram;

use DateTimeImmutable;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;

final class ProcessedUpdatesRepository
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * Tries to insert the given update id. Returns false if already exists.
     */
    public function tryInsert(int $updateId): bool
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            $affected = (int)$this->db->createCommand()
                ->insert('telegram_processed_updates', [
                    'update_id' => $updateId,
                    'created_at' => $now,
                ])->execute();
            return $affected > 0;
        } catch (IntegrityException $e) {
            // duplicate primary key
            return false;
        }
    }

    /**
     * Deletes rows older than the specified number of days. Returns number of deleted rows.
     */
    public function deleteOlderThanDays(int $days): int
    {
        $days = max(1, $days);
        $threshold = (new DateTimeImmutable("now"))
            ->modify("-{$days} days")
            ->format('Y-m-d H:i:s');
        return (int)$this->db->createCommand()
            ->delete('telegram_processed_updates', ['<', 'created_at', $threshold])
            ->execute();
    }
}
