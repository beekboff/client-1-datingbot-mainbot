<?php

declare(strict_types=1);

namespace App\Migrations;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M202512080001_CreateUsers implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        $b->createTable('users', [
            'user_id' => $b->bigInteger()->notNull(),
            'language' => $b->string(8)->notNull(),
            'looking_for' => $b->string(16),
            'status' => $b->tinyInteger()->notNull()->defaultValue(1),
            'last_push' => $b->dateTime(),
            'created_at' => $b->dateTime()->notNull(),
            'updated_at' => $b->dateTime()->notNull(),
        ]);
        $b->addPrimaryKey('users', 'pk_users_user_id', 'user_id');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropTable('users');
    }
}
