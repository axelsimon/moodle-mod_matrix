<?php

declare(strict_types=1);

/**
 * @package   mod_matrix
 * @copyright 2020, New Vector Ltd (Trading as Element)
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace mod_matrix\Matrix\Application;

use Ergebnis\Clock;
use mod_matrix\Matrix;
use mod_matrix\Moodle;

final class MatrixService
{
    private $api;
    private $configuration;

    /**
     * @deprecated
     */
    private $courseRepository;

    /**
     * @deprecated
     */
    private $groupRepository;

    /**
     * @deprecated
     */
    private $moduleRepository;

    /**
     * @deprecated
     */
    private $roomRepository;

    /**
     * @deprecated
     */
    private $userRepository;
    private $clock;

    public function __construct(
        Matrix\Application\Api $api,
        Matrix\Application\Configuration $configuration,
        Moodle\Domain\CourseRepository $courseRepository,
        Moodle\Domain\GroupRepository $groupRepository,
        Moodle\Domain\ModuleRepository $moduleRepository,
        Moodle\Domain\RoomRepository $roomRepository,
        Moodle\Domain\UserRepository $userRepository,
        Clock\Clock $clock
    ) {
        $this->api = $api;
        $this->configuration = $configuration;
        $this->courseRepository = $courseRepository;
        $this->groupRepository = $groupRepository;
        $this->moduleRepository = $moduleRepository;
        $this->roomRepository = $roomRepository;
        $this->userRepository = $userRepository;
        $this->clock = $clock;
    }

    public function urlForRoom(Matrix\Domain\RoomId $roomId): string
    {
        if ('' !== $this->configuration->elementUrl()) {
            return \sprintf(
                '%s/#/room/%s',
                $this->configuration->elementUrl(),
                $roomId->toString(),
            );
        }

        return \sprintf(
            'https://matrix.to/#/%s',
            $roomId->toString(),
        );
    }

    public function prepareRoomsForAllModulesOfCourseAndGroup(
        Moodle\Domain\CourseId $courseId,
        Moodle\Domain\GroupId $groupId
    ): void {
        global $CFG;

        $modules = $this->moduleRepository->findAllBy([
            'course' => $courseId->toInt(),
        ]);

        foreach ($modules as $module) {
            $this->prepareRoomForModuleAndGroup(
                Matrix\Domain\RoomTopic::fromString(\sprintf(
                    '%s/course/view.php?id=%d',
                    $CFG->wwwroot,
                    $module->courseId()->toInt(),
                )),
                $module,
                $groupId,
            );
        }
    }

    /**
     * @throws \RuntimeException
     */
    public function prepareRoomForModuleAndGroup(
        Matrix\Domain\RoomTopic $topic,
        Moodle\Domain\Module $module,
        ?Moodle\Domain\GroupId $groupId
    ): void {
        $course = $this->courseRepository->find($module->courseId());

        if (!$course instanceof Moodle\Domain\Course) {
            throw new \RuntimeException(\sprintf(
                'Could not find course with id %d.',
                $module->courseId()->toInt(),
            ));
        }

        $whoami = $this->api->whoAmI();

        $botPowerLevel = Matrix\Domain\PowerLevel::bot();
        $staffPowerLevel = Matrix\Domain\PowerLevel::staff();
        $redactorPowerLevel = Matrix\Domain\PowerLevel::redactor();

        $roomOptions = [
            'creation_content' => [
                'org.matrix.moodle.course_id' => $module->courseId()->toInt(),
            ],
            'initial_state' => [
                [
                    'content' => [
                        'guest_access' => 'forbidden',
                    ],
                    'state_key' => '',
                    'type' => 'm.room.guest_access',
                ],
            ],
            'name' => \sprintf(
                '%s (%s)',
                $course->name()->toString(),
                $module->name()->toString(),
            ),
            'power_level_content_override' => [
                'ban' => $botPowerLevel->toInt(),
                'invite' => $botPowerLevel->toInt(),
                'kick' => $botPowerLevel->toInt(),
                'events' => [
                    'm.room.name' => $botPowerLevel->toInt(),
                    'm.room.power_levels' => $botPowerLevel->toInt(),
                    'm.room.history_visibility' => $staffPowerLevel->toInt(),
                    'm.room.canonical_alias' => $staffPowerLevel->toInt(),
                    'm.room.avatar' => $staffPowerLevel->toInt(),
                    'm.room.tombstone' => $botPowerLevel->toInt(),
                    'm.room.server_acl' => $botPowerLevel->toInt(),
                    'm.room.encryption' => $botPowerLevel->toInt(),
                    'm.room.join_rules' => $botPowerLevel->toInt(),
                    'm.room.guest_access' => $botPowerLevel->toInt(),
                ],
                'events_default' => 0,
                'state_default' => $staffPowerLevel->toInt(),
                'redact' => $redactorPowerLevel->toInt(),
                'users' => [
                    $whoami->toString() => $botPowerLevel->toInt(),
                ],
            ],
            'preset' => 'private_chat',
            'topic' => $topic->toString(),
        ];

        if ($groupId instanceof Moodle\Domain\GroupId) {
            $group = $this->groupRepository->find($groupId);

            if (!$group instanceof Moodle\Domain\Group) {
                throw new \RuntimeException(\sprintf(
                    'Could not find group with id %d.',
                    $groupId->toInt(),
                ));
            }

            $roomForModuleAndGroup = $this->roomRepository->findOneBy([
                'module_id' => $module->id()->toInt(),
                'group_id' => $groupId->toInt(),
            ]);

            if (!$roomForModuleAndGroup instanceof Moodle\Domain\Room) {
                $roomOptions['name'] = \sprintf(
                    '%s: %s (%s)',
                    $group->name()->toString(),
                    $course->name()->toString(),
                    $module->name()->toString(),
                );
                $roomOptions['creation_content']['org.matrix.moodle.group_id'] = $groupId->toInt();

                $matrixRoomId = $this->api->createRoom($roomOptions);

                $roomForModuleAndGroup = Moodle\Domain\Room::create(
                    Moodle\Domain\RoomId::unknown(),
                    $module->id(),
                    $groupId,
                    $matrixRoomId,
                    Moodle\Domain\Timestamp::fromInt($this->clock->now()->getTimestamp()),
                    Moodle\Domain\Timestamp::fromInt(0),
                );

                $this->roomRepository->save($roomForModuleAndGroup);
            }

            $users = $this->userRepository->findAllUsersEnrolledInCourseAndGroupWithMatrixUserId(
                $module->courseId(),
                $groupId,
            );

            $staff = $this->userRepository->findAllStaffInCourseWithMatrixUserId($module->courseId());

            $this->synchronizeRoomMembersForRoom(
                $roomForModuleAndGroup->matrixRoomId(),
                Matrix\Domain\UserIdCollection::fromUserIds(...\array_map(static function (Moodle\Domain\User $user): Matrix\Domain\UserId {
                    return $user->matrixUserId();
                }, $users)),
                Matrix\Domain\UserIdCollection::fromUserIds(...\array_map(static function (Moodle\Domain\User $user): Matrix\Domain\UserId {
                    return $user->matrixUserId();
                }, $staff)),
            );

            return;
        }

        $roomForModule = $this->roomRepository->findOneBy([
            'module_id' => $module->id()->toInt(),
            'group_id' => null,
        ]);

        if (!$roomForModule instanceof Moodle\Domain\Room) {
            $matrixRoomId = $this->api->createRoom($roomOptions);

            $roomForModule = Moodle\Domain\Room::create(
                Moodle\Domain\RoomId::unknown(),
                $module->id(),
                null,
                $matrixRoomId,
                Moodle\Domain\Timestamp::fromInt($this->clock->now()->getTimestamp()),
                Moodle\Domain\Timestamp::fromInt(0),
            );

            $this->roomRepository->save($roomForModule);
        }

        $users = $this->userRepository->findAllUsersEnrolledInCourseAndGroupWithMatrixUserId(
            $module->courseId(),
            Moodle\Domain\GroupId::fromInt(0),
        );

        $staff = $this->userRepository->findAllStaffInCourseWithMatrixUserId($module->courseId());

        $this->synchronizeRoomMembersForRoom(
            $roomForModule->matrixRoomId(),
            Matrix\Domain\UserIdCollection::fromUserIds(...\array_map(static function (Moodle\Domain\User $user): Matrix\Domain\UserId {
                return $user->matrixUserId();
            }, $users)),
            Matrix\Domain\UserIdCollection::fromUserIds(...\array_map(static function (Moodle\Domain\User $user): Matrix\Domain\UserId {
                return $user->matrixUserId();
            }, $staff)),
        );
    }

    /**
     * @throws \RuntimeException
     */
    public function synchronizeRoomMembersForAllRooms(): void
    {
        $rooms = $this->roomRepository->findAll();

        foreach ($rooms as $room) {
            $module = $this->moduleRepository->findOneBy([
                'id' => $room->moduleId()->toInt(),
            ]);

            if (!$module instanceof Moodle\Domain\Module) {
                throw new \RuntimeException(\sprintf(
                    'Module with id "%d" was not found.',
                    $room->moduleId()->toInt(),
                ));
            }

            $groupId = $room->groupId();

            if (!$groupId instanceof Moodle\Domain\GroupId) {
                $groupId = Moodle\Domain\GroupId::fromInt(0);
            } // Moodle wants zero instead of null

            $users = $this->userRepository->findAllUsersEnrolledInCourseAndGroupWithMatrixUserId(
                $module->courseId(),
                $groupId,
            );

            $userIdsOfUsers = Matrix\Domain\UserIdCollection::fromUserIds(...\array_map(static function (Moodle\Domain\User $user): Matrix\Domain\UserId {
                return $user->matrixUserId();
            }, $users));

            $staff = $this->userRepository->findAllStaffInCourseWithMatrixUserId($module->courseId());

            $userIdsOfStaff = Matrix\Domain\UserIdCollection::fromUserIds(...\array_map(static function (Moodle\Domain\User $user): Matrix\Domain\UserId {
                return $user->matrixUserId();
            }, $staff));

            $this->synchronizeRoomMembersForRoom(
                $room->matrixRoomId(),
                $userIdsOfUsers,
                $userIdsOfStaff,
            );
        }
    }

    public function synchronizeRoomMembersForAllRoomsOfAllModulesInCourse(Moodle\Domain\CourseId $courseId): void
    {
        $modules = $this->moduleRepository->findAllBy([
            'course' => $courseId->toInt(),
        ]);

        $staff = $this->userRepository->findAllStaffInCourseWithMatrixUserId($courseId);

        $userIdsOfStaff = Matrix\Domain\UserIdCollection::fromUserIds(...\array_map(static function (Moodle\Domain\User $user): Matrix\Domain\UserId {
            return $user->matrixUserId();
        }, $staff));

        foreach ($modules as $module) {
            $rooms = $this->roomRepository->findAllBy([
                'module_id' => $module->id()->toInt(),
            ]);

            foreach ($rooms as $room) {
                $groupId = $room->groupId();

                if (!$groupId instanceof Moodle\Domain\GroupId) {
                    $groupId = Moodle\Domain\GroupId::fromInt(0);
                } // Moodle wants zero instead of null

                $users = $this->userRepository->findAllUsersEnrolledInCourseAndGroupWithMatrixUserId(
                    $courseId,
                    $groupId,
                );

                $userIdsOfUsers = Matrix\Domain\UserIdCollection::fromUserIds(...\array_map(static function (Moodle\Domain\User $user): Matrix\Domain\UserId {
                    return $user->matrixUserId();
                }, $users));

                $this->synchronizeRoomMembersForRoom(
                    $room->matrixRoomId(),
                    $userIdsOfUsers,
                    $userIdsOfStaff,
                );
            }
        }
    }

    public function synchronizeRoomMembersForAllRoomsOfAllModulesInCourseAndGroup(
        Moodle\Domain\CourseId $courseId,
        Moodle\Domain\GroupId $groupId
    ): void {
        $modules = $this->moduleRepository->findAllBy([
            'course' => $courseId->toInt(),
        ]);

        $users = $this->userRepository->findAllUsersEnrolledInCourseAndGroupWithMatrixUserId(
            $courseId,
            $groupId,
        );

        $userIdsOfUsers = Matrix\Domain\UserIdCollection::fromUserIds(...\array_map(static function (Moodle\Domain\User $user): Matrix\Domain\UserId {
            return $user->matrixUserId();
        }, $users));

        $staff = $this->userRepository->findAllStaffInCourseWithMatrixUserId($courseId);

        $userIdsOfStaff = Matrix\Domain\UserIdCollection::fromUserIds(...\array_map(static function (Moodle\Domain\User $user): Matrix\Domain\UserId {
            return $user->matrixUserId();
        }, $staff));

        foreach ($modules as $module) {
            $rooms = $this->roomRepository->findAllBy([
                'group_id' => $groupId->toInt(),
                'module_id' => $module->id()->toInt(),
            ]);

            foreach ($rooms as $room) {
                $this->synchronizeRoomMembersForRoom(
                    $room->matrixRoomId(),
                    $userIdsOfUsers,
                    $userIdsOfStaff,
                );
            }
        }
    }

    public function synchronizeRoomMembersForRoom(
        Matrix\Domain\RoomId $roomId,
        Matrix\Domain\UserIdCollection $userIdsOfUsers,
        Matrix\Domain\UserIdCollection $userIdsOfStaff
    ): void {
        $userIdsOfUsersInRoom = $this->api->listUsers($roomId);

        $userIdsOfUsersNotYetInRoom = \array_filter($userIdsOfUsers->toArray(), static function (Matrix\Domain\UserId $userId) use ($userIdsOfUsersInRoom): bool {
            return !\in_array(
                $userId,
                $userIdsOfUsersInRoom,
                false,
            );
        });

        $api = $this->api;

        \array_walk($userIdsOfUsersNotYetInRoom, static function (Matrix\Domain\UserId $userId) use ($roomId): void {
            $this->api->inviteUser(
                $roomId,
                $userId,
            );
        });

        $userIdsOfStaffNotYetInRoom = \array_filter($userIdsOfStaff->toArray(), static function (Matrix\Domain\UserId $userId) use ($userIdsOfUsersInRoom) {
            return !\in_array(
                $userId,
                $userIdsOfUsersInRoom,
                false,
            );
        });

        \array_walk($userIdsOfStaffNotYetInRoom, static function (Matrix\Domain\UserId $userId) use ($api, $roomId): void {
            $api->inviteUser(
                $roomId,
                $userId,
            );
        });

        $matrixUserIdOfBot = $this->api->whoAmI();

        $powerLevels = $this->api->getState(
            $roomId,
            Matrix\Domain\EventType::fromString('m.room.power_levels'),
            Matrix\Domain\StateKey::fromString(''),
        );

        $powerLevels['users'] = \array_merge(
            [
                $matrixUserIdOfBot->toString() => Matrix\Domain\PowerLevel::bot()->toInt(),
            ],
            \array_combine(
                \array_map(static function (Matrix\Domain\UserId $userId): string {
                    return $userId->toString();
                }, $userIdsOfUsers->toArray()),
                \array_fill(
                    0,
                    \count($userIdsOfUsers->toArray()),
                    Matrix\Domain\PowerLevel::default()->toInt(),
                ),
            ),
            \array_combine(
                \array_map(static function (Matrix\Domain\UserId $userId): string {
                    return $userId->toString();
                }, $userIdsOfStaff->toArray()),
                \array_fill(
                    0,
                    \count($userIdsOfStaff->toArray()),
                    Matrix\Domain\PowerLevel::staff()->toInt(),
                ),
            ),
        );

        $this->api->setState(
            $roomId,
            Matrix\Domain\EventType::fromString('m.room.power_levels'),
            Matrix\Domain\StateKey::fromString(''),
            $powerLevels,
        );

        $userIdsOfUsersAllowedInRoom = \array_merge(
            [
                $matrixUserIdOfBot,
            ],
            $userIdsOfUsers->toArray(),
            $userIdsOfStaff->toArray(),
        );

        $userIdsOfUsersNotAllowedInRoom = \array_filter($userIdsOfUsersInRoom, static function (Matrix\Domain\UserId $userId) use ($userIdsOfUsersAllowedInRoom): bool {
            return !\in_array(
                $userId,
                $userIdsOfUsersAllowedInRoom,
                false,
            );
        });

        \array_walk($userIdsOfUsersNotAllowedInRoom, static function (Matrix\Domain\UserId $userId) use ($api, $roomId): void {
            $api->kickUser(
                $roomId,
                $userId,
            );
        });
    }

    public function removeRoom(Moodle\Domain\Room $room): void
    {
        $userIdsOfUsersInRoom = $this->api->listUsers($room->matrixRoomId());

        $userIdOfBot = $this->api->whoAmI();

        foreach ($userIdsOfUsersInRoom as $userId) {
            if ($userId->equals($userIdOfBot)) {
                continue;
            }

            $this->api->kickUser(
                $room->matrixRoomId(),
                $userId,
            );
        }

        $this->api->kickUser(
            $room->matrixRoomId(),
            $userIdOfBot,
        );
    }
}
