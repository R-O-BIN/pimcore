<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */


namespace Pimcore\Bundle\CoreBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Exception;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241114142759 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Introducing an index for user workspaces table';
    }

    public function up(Schema $schema): void
    {
        $this->createIndex($schema, 'users_workspaces_object');
        $this->createIndex($schema, 'users_workspaces_asset');
    }

    public function down(Schema $schema): void
    {
        $this->removeIndex($schema, 'users_workspaces_object');
        $this->removeIndex($schema, 'users_workspaces_asset');
    }

    private function createIndex(Schema $schema, string $tableName): void
    {
        if ($schema->hasTable($tableName)) {
            try {
                $schema->getTable($tableName)->addIndex(
                    ['userId', 'cpath', 'list'],
                    'idx_users_workspaces_list_permission'
                );
            } catch (Exception) {
                // index already exists
            }
        }
    }

    private function removeIndex(Schema $schema, string $tableName): void
    {
        if ($schema->hasTable($tableName)) {
            try {
                $schema->getTable($tableName)->dropIndex('idx_users_workspaces_list_permission');
            } catch (Exception) {
                // index does not exist
            }
        }
    }
}
