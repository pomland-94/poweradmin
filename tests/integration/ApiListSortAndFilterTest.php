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

namespace Poweradmin\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ListSort;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * Sorting and substring filtering behind the API v2 list endpoints (#1440),
 * run against real SQL so the ORDER BY / LIKE ... ESCAPE fragments are exercised.
 */
class ApiListSortAndFilterTest extends TestCase
{
    private PDO $db;
    private DbZoneRepository $zoneRepository;
    private DbUserRepository $userRepository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $this->db->exec("CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT, master TEXT, type TEXT)");
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, zone_name TEXT, owner INTEGER)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT, email TEXT,
            description TEXT, active INTEGER, perm_templ INTEGER)");
        $this->db->exec("CREATE TABLE perm_templ (id INTEGER PRIMARY KEY, name TEXT, template_type TEXT)");

        $this->db->exec("INSERT INTO domains (id, name, master, type) VALUES
            (1, 'beta.example.com', NULL, 'MASTER'),
            (2, 'Alpha.example.com', NULL, 'SLAVE'),
            (3, 'gamma.example.org', NULL, 'MASTER'),
            (4, 'under_score.example.net', NULL, 'NATIVE')");

        $this->db->exec("INSERT INTO users (id, username, fullname, email, description, active, perm_templ) VALUES
            (1, 'zoe', 'Zoe Admin', 'zoe@example.com', '', 1, NULL),
            (2, 'adam', 'Adam Ops', 'adam@ops.example', '', 1, NULL),
            (3, 'mia', 'Mia 100% Ops', 'mia@example.com', '', 1, NULL)");

        $config = ConfigurationManager::getInstance();
        $this->zoneRepository = new DbZoneRepository($this->db, $config);
        $this->userRepository = new DbUserRepository($this->db, $config);
    }

    private function zoneNames(?string $contains, ?ListSort $sort = null): array
    {
        return array_column($this->zoneRepository->getAllZonesFiltered(null, null, null, null, null, $contains, $sort), 'name');
    }

    public function testZoneSubstringFilterIsCaseInsensitive(): void
    {
        $this->assertSame(['Alpha.example.com', 'beta.example.com'], $this->zoneNames('EXAMPLE.COM'));
        $this->assertSame(2, $this->zoneRepository->getZoneCountFiltered(null, null, null, 'example.com'));
    }

    public function testZoneSubstringFilterTreatsWildcardsLiterally(): void
    {
        $this->assertSame(['under_score.example.net'], $this->zoneNames('_'));
        $this->assertSame([], $this->zoneNames('%'));
    }

    public function testZoneSortByTypeThenNameDescending(): void
    {
        $sort = ListSort::fromQuery('type,name:desc', ['name', 'type']);

        $this->assertSame(
            ['gamma.example.org', 'beta.example.com', 'under_score.example.net', 'Alpha.example.com'],
            $this->zoneNames(null, $sort)
        );
    }

    public function testZoneSortPagesStablyWithLimit(): void
    {
        $sort = ListSort::fromQuery('type', ['name', 'type']);
        $page = $this->zoneRepository->getAllZonesFiltered(null, null, null, 0, 2, null, $sort);

        $this->assertSame(['beta.example.com', 'gamma.example.org'], array_column($page, 'name'));
    }

    public function testUserSearchAndSort(): void
    {
        $sort = ListSort::fromQuery('username:desc', ['username', 'fullname', 'email']);

        $this->assertSame(['mia', 'adam'], array_column($this->userRepository->getUsersList(0, 10, 'ops', $sort), 'username'));
        $this->assertSame(['mia'], array_column($this->userRepository->getUsersList(0, 10, '100%'), 'username'));
        $this->assertSame(2, $this->userRepository->getTotalUserCount(null, 'ops'));
    }

    public function testUserDefaultOrderIsById(): void
    {
        $this->assertSame(['zoe', 'adam', 'mia'], array_column($this->userRepository->getUsersList(0, 10), 'username'));
    }
}
