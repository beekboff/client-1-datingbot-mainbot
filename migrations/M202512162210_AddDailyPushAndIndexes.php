<?php

declare(strict_types=1);

namespace App\Migrations;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M202512162210_AddDailyPushAndIndexes implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        // Add daily push counter
        $b->addColumn('users', 'daily_push_count', $b->integer()->notNull()->defaultValue(0));

        // Helpful indexes for scheduler performance
        $b->createIndex('users', 'idx_users_last_push', 'last_push');
        $b->createIndex('users', 'idx_users_daily_push_count', 'daily_push_count');
        $b->createIndex('users', 'idx_users_status', 'status');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex('users', 'idx_users_status');
        $b->dropIndex('users', 'idx_users_daily_push_count');
        $b->dropIndex('users', 'idx_users_last_push');
        $b->dropColumn('users', 'daily_push_count');
    }
}
