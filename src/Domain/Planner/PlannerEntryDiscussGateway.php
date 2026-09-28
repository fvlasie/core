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

namespace Gibbon\Domain\Planner;

use Gibbon\Domain\Traits\TableAware;
use Gibbon\Domain\QueryableGateway;

/**
 * Planner Entry Discuss Gateway
 *
 * @version v31
 * @since   v31
 */
class PlannerEntryDiscussGateway extends QueryableGateway
{
    use TableAware;

    private static $tableName = 'gibbonPlannerEntryDiscuss';
    private static $primaryKey = 'gibbonPlannerEntryDiscussID';
    private static $searchableColumns = [];

    public function selectDiscussionByPlannerEntryID($gibbonPlannerEntryID, $parent = null, $context = 'Lesson', $gibbonPersonIDStudent = null)
    {
        $context = $context === 'Homework' ? 'Homework' : 'Lesson';

        $query = $this
            ->newSelect()
            ->cols([
                'gibbonPlannerEntryDiscuss.*',
                'gibbonPerson.title',
                'gibbonPerson.surname',
                'gibbonPerson.preferredName',
                'gibbonPerson.image_240',
                'gibbonRole.category',
            ])
            ->from($this->getTableName())
            ->innerJoin('gibbonPerson', 'gibbonPlannerEntryDiscuss.gibbonPersonID=gibbonPerson.gibbonPersonID')
            ->innerJoin('gibbonRole', 'gibbonPerson.gibbonRoleIDPrimary=gibbonRole.gibbonRoleID')
            ->where('gibbonPlannerEntryDiscuss.gibbonPlannerEntryID=:gibbonPlannerEntryID')
            ->bindValue('gibbonPlannerEntryID', $gibbonPlannerEntryID)
            ->where('gibbonPlannerEntryDiscuss.context=:context')
            ->bindValue('context', $context)
            ->orderBy(['gibbonPlannerEntryDiscuss.timestamp']);

        if ($context === 'Homework') {
            $query->where('gibbonPlannerEntryDiscuss.gibbonPersonIDStudent=:gibbonPersonIDStudent')
                ->bindValue('gibbonPersonIDStudent', $gibbonPersonIDStudent);
        }

        if ($parent === 0) {
            $query->where('gibbonPlannerEntryDiscuss.gibbonPlannerEntryDiscussIDReplyTo IS NULL');
        } elseif ($parent != null) {
            $query->where('gibbonPlannerEntryDiscuss.gibbonPlannerEntryDiscussIDReplyTo=:parent', ['parent' => $parent]);
        }

        return $this->runSelect($query);
    }
}
