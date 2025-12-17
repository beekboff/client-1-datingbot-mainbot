<?php

declare(strict_types=1);

namespace App\Migrations;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M202512122315_AddProfileIndexes implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        // Replace simple gender index with composite (gender, id) for efficient range scans
        $b->dropIndex('profiles', 'idx_profiles_gender');
        $b->createIndex('profiles', 'idx_profiles_gender_id', ['gender', 'id']);

        // Add index for time-based cleanup of shown items
        $b->createIndex('profiles_shown', 'idx_profiles_shown_shown_at', 'shown_at');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex('profiles', 'idx_profiles_gender_id');
        $b->dropIndex('profiles_shown', 'idx_profiles_shown_shown_at');
        // restore simple gender index
        $b->createIndex('profiles', 'idx_profiles_gender','gender');
    }
}
