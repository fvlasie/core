<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

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

namespace Gibbon\Tables\Renderer;

use Gibbon\Domain\DataSet;
use Gibbon\Domain\QueryCriteria;
use Gibbon\Forms\FormFactory;
use Gibbon\Tables\DataTable;
use Gibbon\Tables\ActionColumn;
use Gibbon\Tables\Renderer\RendererInterface;

/**
 * PaginatedRenderer
 *
 * @version v16
 * @since   v16
 */
class PaginatedRenderer implements RendererInterface
{
    protected $path;
    protected $criteria;
    protected $factory;
    
    /**
     * Creates a renderer that uses page info from the QueryCriteria to display a paginated data table.
     * Hooks into the DataTable functionality in core.js to load using AJAX.
     *
     * @param QueryCriteria $criteria
     * @param string $path
     */
    public function __construct(QueryCriteria $criteria, $path)
    {
        $this->path = $path;
        $this->criteria = $criteria;
        $this->factory = FormFactory::create();
    }

    /**
     * Render the table to HTML. TODO: replace with Twig.
     *
     * @param DataTable $table
     * @param DataSet $dataSet
     * @return string
     */
    public function renderTable(DataTable $table, DataSet $dataSet)
    {
        $output = '';

        $output .= '<div class="linkTop">';
        foreach ($table->getHeader() as $header) {
            $output .= $header->getOutput();
        }
        $output .= '</div>';

        $output .= '<div id="'.$table->getID().'">';
        $output .= '<div class="dataTable">';

        // Debug the AJAX $POST => Filters
        // $output .= '<code>';
        // $output .= 'POST: '.json_encode($_POST).'<br/>';
        // $output .= '</code>';

        // Debug the criteria
        // $output .= '<code>';
        // $output .= 'Criteria: '.$this->criteria->toJson();
        // $output .= '</code>';

        $filterOptions = $table->getMetaData('filterOptions', []);

        $output .= '<div>';
        $output .= $this->renderPageCount($dataSet);
        $output .= $this->renderPageFilters($dataSet, $filterOptions);
        $output .= '</div>';
        $output .= $this->renderFilterOptions($dataSet, $filterOptions);
        $output .= $this->renderPageSize($dataSet);
        $output .= $this->renderPagination($dataSet);

        if ($dataSet->count() > 0) {
            $output .= '<table class="fullWidth colorOddEven" cellspacing="0">';

            // HEADING
            $output .= '<thead>';
            $output .= '<tr class="head">';
            foreach ($table->getColumns() as $columnName => $column) {
                $classes = array('column');

                if ($sortBy = $column->getSortable()) {
                    $classes[] = 'sortable';

                    foreach ($sortBy as $sortColumn) {
                        if ($this->criteria->hasSort($sortColumn)) {
                            $classes[] = 'sorting sort'.$this->criteria->getSortBy($sortColumn);
                        }
                    }
                } else {
                    $sortBy = array();
                }

                $output .= '<th title="'.$column->getTitle().'" style="width:'.$column->getWidth().'" class="'.implode(' ', $classes).'" data-sort="'.implode(',', $sortBy).'">';
                $output .=  $column->getLabel();
                $output .= '<br/><small><i>'.$column->getDescription().'</i></small>';
                $output .= '</th>';
            }
            $output .= '</tr>';
            $output .= '</thead>';

            // ROWS
            $output .= '<tbody>';

            $rowLogic = $table->getRowLogic();
            $cellLogic = $table->getCellLogic();

            foreach ($dataSet as $data) {
                $row = $this->factory->createTableCell();
                if ($rowLogic) {
                    $row = $rowLogic($row, $data);
                }

                $output .= '<tr '.$row->getAttributeString().'>';
                $output .= $row->getPrepended();

                foreach ($table->getColumns() as $columnName => $column) {
                    $cell = $this->factory->createTableCell();
                    if ($cellLogic) {
                        $cell = $cellLogic($cell, $data);
                    }

                    $output .= '<td '.$row->getAttributeString().'>';
                    $output .= $cell->getPrepended();
                    $output .= $column->getOutput($data);
                    $output .= $cell->getAppended();
                    $output .= '</td>';
                }

                $output .= $row->getAppended();
                $output .= '</tr>';
            }

            $output .= '</tbody>';
            $output .= '</table>';

            $output .= $this->renderPageCount($dataSet);
            $output .= $this->renderPagination($dataSet);
        } else {
            if ($dataSet->isSubset()) {
                $output .= '<div class="warning">';
                $output .= __('No results matched your search.');
                $output .= '</div>';
            } else {
                $output .= '<div class="error">';
                $output .= __('There are no records to display.');
                $output .= '</div>';
            }
        }

        

        $output .= '</div></div><br/>';

        // Initialize the jQuery Data Table functionality
        $output .="
        <script>
        $(function(){
            $('#".$table->getID()."').gibbonDataTable('.".str_replace(' ', '%20', $this->path)."', ".$this->criteria->toJson().", ".$dataSet->getResultCount().");
        });
        </script>";

        return $output;
    }

    /**
     * Render the record count for this page, and total record count.
     *
     * @param DataSet $dataSet
     * @return string
     */
    protected function renderPageCount(DataSet $dataSet)
    {
        $output = '<span class="small" style="line-height: 32px;margin-right: 10px;">';

        $output .= $this->criteria->hasSearchText()? __('Search').' ' : '';
        $output .= $dataSet->isSubset()? __('Results') : __('Records');
        $output .= $dataSet->count() > 0? ' '.$dataSet->getPageFrom().'-'.$dataSet->getPageTo().' '.__('of').' ' : ': ';
        $output .= $dataSet->isSubset()? $dataSet->getResultCount() : $dataSet->getTotalCount();

        $output .= '</span>';

        return $output;
    }

    /**
     * Render the currently active filters for this data set.
     *
     * @param DataSet $dataSet
     * @param array $filters
     * @return string
     */
    protected function renderPageFilters(DataSet $dataSet, array $filters)
    {
        $output = '<span class="small" style="line-height: 32px;">';

        if ($this->criteria->hasFilter()) {
            $output .= __('Filtered by').' ';

            $criteriaUsed = array_reduce($this->criteria->getFilterBy(), function($group, $item) use ($filters) {
                $group[$item] = isset($filters[$item])? $filters[$item] : ucwords(str_replace(':', ': ', $item));
                return $group; 
            }, array());

            foreach ($criteriaUsed as $value => $label) {
                $output .= '<input type="button" class="filter" value="'.$label.'" data-filter="'.$value.'"> ';
            }

            $output .= '<input type="button" class="filter clear buttonLink" value="'.__('Clear').'">';
        }

        return $output;
    }

    /**
     * Render the available options for filtering the data set.
     *
     * @param DataSet $dataSet
     * @param array $filters
     * @return string
     */
    protected function renderFilterOptions(DataSet $dataSet, array $filters)
    {
        if (empty($filters)) return '';
        
        return $this->factory->createSelect('filter')
            ->fromArray($filters)
            ->setClass('filters floatNone')
            ->placeholder(__('Filters'))
            ->getOutput();
    }

    /**
     * Render the page size drop-down. Hidden if there's less than one page of total results.
     *
     * @param DataSet $dataSet
     * @return string
     */
    protected function renderPageSize(DataSet $dataSet)
    {
        if ($dataSet->getPageSize() <= 0 || $dataSet->getPageCount() <= 1) return '';

        return $this->factory->createSelect('limit')
            ->fromArray(array(10, 25, 50, 100))
            ->setClass('limit floatNone')
            ->selected($dataSet->getPageSize())
            ->append('<small style="line-height: 30px;margin-left:5px;">'.__('Per Page').'</small>')
            ->getOutput();
    }

    /**
     * Render the set of numeric page buttons for naigating paginated data sets.
     *
     * @param DataSet $dataSet
     * @return string
     */
    protected function renderPagination(DataSet $dataSet)
    {
        if ($dataSet->getPageCount() <= 1) return '';

        $pageNumber = $dataSet->getPage();

        $output = '<div class="floatRight">';
            $output .= '<input type="button" class="paginate" data-page="'.$dataSet->getPrevPageNumber().'" '.($dataSet->isFirstPage()? 'disabled' : '').' value="'.__('Prev').'">';

            foreach ($dataSet->getPaginatedRange() as $page) {
                if ($page === '...') {
                    $output .= '<input type="button" disabled value="...">';
                } else {
                    $class = ($page == $pageNumber)? 'active paginate' : 'paginate';
                    $output .= '<input type="button" class="'.$class.'" data-page="'.$page.'" value="'.$page.'">';
                }
            }

            $output .= '<input type="button" class="paginate" data-page="'.$dataSet->getNextPageNumber().'" '.($dataSet->isLastPage()? 'disabled' : '').' value="'.__('Next').'">';
        $output .= '</div>';

        return $output;
    }
}