<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Domain\Model;

use InvalidArgumentException;
use LogicException;

/**
 * Sort order requested through the `sort` query parameter of an API list endpoint.
 *
 * Format: comma-separated fields, each optionally suffixed with `:asc` or `:desc`
 * (default ascending), e.g. `type,ttl:desc`. Only fields on the endpoint's
 * whitelist are accepted, so a field name never reaches SQL unmapped.
 */
final class ListSort
{
    /** Upper bound on sort keys, to keep ORDER BY clauses sane. */
    private const MAX_FIELDS = 5;

    /**
     * @param list<array{field: string, desc: bool}> $fields
     */
    private function __construct(private readonly array $fields)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Parse a `sort` query value against the endpoint's allowed fields.
     *
     * @param string[] $allowedFields
     * @throws InvalidArgumentException When the value names an unknown field or direction
     */
    public static function fromQuery(?string $raw, array $allowedFields): self
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return self::none();
        }

        $parts = explode(',', $raw);
        if (count($parts) > self::MAX_FIELDS) {
            throw new InvalidArgumentException(sprintf('At most %d sort fields are allowed', self::MAX_FIELDS));
        }

        $fields = [];
        $seen = [];
        foreach ($parts as $part) {
            [$field, $direction] = array_pad(explode(':', trim($part), 2), 2, 'asc');
            $field = strtolower(trim($field));
            $direction = strtolower(trim($direction));

            if (!in_array($field, $allowedFields, true)) {
                throw new InvalidArgumentException(sprintf(
                    "Invalid sort field '%s'. Allowed fields: %s",
                    $field,
                    implode(', ', $allowedFields)
                ));
            }
            if ($direction !== 'asc' && $direction !== 'desc') {
                throw new InvalidArgumentException(sprintf(
                    "Invalid sort direction '%s' for field '%s'. Use asc or desc",
                    $direction,
                    $field
                ));
            }
            if (isset($seen[$field])) {
                throw new InvalidArgumentException(sprintf("Sort field '%s' is given more than once", $field));
            }

            $seen[$field] = true;
            $fields[] = ['field' => $field, 'desc' => $direction === 'desc'];
        }

        return new self($fields);
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * @return list<array{field: string, desc: bool}>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Build an ORDER BY expression (without the keyword).
     *
     * The fallback field is appended as a tiebreaker (unless already requested) so
     * paging stays stable when the requested keys contain duplicates.
     *
     * @param array<string, string> $columnMap API field => SQL column (trusted, never user input)
     * @param string $fallbackField API field used as tiebreaker and default order; must be in $columnMap
     */
    public function toOrderBy(array $columnMap, string $fallbackField): string
    {
        $fields = $this->fields;
        if (!in_array($fallbackField, array_column($fields, 'field'), true)) {
            $fields[] = ['field' => $fallbackField, 'desc' => false];
        }

        $clauses = [];
        foreach ($fields as $sortField) {
            if (!isset($columnMap[$sortField['field']])) {
                throw new LogicException(sprintf("No column mapped for sort field '%s'", $sortField['field']));
            }
            $clauses[] = $columnMap[$sortField['field']] . ($sortField['desc'] ? ' DESC' : ' ASC');
        }

        return implode(', ', $clauses);
    }

    /**
     * Sort already-loaded rows (for endpoints that filter in PHP rather than SQL).
     *
     * Strings compare case-insensitively in natural order, numbers numerically, and
     * nulls sort first. Rows that compare equal keep their original order.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function sortRows(array $rows): array
    {
        $rows = array_values($rows);
        if ($this->fields === []) {
            return $rows;
        }

        $indexed = [];
        foreach ($rows as $index => $row) {
            $indexed[] = [$index, $row];
        }

        usort($indexed, function (array $a, array $b): int {
            foreach ($this->fields as $sortField) {
                $result = self::compareValues($a[1][$sortField['field']] ?? null, $b[1][$sortField['field']] ?? null);
                if ($result !== 0) {
                    return $sortField['desc'] ? -$result : $result;
                }
            }
            return $a[0] <=> $b[0];
        });

        return array_map(static fn(array $entry) => $entry[1], $indexed);
    }

    private static function compareValues(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 0 : 1) <=> ($b === null ? 0 : 1);
        }
        if (is_numeric($a) && is_numeric($b)) {
            return $a <=> $b;
        }
        return strnatcasecmp((string)$a, (string)$b);
    }
}
