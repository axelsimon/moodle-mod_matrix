<?php

declare(strict_types=1);

/**
 * @package   mod_matrix
 * @copyright 2020, New Vector Ltd (Trading as Element)
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace mod_matrix\Test\Unit\Moodle\Application;

use mod_matrix\Matrix;
use mod_matrix\Moodle;
use mod_matrix\Test;
use PHPUnit\Framework;

/**
 * @internal
 *
 * @covers \mod_matrix\Moodle\Application\NameService
 *
 * @uses \mod_matrix\Moodle\Domain\CourseShortName
 * @uses \mod_matrix\Moodle\Domain\GroupName
 * @uses \mod_matrix\Moodle\Domain\ModuleName
 */
final class NameServiceTest extends Framework\TestCase
{
    use Test\Util\Helper;

    public function testCreateForGroupCourseAndModuleReturnsName(): void
    {
        $faker = self::faker();

        $groupName = Moodle\Domain\GroupName::fromString($faker->word());
        $courseShortName = Moodle\Domain\CourseShortName::fromString($faker->word());
        $moduleName = Moodle\Domain\ModuleName::fromString($faker->sentence());

        $nameService = new Moodle\Application\NameService();

        $name = $nameService->createForGroupCourseAndModule(
            $groupName,
            $courseShortName,
            $moduleName,
        );

        $expected = Matrix\Domain\RoomName::fromString(\sprintf(
            '%s: %s (%s)',
            $groupName->toString(),
            $courseShortName->toString(),
            $moduleName->toString(),
        ));

        self::assertEquals($expected, $name);
    }

    public function testCreateForCourseAndModuleReturnsName(): void
    {
        $faker = self::faker();

        $courseShortName = Moodle\Domain\CourseShortName::fromString($faker->word());
        $moduleName = Moodle\Domain\ModuleName::fromString($faker->sentence());

        $nameService = new Moodle\Application\NameService();

        $name = $nameService->createForCourseAndModule(
            $courseShortName,
            $moduleName,
        );

        $expected = Matrix\Domain\RoomName::fromString(\sprintf(
            '%s (%s)',
            $courseShortName->toString(),
            $moduleName->toString(),
        ));

        self::assertEquals($expected, $name);
    }
}
