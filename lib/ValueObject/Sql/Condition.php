<?php
declare(strict_types=1);

/**
 * Pimcore
 *
 * This source file is available under following license:
 * - Pimcore Commercial License (PCL)
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     PCL
 */

namespace Pimcore\ValueObject\Sql;


use Pimcore\Exception\InvalidQueryException;

final class Condition
{
    public function __construct(private readonly string $query, private readonly array $params)
    {
        $this->validate();
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    private function validate(): void
    {
        if (!$this->query || empty($this->params)) {
            throw new InvalidQueryException('Query and params must not be empty.');
        }

        // if array is simple list like ['42', '73']
        if(array_is_list($this->params)) {
            $this->validatePlaceholderAndParamCount();

            return;
        }

        $this->validateQueryAndParamKeys();
    }

    private function validatePlaceholderAndParamCount(): void
    {
        $placeholderCount = substr_count($this->query, '?');

        if ($placeholderCount !== count($this->params)) {
            throw new InvalidQueryException('Placeholder count must match param count.');
        }
    }

    private function validateQueryAndParamKeys(): void
    {
        $paramKeys = array_keys($this->params);

        foreach ($paramKeys as $key) {
            if (!str_contains($this->query, (string)$key)) {
                throw new InvalidQueryException(
                    sprintf(
                        'Query must contain all param keys: %s.',
                        implode(', ', $paramKeys)
                    )
                );
            }
        }
    }
}
