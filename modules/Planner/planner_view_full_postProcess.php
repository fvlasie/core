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

use Gibbon\Data\Validator;
use Gibbon\Comms\NotificationSender;
use Gibbon\Domain\System\NotificationGateway;
use Gibbon\Services\Format;

require_once __DIR__ . '/../../gibbon.php';

$_POST = $container->get(Validator::class)->sanitize($_POST, ['comment' => 'HTML']);

//Module includes
include './moduleFunctions.php';

$gibbonPlannerEntryID = $_POST['gibbonPlannerEntryID'] ?? '';
$URL = $session->get('absoluteURL').'/index.php?q=/modules/'.getModuleName($_POST['address'])."/planner_view_full.php&gibbonPlannerEntryID=$gibbonPlannerEntryID&search=".$_POST['search'].($_POST['params'] ?? '');

if (isActionAccessible($guid, $connection2, '/modules/Planner/planner_view_full.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
} else {
    $highestAction = getHighestGroupedAction($guid, $_POST['address'], $connection2);
    if ($highestAction == false) {
        $URL .= "&return=error0$params";
        header("Location: {$URL}");
    } else {
        //Proceed!
        //Check if planner specified
        if ($gibbonPlannerEntryID == '') {
            $URL .= '&return=error1';
            header("Location: {$URL}");
        } else {
            try {
                $data = array('gibbonPlannerEntryID' => $gibbonPlannerEntryID);
                $sql = 'SELECT * FROM gibbonPlannerEntry WHERE gibbonPlannerEntryID=:gibbonPlannerEntryID';
                $result = $connection2->prepare($sql);
                $result->execute($data);
            } catch (PDOException $e) {
                $URL .= '&return=error2';
                header("Location: {$URL}");
                exit();
            }

            if ($result->rowCount() != 1) {
                $URL .= '&return=error2';
                header("Location: {$URL}");
            } else {
                $row = $result->fetch();

                $context = ($_POST['context'] ?? '') === 'Homework' ? 'Homework' : 'Lesson';
                $gibbonPersonIDStudent = $context === 'Homework' ? ($_POST['gibbonPersonIDStudent'] ?? '') : null;
                $replyTo = !empty($_POST['replyTo']) ? $_POST['replyTo'] : null;
                $comment = $_POST['comment'] ?? '';

                if ($context === 'Homework' && !plannerCanAccessHomeworkChat($connection2, $session, $row, $gibbonPersonIDStudent, $highestAction, true)) {
                    $URL .= '&return=error0';
                    header("Location: {$URL}");
                    exit();
                }

                $replyToID = null;
                if (!empty($replyTo)) {
                    $dataReply = array('gibbonPlannerEntryDiscussID' => $replyTo, 'gibbonPlannerEntryID' => $gibbonPlannerEntryID);
                    $sqlReply = 'SELECT * FROM gibbonPlannerEntryDiscuss WHERE gibbonPlannerEntryDiscussID=:gibbonPlannerEntryDiscussID AND gibbonPlannerEntryID=:gibbonPlannerEntryID';
                    $resultReply = $connection2->prepare($sqlReply);
                    $resultReply->execute($dataReply);
                    if ($resultReply->rowCount() != 1) {
                        $URL .= '&return=error1';
                        header("Location: {$URL}");
                        exit();
                    }
                    $rowReply = $resultReply->fetch();
                    if (($rowReply['context'] ?? 'Lesson') !== $context || ($context === 'Homework' && (string) $rowReply['gibbonPersonIDStudent'] !== (string) $gibbonPersonIDStudent)) {
                        $URL .= '&return=error1';
                        header("Location: {$URL}");
                        exit();
                    }
                    $replyToID = $rowReply['gibbonPersonID'];
                }

                try {
                    $dataInsert = array('gibbonPlannerEntryID' => $gibbonPlannerEntryID, 'context' => $context, 'gibbonPersonIDStudent' => $gibbonPersonIDStudent, 'gibbonPersonID' => $session->get('gibbonPersonID'), 'comment' => $comment, 'replyTo' => $replyTo);
                    $sqlInsert = 'INSERT INTO gibbonPlannerEntryDiscuss SET gibbonPlannerEntryID=:gibbonPlannerEntryID, context=:context, gibbonPersonIDStudent=:gibbonPersonIDStudent, gibbonPersonID=:gibbonPersonID, comment=:comment, gibbonPlannerEntryDiscussIDReplyTo=:replyTo';
                    $resultInsert = $connection2->prepare($sqlInsert);
                    $resultInsert->execute($dataInsert);
                } catch (PDOException $e) {
                    $URL .= '&return=error2';
                    header("Location: {$URL}");
                    exit();
                }

                $notificationGateway = $container->get(NotificationGateway::class);
                $notificationSender = $container->get(NotificationSender::class);

                $personName = Format::name('', $session->get('preferredName'), $session->get('surname'), 'Staff', false, true);
                $anchor = $context === 'Homework' ? '#homework-chat-'.$gibbonPersonIDStudent : '#lessonChat';
                $notificationURL = "/index.php?q=/modules/Planner/planner_view_full.php&gibbonPlannerEntryID=$gibbonPlannerEntryID&viewBy=date&date=".$row['date'].'&gibbonCourseClassID=&search='.($context === 'Homework' ? $gibbonPersonIDStudent : '').$anchor;

                $recipientIDs = [];
                if ($context === 'Homework') {
                    $recipientIDs[] = $gibbonPersonIDStudent;

                    $dataTeachers = array('gibbonCourseClassID' => $row['gibbonCourseClassID']);
                    $sqlTeachers = "SELECT gibbonPersonID FROM gibbonCourseClassPerson WHERE gibbonCourseClassID=:gibbonCourseClassID AND role LIKE '%Teacher%' AND role NOT LIKE '%Left'";
                    $resultTeachers = $connection2->prepare($sqlTeachers);
                    $resultTeachers->execute($dataTeachers);
                    while ($rowTeacher = $resultTeachers->fetch()) {
                        $recipientIDs[] = $rowTeacher['gibbonPersonID'];
                    }

                    $dataParents = array('gibbonPersonIDStudent' => $gibbonPersonIDStudent);
                    $sqlParents = "SELECT gibbonFamilyAdult.gibbonPersonID FROM gibbonFamilyAdult JOIN gibbonFamilyChild ON (gibbonFamilyAdult.gibbonFamilyID=gibbonFamilyChild.gibbonFamilyID) WHERE gibbonFamilyChild.gibbonPersonID=:gibbonPersonIDStudent AND gibbonFamilyAdult.childDataAccess='Y'";
                    $resultParents = $connection2->prepare($sqlParents);
                    $resultParents->execute($dataParents);
                    while ($rowParent = $resultParents->fetch()) {
                        $recipientIDs[] = $rowParent['gibbonPersonID'];
                    }
                } else {
                    $dataClassGroup = array('gibbonCourseClassID' => $row['gibbonCourseClassID']);
                    $sqlClassGroup = "SELECT * FROM gibbonCourseClassPerson INNER JOIN gibbonPerson ON gibbonCourseClassPerson.gibbonPersonID=gibbonPerson.gibbonPersonID WHERE gibbonCourseClassID=:gibbonCourseClassID AND status='Full' AND (dateStart IS NULL OR dateStart<='".date('Y-m-d')."') AND (dateEnd IS NULL  OR dateEnd>='".date('Y-m-d')."') AND (NOT role='Student - Left') AND (NOT role='Teacher - Left') ORDER BY role DESC, surname, preferredName";
                    $resultClassGroup = $connection2->prepare($sqlClassGroup);
                    $resultClassGroup->execute($dataClassGroup);
                    while ($rowClassGroup = $resultClassGroup->fetch()) {
                        $recipientIDs[] = $rowClassGroup['gibbonPersonID'];
                    }
                }

                foreach (array_unique($recipientIDs) as $recipientID) {
                    if ($recipientID != $session->get('gibbonPersonID') and $recipientID != $replyToID) {
                        $notificationText = $context === 'Homework'
                            ? __('{person} has commented on homework for {lessonName}.', ['person' => $personName, 'lessonName' => $row['name']])
                            : __('{person} has commented on your lesson plan {lessonName}.', ['person' => $personName, 'lessonName' => $row['name']]);
                        $notificationSender->addNotification($recipientID, $notificationText, 'Planner', $notificationURL);
                    }
                }

                $notificationSender->sendNotifications();

                if (is_null($replyToID) == false) {
                    $notificationText = $context === 'Homework'
                        ? __('{person} has replied to a comment you made on homework for {lessonName}.', ['person' => $personName, 'lessonName' => $row['name']])
                        : __('{person} has replied to a comment you made on lesson plan {lessonName}.', ['person' => $personName, 'lessonName' => $row['name']]);
                    $notificationSender->addNotification($replyToID, $notificationText, 'Planner', $notificationURL);
                    $notificationSender->sendNotifications();
                }

                $URL .= '&return=success0'.$anchor;
                header("Location: {$URL}");
            }
        }
    }
}
