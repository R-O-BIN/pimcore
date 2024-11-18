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

namespace Pimcore\Helper;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Pimcore\Cache;
use Pimcore\Db;
use Pimcore\Model\UserInterface;
use Pimcore\Tool\Admin;
use Pimcore\ValueObject\Sql\Condition;

/**
 * @internal
 */
final class WorkspaceConditionHelper
{
    private const MAX_CHAR_INDEX = 26;

    private const FORBIDDEN = 'forbidden';

    private const ALLOWED = 'allowed';

    private const TABLE_COLUMN = 'CONCAT({prefix}`path`, {prefix}`key`)';

    /**
     * @throws Exception
     */
    public function getConditionForCurrentUser(string $type, string $tablePrefix = ''): Condition
    {
        $user = Admin::getCurrentUser();
        if (!$user instanceof UserInterface) {
            // think about this
        }
        $paths = $this->findWorkspacePaths($type, $user);

        if (empty($paths[self::ALLOWED])) {
            return $this->createConditionObject($this->getForbiddenPathsSql($paths, $tablePrefix));
        }

        return $this->createConditionObject($this->getAllowedPathsWithForbiddenPathsSql($paths, $tablePrefix));
    }

    public function getConditionForConfigWorkspaces(array $workspaces): Condition
    {
        $paths = $this->mapConfigWorkspacePaths($workspaces);

        if (empty($paths[self::ALLOWED])) {
            return $this->createConditionObject($this->getForbiddenPathsSql($paths));
        }

        return $this->createConditionObject($this->getAllowedPathsWithForbiddenPathsSql($paths));
    }

    public function getConditionForUser(UserInterface $user, string $type, string $tablePrefix = ''): Condition
    {
        $paths = $this->findWorkspacePaths($type, $user);

        if (empty($paths[self::ALLOWED])) {
            return $this->createConditionObject($this->getForbiddenPathsSql($paths, $tablePrefix));
        }

        return $this->createConditionObject($this->getAllowedPathsWithForbiddenPathsSql($paths, $tablePrefix));
    }

    public function getAllowedElementsForUser(
        UserInterface $user,
        string $table,
        string $type,
        string $tablePrefix = 'o'
    ): array {
        $cacheKey = 'workspace_condition_' . $table . '_' . $user->getId();
        $cache = Cache::load($cacheKey);

        if ($cache !== false) {
            return $cache;
        }

        $workspaceCondition = $this->getConditionForUser($user, $type, $tablePrefix);

        $queryBuilder = Db::getConnection()->createQueryBuilder();
        $queryBuilder
            ->distinct()
            ->select(
                'o.id as id'
            )
            ->from($table, 'o')
            ->andWhere(
                $workspaceCondition->getQuery()
            )
            ->setParameters($workspaceCondition->getParams());

        $result = $queryBuilder->executeQuery()->fetchAllAssociative();
        $result = array_column($result, 'id');

        if (empty($result)) {
            // set to -1 since we use it in an in condition
            $result = [-1];
        }
        Cache::save($result, $cacheKey, ['output', 'user-' . $user->getId()]);

        return $result;
    }

    private function mapConfigWorkspacePaths(array $workspaces): array
    {
        $paths = [self::ALLOWED => [], self::FORBIDDEN => []];

        foreach ($workspaces as $workspace) {
            $include = isset($workspace['include']) && $workspace['include'];
            $allowedOrForbidden = $include ? self::ALLOWED : self::FORBIDDEN;
            $paths[$allowedOrForbidden][] = $workspace['path'];
        }

        return $paths;
    }

    private function getAllowedPathsWithForbiddenPathsSql(array $paths, string $tablePrefix = ''): array
    {
        $conditions =  [];
        $params = [];

        $paths = $this->mapAllowedPathsWithForbiddenPaths($paths);
        $key = 0;
        foreach ($paths as $allowedPath => $forbiddenPaths) {

            $paramKey = $this->key($key);
            // prepare sql statement for every single path
            $condition = $this->buildQueryCondition($tablePrefix, 'LIKE', $paramKey);

            $params[$paramKey] = $allowedPath. '%';

            // if forbidden paths, wrap them as exceptions to the allowed path
            if (!empty($forbiddenPaths)) {
                $forbiddenPathConditions = $this->prepareForbiddenPaths(
                    $forbiddenPaths,
                    $paramKey,
                    $params,
                    $tablePrefix
                );
                $condition .= sprintf(' AND (%s)', $forbiddenPathConditions['query']);
                $params = $forbiddenPathConditions['params'];
            }
            $conditions[] = $condition;
            $key++;
        }

        return ['query' => implode(' OR ', $conditions), 'params' => $params];
    }

    private function getForbiddenPathsSql(array $paths, string $tablePrefix = ''): array
    {
        $conditions =  [];
        $params = [];
        // if nothing is allowed also add root
        $paths[self::FORBIDDEN][] = '/';
        foreach ($paths[self::FORBIDDEN] as $key => $forbiddenPath) {
            $paramKey = $this->key($key);
            $conditions[] = $this->buildQueryCondition($tablePrefix, 'NOT LIKE', $paramKey);
            $params[$paramKey] = $forbiddenPath. '%';
        }

        return ['query' => implode(' AND ', $conditions), 'params' => $params];
    }

    private function prepareForbiddenPaths(
        array $forbiddenPaths,
        string $prefix,
        array $params,
        string $tablePrefix
    ): array {
        $conditions =  [];
        foreach ($forbiddenPaths as $key => $forbiddenPath) {
            $paramKey = $prefix . '_' . $this->key($key);
            $conditions[] = $this->buildQueryCondition($tablePrefix, 'NOT LIKE', $paramKey);
            $params[$paramKey] = $forbiddenPath. '%';
        }

        return ['query' => implode(' AND ', $conditions), 'params' => $params];
    }

    private function mapAllowedPathsWithForbiddenPaths(array $paths): array
    {
        $allowedPaths = [];
        foreach ($paths[self::ALLOWED] as $allowedPath) {
            $allowedPaths[$allowedPath] =
                array_values(
                    array_filter(
                        $paths[self::FORBIDDEN],
                        static function ($forbiddenPath) use ($allowedPath) {
                            return str_contains($forbiddenPath, $allowedPath);
                        }
                    )
                );
        }

        return $allowedPaths;
    }

    /**
     * @throws Exception
     */
    private function findWorkspacePaths(string $type, UserInterface $user): array
    {
        if ($user->isAdmin()) {
            return [self::ALLOWED => ['/'], self::FORBIDDEN => []];
        }

        $paths = [self::ALLOWED => [], self::FORBIDDEN => []];

        $result = $this->getUserWorkspaces($type, $user);
        while ($row = $result->fetchAssociative()) {
            $allowed = $row['list'] === 1 ? self::ALLOWED : self::FORBIDDEN;
            $paths[$allowed][] = $row['cpath'];
        }

        $result = $this->getRolesWorkspaces($type, $user);
        while ($row = $result->fetchAssociative()) {
            // only insert into forbidden if it is not in already allowed by the user
            if (($row['list'] === 0) && in_array($row['cpath'], $paths[self::ALLOWED], true)) {
                continue;
            }
            $allowed = $row['list'] === 1 ? self::ALLOWED : self::FORBIDDEN;
            $paths[$allowed][] = $row['cpath'];
        }

        return $paths;
    }

    /**
     * @throws Exception
     */
    private function getUserWorkspaces(string $type, UserInterface $user): Result
    {
        $builder = $this->getQueryBuilder();

        $builder
            ->select('cpath, list')
            ->from('users_workspaces_' . $type)
            ->where('userId = :userId')
            ->setParameter('userId', $user->getId(), ParameterType::INTEGER);

        return $builder->executeQuery();
    }

    /**
     * @throws Exception
     */
    private function getRolesWorkspaces(string $type, UserInterface $user): Result
    {
        $builder = $this->getQueryBuilder();

        $builder
            ->select('cpath, list')
            ->from('users_workspaces_' . $type)
            ->where('userId IN(:roleIds)')
            ->setParameter('roleIds', $user->getRoles(), ArrayParameterType::INTEGER);

        return $builder->executeQuery();
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return Db::getConnection()->createQueryBuilder();
    }

    private function getQueryColumn(string $tablePrefix = ''): string
    {
        if ($tablePrefix !== '') {
            $tablePrefix.= '.';
        }

        return str_replace('{prefix}', $tablePrefix, self::TABLE_COLUMN);
    }

    private function buildQueryCondition(string $tablePrefix, string $condition, string $paramKey): string
    {
        return "{$this->getQueryColumn($tablePrefix)} $condition :$paramKey";
    }

    private function createConditionObject(array $condition): Condition
    {
        return new Condition($condition['query'], $condition['params']);
    }

    private function key(int $index): string
    {
        // + 97 to start with a lower case a
        $add = ord('a');

        if ($index >= self::MAX_CHAR_INDEX) {
            $add = ord('A') - self::MAX_CHAR_INDEX;
        }

        return chr($index + $add);
    }
}
