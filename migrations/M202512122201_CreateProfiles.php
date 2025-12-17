<?php

declare(strict_types=1);

namespace App\Migrations;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

final class M202512122201_CreateProfiles implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function up(MigrationBuilder $b): void
    {
        // Profiles table
        $b->createTable('profiles', [
            'id' => $b->primaryKey(),
            'file' => $b->string(255)->notNull(),
            // store 'woman' | 'man' for consistency with user preference
            'gender' => $b->string(16)->notNull(),
            'created_at' => $b->dateTime()->notNull(),
        ]);
        $b->createIndex('profiles', 'idx_profiles_gender', 'gender');

        // Shown profiles table
        $b->createTable('profiles_shown', [
            'user_id' => $b->bigInteger()->notNull(),
            'profile_id' => $b->integer()->notNull(),
            'shown_at' => $b->dateTime()->notNull(),
        ]);
        $b->addPrimaryKey('profiles_shown', 'pk_profiles_shown', ['user_id', 'profile_id']);
        $b->createIndex('profiles_shown', 'idx_profiles_shown_user', 'user_id');

        // Optional FK (won't add ON DELETE cascades to keep it simple)
        // Some DBs need explicit name length limits; using simple name.
        $b->addForeignKey('profiles_shown', 'fk_profiles_shown_profile', 'profile_id', 'profiles', 'id');
    }

    public function down(MigrationBuilder $b): void
    {
        $b->dropForeignKey('profiles_shown', 'fk_profiles_shown_profile');
        $b->dropTable('profiles_shown');
        $b->dropTable('profiles');
    }
}
