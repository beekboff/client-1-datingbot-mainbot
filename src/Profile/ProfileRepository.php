<?php

declare(strict_types=1);

namespace App\Profile;

use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

final class ProfileRepository
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * Returns random profile matching gender that user hasn't seen yet.
     * @return array{id:int,file:string,gender:string}|null
     */
    public function getRandomUnseenByGender(int $userId, string $gender): ?array
    {
        $batch = $this->getUnseenBatchByGender($userId, $gender, 1);
        return $batch[0] ?? null;
    }

    /**
     * Returns up to $limit unseen profiles matching gender using fast range scan instead of RAND().
     * @return array<int, array{id:int,file:string,gender:string}>
     */
    public function getUnseenBatchByGender(int $userId, string $gender, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        // Get cached min/max id by gender to pick a random probe point
        $bounds = $this->cache->get($this->boundsCacheKey($gender));
        if (!is_array($bounds) || !isset($bounds['min'], $bounds['max']) || (int)$bounds['min'] <= 0 || (int)$bounds['max'] <= 0) {
            $row = (new Query($this->db))
                ->from('profiles')
                ->select(['min_id' => 'MIN(id)', 'max_id' => 'MAX(id)'])
                ->where(['gender' => $gender])
                ->one();
            $min = (int)($row['min_id'] ?? 0);
            $max = (int)($row['max_id'] ?? 0);
            $bounds = ['min' => $min, 'max' => $max];
            $this->cache->set($this->boundsCacheKey($gender), $bounds, 3600); // cache for 3600s
        }

        $minId = (int)$bounds['min'];
        $maxId = (int)$bounds['max'];
        if ($minId <= 0 || $maxId <= 0 || $minId > $maxId) {
            return [];
        }

        $picked = random_int($minId, $maxId);

        // attempt #1: id >= picked ascending
        $rows = $this->selectUnseenRange($userId, $gender, $picked, $limit, '>=', 'ASC');
        if (count($rows) < $limit) {
            // attempt #2: wrap-around lower range
            $remaining = $limit - count($rows);
            $rows2 = $this->selectUnseenRange($userId, $gender, $picked, $remaining, '<', 'DESC');
            $rows = array_merge($rows, $rows2);
        }

        // normalize
        return array_map(static fn(array $r) => [
            'id' => (int)$r['id'],
            'file' => (string)$r['file'],
            'gender' => (string)$r['gender'],
        ], $rows);
    }

    /**
     * @return array<int, array{id:int,file:string,gender:string}>
     */
    private function selectUnseenRange(int $userId, string $gender, int $pivotId, int $limit, string $cmp, string $order): array
    {
        if ($limit <= 0) {
            return [];
        }
        $q = (new Query($this->db))
            ->from(['p' => 'profiles'])
            ->select(['id' => 'p.id', 'file' => 'p.file', 'gender' => 'p.gender'])
            ->leftJoin(['s' => 'profiles_shown'], 's.profile_id = p.id AND s.user_id = :uid', [':uid' => $userId])
            ->where(['p.gender' => $gender])
            ->andWhere(['IS', 's.profile_id', null])
            ->andWhere("p.id $cmp :pivot", [':pivot' => $pivotId])
            ->orderBy(["p.id" => $order === 'ASC' ? SORT_ASC : SORT_DESC])
            ->limit($limit);

        $rows = $q->all();
        return is_array($rows) ? $rows : [];
    }

    private function boundsCacheKey(string $gender): string
    {
        return 'profiles_bounds_' . $gender;
    }

    public function markShown(int $userId, int $profileId): void
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->createCommand()
            ->upsert('profiles_shown', [
                'user_id' => $userId,
                'profile_id' => $profileId,
                'shown_at' => $now,
            ], [
                'shown_at' => $now,
            ])->execute();
    }

    public function clearShownForUser(int $userId): void
    {
        $this->db->createCommand()
            ->delete('profiles_shown', ['user_id' => $userId])
            ->execute();
    }

    /**
     * Create a profile row if it doesn't exist yet.
     * @return array{id:int, created:bool}
     */
    public function createIfNotExists(string $file, string $gender): array
    {
        $gender = $gender === 'woman' ? 'woman' : 'man';

        $row = (new Query($this->db))
            ->from('profiles')
            ->select(['id'])
            ->where(['file' => $file, 'gender' => $gender])
            ->one();
        if (is_array($row) && isset($row['id'])) {
            return ['id' => (int)$row['id'], 'created' => false];
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db->createCommand()
            ->insert('profiles', [
                'file' => $file,
                'gender' => $gender,
                'created_at' => $now,
            ])->execute();

        // For MySQL getLastInsertID returns string
        try {
            $lastId = (int)$this->db->getLastInsertID();
            if ($lastId > 0) {
                // Invalidate bounds cache for this gender so new ids are considered
                $this->cache->delete($this->boundsCacheKey($gender));
                return ['id' => $lastId, 'created' => true];
            }
        } catch (\Throwable) {
            // fallback to select
        }

        $row2 = (new Query($this->db))
            ->from('profiles')
            ->select(['id'])
            ->where(['file' => $file, 'gender' => $gender])
            ->one();
        $this->cache->delete($this->boundsCacheKey($gender));
        return ['id' => is_array($row2) ? (int)$row2['id'] : 0, 'created' => true];
    }
}
