<?php

declare(strict_types=1);

/**
 * @package   mod_matrix
 * @copyright 2020, New Vector Ltd (Trading as Element)
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace mod_matrix\Moodle\Infrastructure;

use mod_matrix\Matrix;
use mod_matrix\Moodle;

final class View
{
    private $moodleRoomRepository;
    private $moodleGroupRepository;
    private $moodleMatrixUserIdLoader;
    private $moodleRoomService;
    private $moodleNameService;
    private $moodleConfiguration;
    private $page;
    private $renderer;

    public function __construct(
        Moodle\Domain\RoomRepository $moodleRoomRepository,
        Moodle\Domain\GroupRepository $moodleGroupRepository,
        Moodle\Domain\MatrixUserIdLoader $moodleMatrixUserIdLoader,
        Moodle\Application\RoomService $moodleRoomService,
        Moodle\Application\NameService $moodleNameService,
        Moodle\Application\Configuration $moodleConfiguration,
        \moodle_page $page,
        \core_renderer $renderer
    ) {
        $this->moodleRoomRepository = $moodleRoomRepository;
        $this->moodleGroupRepository = $moodleGroupRepository;
        $this->moodleMatrixUserIdLoader = $moodleMatrixUserIdLoader;
        $this->moodleRoomService = $moodleRoomService;
        $this->moodleNameService = $moodleNameService;
        $this->moodleConfiguration = $moodleConfiguration;
        $this->page = $page;
        $this->renderer = $renderer;
    }

    public function render(
        Moodle\Domain\Module $module,
        \cm_info $cm,
        \stdClass $user
    ): void {
        $matrixUserId = $this->moodleMatrixUserIdLoader->load($user);

        if (!$matrixUserId instanceof Matrix\Domain\UserId) {
            $this->editMatrixUserIdFormAction()->handle($user);

            return;
        }

        $this->listRoomsAction()->handle(
            $user,
            $module,
            $cm,
        );
    }

    private function editMatrixUserIdFormAction(): Moodle\Infrastructure\Action\EditMatrixUserIdAction
    {
        return new Moodle\Infrastructure\Action\EditMatrixUserIdAction(
            $this->page,
            $this->renderer,
            $this->moodleConfiguration,
        );
    }

    private function listRoomsAction(): Moodle\Infrastructure\Action\ListRoomsAction
    {
        return new Moodle\Infrastructure\Action\ListRoomsAction(
            $this->moodleRoomRepository,
            $this->moodleGroupRepository,
            $this->moodleMatrixUserIdLoader,
            $this->moodleRoomService,
            $this->moodleNameService,
            $this->renderer,
        );
    }
}
