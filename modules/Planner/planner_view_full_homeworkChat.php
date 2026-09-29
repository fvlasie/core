<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Domain\Planner\PlannerEntryDiscussGateway;
use Gibbon\Domain\Planner\PlannerEntryGateway;

require_once __DIR__ . '/../../gibbon.php';

include __DIR__.'/moduleFunctions.php';

$page = $container->get('page');

$gibbonPlannerEntryID = $_GET['gibbonPlannerEntryID'] ?? '';
$gibbonPersonIDStudent = $_GET['gibbonPersonIDStudent'] ?? '';

if (isActionAccessible($guid, $connection2, '/modules/Planner/planner_view_full.php') == false) {
    echo '<div class="warning">'.__('You do not have access to this action.').'</div>';
    return;
}

$highestAction = getHighestGroupedAction($guid, '/modules/Planner/planner_view_full.php', $connection2);
$planner = $container->get(PlannerEntryGateway::class)->getByID($gibbonPlannerEntryID);

if (empty($planner) || !plannerCanAccessHomeworkChat($connection2, $session, $planner, $gibbonPersonIDStudent, $highestAction, false)) {
    echo '<div class="warning">'.__('The selected record does not exist, or you do not have access to it.').'</div>';
    return;
}

try {
    $container->get(PlannerEntryDiscussGateway::class)->markHomeworkChatRead($session->get('gibbonPersonID'), $gibbonPlannerEntryID, $gibbonPersonIDStudent);
} catch (\PDOException $e) {
}

$canReply = plannerCanAccessHomeworkChat($connection2, $session, $planner, $gibbonPersonIDStudent, $highestAction, true);
$canDelete = $highestAction == 'Lesson Planner_viewEditAllClasses';
if (!$canDelete && in_array($highestAction, ['Lesson Planner_viewAllEditMyClasses', 'Lesson Planner_viewMyClasses'], true)) {
    $dataTeacher = ['gibbonCourseClassID' => $planner['gibbonCourseClassID'], 'gibbonPersonID' => $session->get('gibbonPersonID')];
    $sqlTeacher = "SELECT gibbonCourseClassPersonID FROM gibbonCourseClassPerson WHERE gibbonCourseClassID=:gibbonCourseClassID AND gibbonPersonID=:gibbonPersonID AND role LIKE '%Teacher%' AND role NOT LIKE '%Left'";
    $resultTeacher = $connection2->prepare($sqlTeacher);
    $resultTeacher->execute($dataTeacher);
    $canDelete = $resultTeacher->rowCount() > 0;
}

$urlParams = [
    'gibbonPlannerEntryID' => $gibbonPlannerEntryID,
    'viewBy' => $_GET['viewBy'] ?? 'date',
    'subView' => $_GET['subView'] ?? '',
    'gibbonCourseClassID' => $_GET['gibbonCourseClassID'] ?? $planner['gibbonCourseClassID'],
    'date' => $_GET['date'] ?? ($planner['date'] ?? ''),
    'search' => $_GET['search'] ?? '',
];

echo '<div class="flex justify-between items-center mb-2">';
echo '<span class="font-bold">'.__('Homework Chat').'</span>';
echo '<button type="button" class="tag" onclick="Alpine.$data(this.closest(\'[x-data]\')).open=\'\'">'.__('Close').'</button>';
echo '</div>';

echo renderPlannerDiscussion(
    $page,
    'plannerHomeworkChat'.$gibbonPersonIDStudent,
    __('Homework Chat'),
    'homework-chat-thread-'.$gibbonPersonIDStudent,
    $gibbonPlannerEntryID,
    $urlParams,
    $canReply,
    $canDelete,
    'Homework',
    $gibbonPersonIDStudent,
    false
);

echo '<span id="homework-chat-badge-'.htmlspecialchars((string) $gibbonPersonIDStudent, ENT_QUOTES).'" class="ml-1 tag error" style="display:none" hx-swap-oob="outerHTML"></span>';
