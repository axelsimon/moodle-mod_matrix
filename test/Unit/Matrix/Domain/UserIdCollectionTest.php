<?php

declare(strict_types=1);

/**
 * @package   mod_matrix
 * @copyright 2020, New Vector Ltd (Trading as Element)
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace mod_matrix\Test\Unit\Matrix\Domain;

use mod_matrix\Matrix;
use mod_matrix\Test;
use PHPUnit\Framework;

/**
 * @internal
 *
 * @covers \mod_matrix\Matrix\Domain\UserIdCollection
 *
 * @uses \mod_matrix\Matrix\Domain\UserId
 */
final class UserIdCollectionTest extends Framework\TestCase
{
    use Test\Util\Helper;

    public function testFromUsersIdsReturnsUserIdCollection(): void
    {
        $faker = self::faker();

        $userIds = [
            Matrix\Domain\UserId::fromString($faker->sha1()),
            Matrix\Domain\UserId::fromString($faker->sha1()),
            Matrix\Domain\UserId::fromString($faker->sha1()),
        ];

        $collection = Matrix\Domain\UserIdCollection::fromUserIds(...$userIds);

        self::assertSame($userIds, $collection->toArray());
    }

    public function testDiffReturnsUserIdCollection(): void
    {
        $faker = self::faker();

        $userIdOne = Matrix\Domain\UserId::fromString($faker->sha1());
        $userIdTwo = Matrix\Domain\UserId::fromString($faker->sha1());
        $userIdThree = Matrix\Domain\UserId::fromString($faker->sha1());

        $userIdsOne = [
            $userIdOne,
            $userIdTwo,
            $userIdThree,
        ];

        $userIdFour = Matrix\Domain\UserId::fromString($faker->sha1());
        $userIdFive = Matrix\Domain\UserId::fromString($faker->sha1());
        $userIdSix = Matrix\Domain\UserId::fromString($faker->sha1());

        $userIdsTwo = [
            $userIdTwo,
            $userIdFour,
            $userIdFive,
            $userIdSix,
        ];

        $one = Matrix\Domain\UserIdCollection::fromUserIds(...$userIdsOne);
        $two = Matrix\Domain\UserIdCollection::fromUserIds(...$userIdsTwo);

        $diff = $one->diff($two);

        $expected = [
            $userIdOne,
            $userIdThree,
        ];

        self::assertSame($expected, $diff->toArray());

        self::assertNotSame($one, $diff);
        self::assertNotSame($two, $diff);

        self::assertSame($userIdsOne, $one->toArray());
        self::assertSame($userIdsTwo, $two->toArray());
    }

    public function testFilterReturnsUserIdCollection(): void
    {
        $faker = self::faker();

        $userIdOne = Matrix\Domain\UserId::fromString($faker->sha1());
        $userIdTwo = Matrix\Domain\UserId::fromString($faker->sha1());
        $userIdThree = Matrix\Domain\UserId::fromString($faker->sha1());

        $userIds = [
            $userIdOne,
            $userIdTwo,
            $userIdThree,
        ];

        $collection = Matrix\Domain\UserIdCollection::fromUserIds(...$userIds);

        $filtered = $collection->filter(static function (Matrix\Domain\UserId $userId) use ($userIdTwo): bool {
            return !$userId->equals($userIdTwo);
        });

        $expected = [
            $userIdOne,
            $userIdThree,
        ];

        self::assertSame($expected, $filtered->toArray());

        self::assertNotSame($collection, $filtered);

        self::assertSame($userIds, $collection->toArray());
    }

    public function testMergeReturnsUserIdCollection(): void
    {
        $faker = self::faker();

        $userIdsOne = [
            Matrix\Domain\UserId::fromString($faker->sha1()),
            Matrix\Domain\UserId::fromString($faker->sha1()),
            Matrix\Domain\UserId::fromString($faker->sha1()),
        ];

        $userIdsTwo = [
            Matrix\Domain\UserId::fromString($faker->sha1()),
            Matrix\Domain\UserId::fromString($faker->sha1()),
            Matrix\Domain\UserId::fromString($faker->sha1()),
        ];

        $one = Matrix\Domain\UserIdCollection::fromUserIds(...$userIdsOne);
        $two = Matrix\Domain\UserIdCollection::fromUserIds(...$userIdsTwo);

        $merged = $one->merge($two);

        $expected = \array_merge(
            $userIdsOne,
            $userIdsTwo,
        );

        self::assertSame($expected, $merged->toArray());

        self::assertNotSame($one, $merged);
        self::assertNotSame($two, $merged);

        self::assertSame($userIdsOne, $one->toArray());
        self::assertSame($userIdsTwo, $two->toArray());
    }
}
