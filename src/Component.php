<?php

namespace Nomensa\FormBuilder;

use View;

class Component
{
    /** @var string */
    public $type;

    /** @var mixed|null */
    public $componentData;

    /** @var Instance of \Nomensa\FormBuilder\RowGroup */
    public $rowGroup;

    /** @var string Optional name */
    public $rowGroupName;

    /** @var string - A key-pair value to map the field IDs to a human-friendly names */
    public $fieldMappings;

    public function __construct(array $component_schema)
    {
        $this->type = $component_schema['type'];
        $this->errors = []; // TODO Errors pulled in from request when in update

        $this->rowGroupName = $component_schema['validationGroup'] ?? null;

        if (isset($component_schema['rows'])) {
            $this->rowGroup = new RowGroup($component_schema['rows'], $this->rowGroupName);
        }

        if (isset($component_schema['field-mappings'])) {
            $this->fieldMappings = $component_schema['field-mappings'];
        }

        $this->componentData = $component_schema['componentData'] ?? null;

    }


    /**
     * @param \Nomensa\FormBuilder\FormBuilder $formBuilder - The containing form
     *
     * @return string
     */
    public function markup(FormBuilder $formBuilder)
    {
        if ($this->type == "dynamic") {
            return $this->rowGroup->markup($formBuilder);
        } else {

            $data = array_merge([
                'formBuilder' => $formBuilder,
            ], $formBuilder->viewData);

            // If componentData has been provided in the schema, throw these in the mix too!
            if (is_array($this->componentData)) {
                $data = array_merge($data, $this->componentData);
            }

            $view = View::make('components.formbuilder.' . $this->type, $data);
            return $view->render();
        }
    }


    /**
     * @param string $row_name
     * @param string $field_name
     *
     * @return null|Column
     */
    public function findField($row_name, $field_name)
    {
        if ($this->rowGroup) {
            return $this->rowGroup->findField($row_name, $field_name);
        }
    }

}
