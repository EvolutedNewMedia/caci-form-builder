<?php

namespace Nomensa\FormBuilder;

class RowGroup
{
    const CLONEABLE = true;

    /** @var string */
    public $name;

    /** @var bool */
    public $cloneable = false;

    /** @var array - Can contain both Rows and RowGroups */
    public $rows = [];


    /**
     * RowGroup constructor.
     *
     * @param array $rows
     * @param $name
     * @param bool $cloneable
     *
     * @throws \Nomensa\FormBuilder\Exceptions\InvalidSchemaException
     */
    public function __construct(array $rows, $name, bool $cloneable = false)
    {
        if (empty($name)) {
            $name = 'dynamic';
        }
        $this->name = $name;

        $this->cloneable = $cloneable;

        $this->rows = $rows;

        // Iterate over array of rows as arrays, converting them to instances of Row
        foreach ($this->rows as $key => &$row) {

            if (isset($row['cloneable_rowgroup']) && $row['cloneable_rowgroup'] == true) {

                $row = new RowGroup($row['rows'], $row['rowgroup_name'], self::CLONEABLE);

            } else {

                $row['row_name'] = $this->name;

                $row = new Row($row, $this->cloneable);

            }
        }

    }

    /**
     * Calls markupClone the required number of times (mostly just once)
     *
     * @param \Nomensa\FormBuilder\FormBuilder $formBuilder
     *
     * @return string HTML markup
     */
    public function markup(FormBuilder $formBuilder): string
    {
        $html = '';

        if ($this->cloneable) {

            // See if there are any values in the old request
            $name = $this->name;
            $requestVals = old($name);
            $requestGroupCloneCount = is_countable($requestVals) ? count($requestVals) : 0;

            // Decide if we need to loop over multiple times
            $rowGroupValueCounts = $formBuilder->getRowGroupValueCount($this->name);

            // Add a hidden field to track to number of active groups
            $html .= '<input type="hidden" id="cloneableRowGroupsCounts-' . $this->name . '" name="cloneableRowGroupsCounts[' . $this->name . ']" value="' . $rowGroupValueCounts . '">' . PHP_EOL . PHP_EOL;

            // We ALWAYS want at least 1 copy of a cloneable rowGroup, otherwise editing
            // a form attempting to add a group for the first time is impossible.
            $iLimit = max(1, $requestGroupCloneCount, $rowGroupValueCounts);

            for ($group_index = 0; $group_index < $iLimit; $group_index++) {
                $groupHTML = $this->markupClone($formBuilder, $group_index);
                $html .= $groupHTML;
            }

            // If there is markup and the form is not read-only
            if ($groupHTML != '' && !$formBuilder->isDisplayMode('reading')) {
                $html .= '<p><span class="btn btn-link btn-clone-rowGroup" data-target="' . $this->name . '">Add another</span></p>';
            }

        } else {
            $html .= $this->markupClone($formBuilder);
        }

        return $html;
    }

    /**
     * Iterates over rows, concatenating markup
     *
     * @param \Nomensa\FormBuilder\FormBuilder $formBuilder
     * @param null|int $group_index
     *
     * @return string HTML markup
     */
    private function markupClone(FormBuilder $formBuilder, $group_index = null): string
    {
        $html = '';
        foreach ($this->rows as $row) {
            $html .= $row->markup($formBuilder, $group_index);
        }

        if ($this->cloneable && strlen($html)) {

            $html .= '<div class="text-right remove-button-wrapper" style="display: none;">';
            $html .= '<a class="btn btn-link btn-remove-rowGroup" data-target="' . $this->name . '">Remove</a>';
            $html .= '</div>';

            $attributes = [
                'class' => 'rowGroup-cloneable',
                'id' => $this->name
            ];
            if ($group_index > 0) {
                $attributes['class'] .= ' ' . $this->name . '-clone';
                $attributes['class'] .= ' clone-' . $group_index;
                unset($attributes['id']);
            }
            $html = MarkerUpper::wrapInTag($html, 'div', $attributes);


        }
        return $html;
    }


    /**
     * @param string $row_name
     * @param string $field_name
     *
     * @return null|Column
     */
    public function findField($row_name, $field_name)
    {
        foreach ($this->rows as $row) {
            $field = $row->findField($row_name, $field_name);
            if ($field) {
                return $field;
            }
        }
    }

}
