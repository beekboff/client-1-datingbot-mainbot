<?php

declare(strict_types=1);

namespace App\Migrations;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M202512171006_CreateProcessedUpdates implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->createTable('telegram_processed_updates', [
            'update_id' => $b->bigInteger()->notNull(),
            'created_at' => $b->dateTime()->notNull(),
        ]);
        $b->addPrimaryKey('telegram_processed_updates', 'pk_tpu_update_id', 'update_id');
        $b->createIndex('telegram_processed_updates', 'idx_tpu_created_at', 'created_at');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex('telegram_processed_updates', 'idx_tpu_created_at');
        $b->dropTable('telegram_processed_updates');
    }
}
