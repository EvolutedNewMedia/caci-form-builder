<?php

namespace Nomensa\FormBuilder;

use CSSClassFactory;
use Nomensa\FormBuilder\Exceptions\InvalidSchemaException;

class Row
{
    /** @var bool */
    public $cloneable = false;

    /** @var string */
    public $title;

    /** @var string */
    public $name;

    /** @var string */
    protected $editing_instructions;
    protected $viewing_instructions;
    protected $edit_view_description;

    protected $description;
    protected $notes;

    public $onlyAvailableForBrands;
    public $onlyAvailableForCurricula;
    public $onlyAvailableForRoles;

    /** @var array - A row contains many columns */
    public $columns = [];

    /**
     * Row constructor.
     *
     * @param array $row_schema Defines a single row of a RowGroup. Can contain 'title', 'intro', 'description', 'notes'
     * @param bool $cloneable
     *
     * @throws InvalidSchemaException
     */
    public function __construct(array $row_schema, bool $cloneable = false)
    {
        $this->cloneable = $cloneable;

        $this->title = $row_schema['title'] ?? '';
        $this->name = $row_schema['row_name'] ?? '';
        $this->editing_instructions = $row_schema['editing_instructions'] ?? '';
        $this->viewing_instructions = $row_schema['viewing_instructions'] ?? '';
        $this->edit_view_description = $row_schema['edit_view_description'] ?? '';
        $this->description = $row_schema['description'] ?? '';
        $this->onlyAvailableForBrands = $row_schema['onlyAvailableForBrands'] ?? '';
        $this->onlyAvailableForCurricula = $row_schema['onlyAvailableForCurricula'] ?? '';
        $this->onlyAvailableForRoles = $row_schema['onlyAvailableForRoles'] ?? '';
        $this->notes = $row_schema['notes'] ?? '';
        $this->columns = $row_schema['columns'] ?? null;

        if (isset($this->columns)) {

            foreach ($this->columns as &$column) {

                $column['row_name'] = $row_schema['row_name'];

                $column = new Column($column, $this->cloneable);

            }
        }
    }

    /**
     * @return bool
     */
    private function shouldRender()
    {
        if (!empty($this->onlyAvailableForBrands)) {
            return in_array(current_brand(), $this->onlyAvailableForBrands);
        }

        if (!empty($this->onlyAvailableForCurricula)) {
            return in_array(
                optional(auth()->user())->activeCurriculumOfBrand()->curriculumType->slug ?? null,
                $this->onlyAvailableForCurricula
            );
        }

        if (!empty($this->onlyAvailableForRoles)) {
            $authUser = auth()->user();
            if (!$authUser) {
                return false;
            }

            return $authUser->role($this->onlyAvailableForRoles);
        }

        return true;
    }

    /**
     * @param FormBuilder $formBuilder
     * @param             $group_index
     * @return string
     * @throws Exceptions\InvalidDisplayModeException
     */
    public function markup(FormBuilder $formBuilder, $group_index): string
    {
        if (!$this->shouldRender()) {
            return '';
        }

        $html = '';
        $colsMarkup = '';
        $rowHasVisibleContent = false;

        if (isset($this->columns)) {
            $colCount = count($this->columns);
            foreach ($this->columns as $column) {
                $colMarkup = $column->markup($formBuilder, $colCount, $group_index);
                $colsMarkup .= $colMarkup->html;

                if ($colMarkup->hasVisibleContent) {
                    $rowHasVisibleContent = true;
                }
            }
        }

        if ($this->title && $rowHasVisibleContent) {
            $html .= MarkerUpper::wrapInTag($this->title, 'h2', ['class' => 'heading']);
        }

        if ($this->editing_instructions && !$formBuilder->isDisplayMode('reading') && $rowHasVisibleContent) {
            $html .= MarkerUpper::wrapInTag($this->editing_instructions, 'p');
        }

        if ($this->viewing_instructions && $formBuilder->isDisplayMode('reading')) {
            $html .= MarkerUpper::wrapInTag($this->viewing_instructions, 'p');
        }

        if ($this->edit_view_description) {
            $html .= MarkerUpper::wrapInTag($this->edit_view_description, 'p');
        }


        if ($this->description && $rowHasVisibleContent) {
            $html .= MarkerUpper::wrapInTag($this->description, 'p');
        }

        if ($rowHasVisibleContent) {
            $html .= $this->wrapInRowTags($colsMarkup);
        } else {
            $html .= $colsMarkup;
        }

        if ($this->notes && $rowHasVisibleContent) {
            $html .= MarkerUpper::wrapInTag($this->notes, 'div');
        }

        return $html;
    }


    /**
     * @param string $content
     * @param array $attributes
     *
     * @return string
     */
    private function wrapInRowTags($content, $attributes = []): string
    {
        $classBundle = CSSClassFactory::rowClassBundle();
        if (!empty($attributes['class'])) {
            $classBundle->add($attributes['class']);
        }
        $attributes['class'] = $classBundle->__toString();

        return MarkerUpper::wrapInTag($content, 'div', $attributes);
    }


    /**
     * @param string $row_name
     * @param string $field_name
     *
     * @return null|Column
     */
    public function findField($row_name, $field_name)
    {
        foreach ($this->columns as $column) {
            if ($column->row_name == $row_name && $column->field == $field_name) {
                return $column;
            }
        }
    }

}
