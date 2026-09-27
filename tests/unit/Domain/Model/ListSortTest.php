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

namespace Poweradmin\Tests\Unit\Domain\Model;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ListSort;

class ListSortTest extends TestCase
{
    private const ALLOWED = ['name', 'type', 'ttl'];

    public function testEmptyValueMeansNoSort(): void
    {
        $this->assertTrue(ListSort::fromQuery(null, self::ALLOWED)->isEmpty());
        $this->assertTrue(ListSort::fromQuery('  ', self::ALLOWED)->isEmpty());
    }

    public function testParsesFieldsAndDirections(): void
    {
        $sort = ListSort::fromQuery('type, TTL:DESC,name:asc', self::ALLOWED);

        $this->assertSame([
            ['field' => 'type', 'desc' => false],
            ['field' => 'ttl', 'desc' => true],
            ['field' => 'name', 'desc' => false],
        ], $sort->getFields());
    }

    public static function invalidValues(): array
    {
        return [
            'unknown field' => ['content'],
            'sql injection attempt' => ['name;DROP TABLE users'],
            'bad direction' => ['name:up'],
            'empty field' => ['name,'],
            'duplicate field' => ['name,name:desc'],
            'too many fields' => ['name,type,ttl,name,type,ttl'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        ListSort::fromQuery($value, self::ALLOWED);
    }

    public function testOrderByUsesMappedColumnsAndAppendsFallback(): void
    {
        $map = ['name' => 'd.name', 'type' => 'd.type', 'ttl' => 'd.ttl'];

        $this->assertSame('d.name ASC', ListSort::none()->toOrderBy($map, 'name'));
        $this->assertSame('d.type DESC, d.name ASC', ListSort::fromQuery('type:desc', self::ALLOWED)->toOrderBy($map, 'name'));
        $this->assertSame('d.name DESC', ListSort::fromQuery('name:desc', self::ALLOWED)->toOrderBy($map, 'name'));
    }

    public function testSortRowsIsNaturalCaseInsensitiveAndStable(): void
    {
        $rows = [
            ['name' => 'www10', 'ttl' => 300],
            ['name' => 'WWW2', 'ttl' => 3600],
            ['name' => 'mail', 'ttl' => 300],
            ['name' => 'api', 'ttl' => 3600],
        ];

        $byName = ListSort::fromQuery('name', self::ALLOWED)->sortRows($rows);
        $this->assertSame(['api', 'mail', 'WWW2', 'www10'], array_column($byName, 'name'));

        // Equal TTLs keep their original relative order.
        $byTtlDesc = ListSort::fromQuery('ttl:desc', self::ALLOWED)->sortRows($rows);
        $this->assertSame(['WWW2', 'api', 'www10', 'mail'], array_column($byTtlDesc, 'name'));
    }

    public function testSortRowsWithoutFieldsReindexesOnly(): void
    {
        $rows = [3 => ['name' => 'b'], 7 => ['name' => 'a']];

        $this->assertSame([['name' => 'b'], ['name' => 'a']], ListSort::none()->sortRows($rows));
    }
}
