<?php

declare(strict_types=1);

namespace App\User;

use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final class UserRepository
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function isRegistered(int $userId): bool
    {
        return (new Query($this->db))
            ->from('users')
            ->select('*')
            ->where(['user_id' => $userId])
            ->exists();
    }

    public function deactivate(int $userId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->createCommand()
            ->update('users', [
                'status' => 0,
                'updated_at' => $now,
            ], ['user_id' => $userId])
            ->execute();
    }

    public function activate(int $userId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->createCommand()
            ->update('users', [
                'status' => 1,
                'updated_at' => $now,
            ], ['user_id' => $userId])
            ->execute();
    }

    public function register(int $userId, string $language): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->createCommand()
            ->upsert('users', [
                'user_id' => $userId,
                'language' => $language,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'language' => $language,
                'updated_at' => $now,
            ])->execute();
    }

    public function setPreference(int $userId, string $lookingFor): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->createCommand()
            ->update('users', [
                'looking_for' => $lookingFor,
                'updated_at' => $now,
            ], ['user_id' => $userId])
            ->execute();
    }

    public function updateLastPush(int $userId, DateTimeImmutable $dt): void
    {
        $ts = $dt->format('Y-m-d H:i:s');
        $this->db->createCommand()
            ->update('users', [
                'last_push' => $ts,
                'updated_at' => $ts,
            ], ['user_id' => $userId])
            ->execute();
    }

    /**
     * Find users that are eligible to receive a push: active status, daily cap not reached,
     * and last push or interaction was more than 1 hour ago (or never).
     * Returns array of rows with keys: user_id, language.
     *
     * @return array<int, array{user_id:int,language:string}>
     */
    public function findDueUsers(DateTimeImmutable $now, int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        $threshold = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $rows = (new Query($this->db))
            ->from('users')
            ->select(['user_id', 'language', 'looking_for'])
            ->where(['status' => 1])
            ->andWhere(['<', 'daily_push_count', 5])
            ->andWhere(['or', ['IS', 'last_push', null], ['<=', 'last_push', $threshold]])
            ->orderBy(['last_push' => SORT_ASC])
            ->limit($limit)
            ->all();
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static fn(array $r) => [
            'user_id' => (int)$r['user_id'],
            'language' => (string)$r['language'],
        ], $rows);
    }

    /**
     * Atomically marks that a push is being enqueued: increments daily counter and sets last_push to now
     * but only if cap not reached and at least 1 hour passed since last push/interaction (or never).
     */
    public function tryMarkPushEnqueued(int $userId, DateTimeImmutable $now): bool
    {
        $tsNow = $now->format('Y-m-d H:i:s');
        $threshold = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $cmd = $this->db->createCommand()
            ->update('users', [
                'daily_push_count' => new \Yiisoft\Db\Expression\Expression('daily_push_count + 1'),
                'last_push' => $tsNow,
                'updated_at' => $tsNow,
            ], [
                'and',
                ['user_id' => $userId],
                ['status' => 1],
                ['<', 'daily_push_count', 5],
                ['or', ['IS', 'last_push', null], ['<=', 'last_push', $threshold]],
            ]);
        $affected = (int)$cmd->execute();
        return $affected > 0;
    }

    /** Resets daily push counters for all users to 0. */
    public function resetDailyPushCounters(): int
    {
        return (int)$this->db->createCommand()
            ->update('users', ['daily_push_count' => 0])
            ->execute();
    }

    public function getLanguage(int $userId): ?string
    {
        $row = (new Query($this->db))
            ->from('users')
            ->select('language')
            ->where(['user_id' => $userId])
            ->one();
        return is_array($row) ? (string)$row['language'] : null;
    }

    public function getPreference(int $userId): ?string
    {
        $row = (new Query($this->db))
            ->from('users')
            ->select('looking_for')
            ->where(['user_id' => $userId])
            ->one();
        $val = is_array($row) ? ($row['looking_for'] ?? null) : null;
        if (!is_string($val) || ($val !== 'woman' && $val !== 'man')) {
            return null;
        }
        return $val;
    }
}
