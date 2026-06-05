<?php

namespace Nomensa\FormBuilder;

use Auth;
use Carbon\Carbon;
use CSSClassFactory;
use Exception;
use Field;
use Form;
use Html;
use Nomensa\FormBuilder\Exceptions\InvalidSchemaException;
use Nomensa\FormBuilder\Helpers\OutputHelper;

class Column
{
    const MULTI_OPTION_TYPES = ['checkboxes', 'radios'];
    const WITH_LABEL = true;

    /** @var string */
    public $field = '';

    public $label = '';

    public $label_compute_optional_text = true;

    public $type = 'text';

    public $stateSpecificType = '';

    private $default_value;

    public $fieldName;

    public $id;

    public $value;

    /** @var bool */
    public $cloneable = false;

    public $row_name;

    public $parentTitle;

    public $disabled;

    public $helptext;

    public $helptextIfPreviouslySaved;

    public $prefix;

    public $onlyAvailableForBrands;

    public $onlyAvailableForCurricula;

    public $onlyAvailableForRoles;

    public $errors;

    public $fieldNameWithBrackets;

    public $toolbar;

    /** @var array of HTML tag attributes */
    public $attributes = [];

    /** @var array of display states */
    public $states;

    /** @var array */
    public $classes;

    /** @var ClassBundle */
    public $classBundle;

    /** @var array Values in a select box */
    public $options = [];

    /** @var array Values for option-table */
    public $columnHeadings = [];

    /** @var array of HTML data attributes keyed by name (without "data-" prefix) */
    public $dataAttributes = [];

    /**
     * Column constructor.
     *
     * @param array $column_schema
     * @param bool $cloneable
     * @throws InvalidSchemaException
     */
    public function __construct(array $column_schema, bool $cloneable)
    {
        if (isset($column_schema['field'])) {
            $this->field = $column_schema['field'];
        } else {
            throw new InvalidSchemaException('Columns must have a "field" value');
        }

        if (isset($column_schema['label'])) {
            $this->label = $column_schema['label'];
            $this->label_compute_optional_text = $column_schema['label_compute_optional_text'] ?? true;
        } else {
            throw new InvalidSchemaException('Columns must have a "label" value');
        }

        if (isset($column_schema['type'])) {
            $this->type = $column_schema['type'];
        } else {
            throw new InvalidSchemaException('Columns must have a "type" value');
        }

        $this->default_value = $column_schema['default_value'] ?? null;

        $this->toolbar = $column_schema['toolbar'] ?? null;
        $this->attributes = $column_schema['attributes'] ?? [];
        $this->states = $column_schema['states'] ?? [];
        $this->value = '';

        $this->cloneable = $cloneable;

        $this->prefix = $column_schema['prefix'] ?? null;

        $this->parentTitle = $column_schema['parentTitle'] ?? null;
        $this->classes = $column_schema['classes'] ?? null;
        $this->disabled = $column_schema['disabled'] ?? null;
        $this->helptext = $column_schema['helptext'] ?? null;
        $this->onlyAvailableForBrands = $column_schema['onlyAvailableForBrands'] ?? null;
        $this->onlyAvailableForCurricula = $column_schema['onlyAvailableForCurricula'] ?? null;
        $this->onlyAvailableForRoles = $column_schema['onlyAvailableForRoles'] ?? null;
        $this->helptextIfPreviouslySaved = $column_schema['helptextIfPreviouslySaved'] ?? null;
        $this->row_name = $column_schema['row_name'];
        $this->errors = $column_schema['errors'] ?? null;

        // Construct field name
        $fieldName = $this->row_name;
        if ($this->cloneable) {
            $fieldName .= '.0'; // TODO Make this increment
        }
        $fieldName .= '.' . $this->field;

        $this->fieldName = trim($fieldName, '.');
        $this->fieldNameWithBrackets = MarkerUpper::htmlNameAttribute($this->fieldName);

        // Underscore version
        $this->id = MarkerUpper::HTMLIDFriendly($this->fieldName);

        if (!isset($column_schema['options'])) {
            // Do nothing

        } else {
            if (is_array($column_schema['options'])) {
                if (!$this->hasStringKeys($column_schema['options'])) {
                    $column_schema['options'] = $this->slugKeyArray($column_schema['options'], ($this->type == 'radios'));
                }
                $this->options = $column_schema['options'];
            }
        }

        $this->columnHeadings = $column_schema['column-headings'] ?? null;
    }

    /**
     * Temporary poly-fill until schemas only contain key-pair values
     *
     * @param array $array
     * @return bool
     */
    function hasStringKeys(array $array): bool
    {
        return count(array_filter(array_keys($array), 'is_string')) > 0;
    }

    function slugKeyArray(array $array, $stripFirst = false)
    {
        if ($stripFirst) {
            array_shift($array);
        }
        $associativeArray = [];
        foreach ($array as $item) {
            if (strtolower($item) == 'please select') {
                $associativeArray[""] = $item;
            } else {
                $associativeArray[str_slug($item)] = $item;
            }
        }

        return $associativeArray;
    }

    /**
     * Returns an array containing only the column parameters required by LaravelCollective Form/Field
     *
     * @return array
     * @var $withLabel
     */
    private function asFormArray($withLabel = false)
    {
        $simpleColumn = [
            'id' => $this->id,
            'disabled' => $this->disabled,
            'label' => false,
        ];

        if ($withLabel === Column::WITH_LABEL) {
            $simpleColumn['label'] = $this->label;
        }

        foreach ($this->dataAttributes as $key => $value) {
            $dataAttributeKey = 'data-' . $key;
            $simpleColumn[$dataAttributeKey] = $value;
        }

        $simpleColumn = array_merge($simpleColumn, $this->attributes);

        return $simpleColumn;
    }

    /**
     * @param FormBuilder $formBuilder
     * @return string
     */
    private function markupField(FormBuilder $formBuilder)
    {
        $output = '';

        if ($this->value === null) {
            $this->value = $this->parseDefaultValue($formBuilder);
        }

        switch ($this->stateSpecificType) {

            case "hidden":
            case "ignore":
                // do not render this field at all
                return '';

            case "checkbox":

                return Field::checkbox($this->fieldNameWithBrackets, $this->options, $this->value, $this->asFormArray(Column::WITH_LABEL));

            case "checkboxes":

                $values = json_decode($this->value, true) ?? [];

                $attributes = $this->asFormArray(Column::WITH_LABEL);
                $origID = $attributes['id'];
                foreach ($this->options as $key => $option) {
                    $attributes['label'] = $option;
                    $attributes['id'] = $origID . '_' . $key;
                    $output .= Field::checkbox($this->fieldNameWithBrackets . '[]', $key, in_array($key, $values), $attributes);
                }

                return $output;

            case "checkboxes-readonly": /* Render text into the form and add hidden fields */

                $attributes = $this->asFormArray(Column::WITH_LABEL);

                $values = json_decode($this->value, true);
                if ($values === null) {
                    return '';
                }
                $output .= '<div class="' . $this->classBundle . '">';
                $output .= '<div class="section-readonly">';
                $output .= MarkerUpper::wrapInTag($this->label, "h4");

                if (is_array($values)) {
                    $output .= $this->readonlyMultipleValues($attributes['id'], $values);
                }

                $output .= '</div>' . PHP_EOL . '<!-- /.section-readonly -->' . PHP_EOL;
                $output .= '</div>' . PHP_EOL;

                return $output;

            case "select":

                $attributes = $this->asFormArray();
                $attributes['class'] = CSSClassFactory::selectClassBundle();

                $fieldName = $this->fieldNameWithBrackets;

                // If this select is a multi-select, we need to add empty square brackets on the
                // end to make sure all options are passed through to the request.
                if (isset($attributes['multiple'])) {
                    $fieldName .= '[]';
                }

                // If the value can be decoded to an array of values, do it.
                $values = json_decode($this->value);
                if (!is_array($values)) {
                    $values = $this->value;
                }

                return Form::select($fieldName, $this->options, $values, $attributes);

            case "radios":

                return Form::{$this->type}($this->fieldNameWithBrackets, $this->options, $this->value, $this->asFormArray());

            case "option-table":
                // define table headers from first row
                $headers = $this->columnHeadings;

                $output = '<table class="table table-active table-hide-fooicon" data-expand-all="true" data-toggle-column="last">';
                $output .= '<thead>';

                $output .= '<tr>';
                $output .= '<th></th>';

                foreach ($headers as $header => $value) {
                    $output .= "<th>$value</th>";
                }

                $output .= '</tr>';
                $output .= '</thead>';
                $output .= '<tbody>';

                foreach ($this->options as $value => $cells) {

                    $selected = $this->value == $value ? true : false;

                    $output .= '<tr>';

                    $output .= "<td>" . Form::radio($this->fieldNameWithBrackets, $value, $selected, ['id' => $this->fieldNameWithBrackets . '_' . $value]) . "</td>";

                    foreach ($cells as $cell) {
                        $output .= "<td>" . Form::label($this->fieldNameWithBrackets . '_' . $value, OutputHelper::output($cell)) . "</td>";
                    }

                    $output .= '</tr>';
                }

                $output .= '</tbody>';
                $output .= '</table>';

                break;

            case "file":

                return Field::file($this->fieldNameWithBrackets, $this->asFormArray());

            case "date":

                if ($formBuilder->ruleExists($this->fieldName, 'date_is_in_the_past')) {
                    $this->dataAttributes['mindate'] = '-5y';
                    $this->dataAttributes['maxdate'] = 0;
                }

                if ($formBuilder->ruleExists($this->fieldName, 'date_is_in_the_future')) {
                    $this->dataAttributes['mindate'] = 0;
                    $this->dataAttributes['maxdate'] = '+5y';
                }

                if ($formBuilder->ruleExists($this->fieldName, 'date_is_within_the_last_month')) {
                    $this->dataAttributes['mindate'] = '-1m';
                    $this->dataAttributes['maxdate'] = 0;
                }

                $rule = $formBuilder->ruleExists($this->fieldName, 'before');
                if ($rule && $rule == "before:today") {
                    $this->dataAttributes['mindate'] = '-5y';
                    $this->dataAttributes['maxdate'] = 0;
                }

                if ($this->value) {
                    $this->value = $this->value->format('Y-m-d');
                }

                // We create date as a text field (NOT date!) because we replace it with a date picker and don't want Chrome to be "helpful"
                return Field::text($this->fieldNameWithBrackets, $this->value, $this->asFormArray());

            case "date-readonly":  /* Render text into the form and add a hidden field */

                if (!empty($this->value)) {

                    // Catch format() exception if $this->value is passed in as a string
                    // to determine cause of recurring issue
                    try {
                        MarkerUpper::wrapInTag($this->value->format('j F Y'), 'p');
                    } catch (Exception $exception) {
                        $valueData = [
                            '$this->stateSpecificType' => $this->stateSpecificType,
                            '$this->classBundle' => $this->classBundle,
                            '$this->label' => $this->label,
                            '$this->value' => $this->value,
                        ];

                        logger('date-readonly exception', [
                            'EXCEPTION' => [
                                'exception type' => get_class($exception),
                                'message' => $exception->getMessage(),
                                'file' => $exception->getFile(),
                                'line' => $exception->getLine(),
                                'trace' => $exception->getTraceAsString(),
                            ],
                            'VALUE DATA' => json_encode($valueData, JSON_PRETTY_PRINT),
                        ]);
                                    
                    }
                    
                    // DEFENSIVE FIX
                    $date = $this->value instanceof Carbon
                        ? $this->value
                        : Carbon::parse($this->value);

                    $output .= '<div class="' . $this->classBundle . '">';
                    $output .= '<div class="section-readonly">';
                    $output .= MarkerUpper::wrapInTag($this->label, "h4");
                    $output .= MarkerUpper::wrapInTag($date->format('j F Y'), 'p');
                    $output .= '</div>' . PHP_EOL . '<!-- /.section-readonly -->' . PHP_EOL;
                    $output .= '</div>' . PHP_EOL;
                    $output .= Field::hidden($this->fieldNameWithBrackets,
                        $this->value->format('Y-m-d'), $this->asFormArray());
                }

                break;

            case "password":

                return Form::bsPassword($this->fieldNameWithBrackets, $this->value, $this->asFormArray());

            case "radios-readonly":  /* Render text into the form and add a hidden field */
            case "select-readonly":  /* Render text into the form and add a hidden field */

                if (!empty($this->value)) {
                    $output .= '<div class="' . $this->classBundle . '">';
                    $output .= '<div class="section-readonly">';

                    if (isset($this->parentTitle)) {
                        $output .= MarkerUpper::wrapInTag($this->parentTitle, "h3");
                    }

                    $output .= MarkerUpper::wrapInTag($this->label, "h4");

                    $attributes = $this->asFormArray();
                    $values = json_decode($this->value, true);

                    if (is_array($values)) {
                        $output .= $this->readonlyMultipleValues($attributes['id'], $values);
                    } else {

                        if (isset($this->options[$this->value])) {
                            $output .= MarkerUpper::wrapInTag($this->options[$this->value], 'p');
                        } else {
                            $output .= MarkerUpper::wrapInTag(OutputHelper::output($this->value), 'p');
                        }
                    }

                    $output .= '</div>' . PHP_EOL . '<!-- /.section-readonly -->' . PHP_EOL;
                    $output .= '</div>' . PHP_EOL;
                    $output .= Field::hidden($this->fieldNameWithBrackets, $this->value, $attributes);
                }
                break;

            case "text-readonly":  /* Render text into the form and add a hidden field */
            case "number-readonly":
                $this->value = OutputHelper::output($this->value);
            case "textarea-readonly":  /* Render text into the form and add a hidden field */
                $this->value = $this->stripTagsTextarea();

                if (!empty($this->value) || $this->value === 0) {
                    $output .= '<div class="' . $this->classBundle . '">';
                    $output .= '<div class="section-readonly">';
                    $output .= MarkerUpper::wrapInTag($this->label, "h4");
                    $output .= MarkerUpper::wrapInTag($this->value, 'div');
                    $output .= '</div>' . PHP_EOL . '<!-- /.section-readonly -->' . PHP_EOL;
                    $output .= Field::hidden($this->fieldNameWithBrackets, $this->value, $this->asFormArray());
                    $output .= '</div>' . PHP_EOL;

                }
                break;

            case "option-table-readonly":
                // define table headers from first row
                $headers = $this->columnHeadings;

                $output .= '<div class="' . $this->classBundle . '">';
                $output .= '<div class="section-readonly">';

                $output .= '<table class="table table-active table-hide-fooicon" data-expand-all="true" data-toggle-column="last">';
                $output .= '<thead>';

                $output .= '<tr>';

                foreach ($headers as $header => $value) {
                    $output .= "<th>$value</th>";
                }

                $output .= '</tr>';
                $output .= '</thead>';
                $output .= '<tbody>';

                foreach ($this->options as $value => $cells) {

                    // only display the selected row
                    if ($this->value == $value) {
                        $output .= '<tr>';

                        foreach ($cells as $cell) {
                            $output .= "<td>" . OutputHelper::output($cell) . "</td>";
                        }

                        $output .= '</tr>';
                    }
                }

                $output .= '</tbody>';
                $output .= '</table>';

                $output .= '</div>' . PHP_EOL . '<!-- /.section-readonly -->' . PHP_EOL;
                $output .= '</div>' . PHP_EOL;
                $output .= Field::hidden($this->fieldNameWithBrackets, $this->value, $this->asFormArray());

                break;

            case "search":

                return Form::text($this->fieldNameWithBrackets, $this->value, $this->asFormArray());

            case 'textarea':
                $this->value = $this->stripTagsTextarea();

                return Field::{$this->type}($this->fieldNameWithBrackets, htmlspecialchars($this->value), $this->asFormArray());

            default:

                return Field::{$this->type}($this->fieldNameWithBrackets, $this->value, $this->asFormArray());
        }

        return $output;
    }

    /**
     * Used by checkboxes and multi-selects to print a list of values
     *
     * @param string $origID
     * @param array $values
     * @return string
     */
    private function readonlyMultipleValues($origID, array $values): string
    {
        $output = '<ul id="' . $origID . '_values">';
        foreach ($values as $i => $value) {

            // TODO: This hidden field is legacy support and should be able to be removed soon
            $output .= Field::hidden($this->fieldNameWithBrackets . '[]', OutputHelper::output($value));

            $output .= '<li>' . FormBuilder::findHumanValueIfAvailable($this->options, $value) . '</li>';
        }
        $output .= '</ul>';

        return $output;
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
     * @param int $totalCols
     * @param null|int $group_index
     * @return MarkUp
     */
    public function markup(FormBuilder $formBuilder, $totalCols, $group_index): MarkUp
    {
        if (!$this->shouldRender()) {
            return new MarkUp('');
        }

        $this->classBundle = CSSClassFactory::colClassBundle($totalCols);
        $this->classBundle->add($this->classes);

        $this->rules = isset($this->rules) ? $this->rules : null;
        $this->field = isset($this->field) ? $this->field : $this->fieldName;
        $this->selected = isset($this->selected) ? $this->selected : null;
        $this->saved = isset($this->saved) ? $this->saved : null;
        $this->label = $this->label ?? null;

        $this->value = $formBuilder->getFieldValue($this->row_name, $group_index, $this->field);

        if ($this->value && !empty($this->helptextIfPreviouslySaved)) {
            $this->helptext = $this->helptextIfPreviouslySaved;
        }

        $output = '';

        $state = $this->getState($formBuilder);

        // if access for your state determines content is hidden then don't render the field
        if ($state == 'hidden-for-learner' && $formBuilder->formInstance->workflow->name != 'learner-approval') {
            return new MarkUp('', MarkUp::NO_VISIBLE_CONTENT);
        }

        /** check if variable exists in viewData and set state as editable if it does and is true */
        if (preg_match('/^editable_if_true_else_ignore:(.*)$/', $state, $matches)) {
            $keyName = $matches[1];

            $state = ($formBuilder->viewData[$keyName] == true) ? 'editable' : 'ignore';
        }

        /** check if variable exists in viewData and set state as editable if it does and is true */
        if (preg_match('/^editable_if_true_else_readonly:(.*)$/', $state, $matches)) {
            $keyName = $matches[1];

            $state = ($formBuilder->viewData[$keyName] ?? false) == true ? 'editable' : 'readonly';
        }

        $this->stateSpecificType = $this->type;

        if ($state == 'readonly_for_owner' && $formBuilder->owner->id == (Auth::user()->id ?? null) ||
            $state == 'editable_for_owner' && $formBuilder->owner->id != (Auth::user()->id ?? null) ||
            $state != 'hidden' && $formBuilder->isDisplayMode('reading') ||
            $state == 'readonly') {
            $state = 'readonly';
            $this->stateSpecificType = $this->type . '-' . $state;
        }

        if ($state == 'hidden') {
            $this->stateSpecificType = 'hidden';
        }

        if ($state == 'ignore') {
            $this->stateSpecificType = 'ignore';
        }

        $optional = $this->label_compute_optional_text
            ? ($formBuilder->ruleExists($this->fieldName, 'nullable') ? '<span class="optional"> ' . __('validation.optional_field') . '</span>' : null)
            : null;

        $inlineErrors = $formBuilder->getInlineFieldError($this->fieldName);

        if (!empty($inlineErrors)) {
            $this->classBundle->add("errors");
        }

        if ($this->cloneable) {
            // Replace the zero surrounded by dots with the clone number
            $this->fieldNameWithBrackets = preg_replace('/\[[\d]+\]/', '[' . $group_index . ']', $this->fieldNameWithBrackets);
            // ...and the same for dot notation in fieldName...
            $this->fieldName = preg_replace('/\.[\d]+\./', '.' . $group_index . '.', $this->fieldName);
            // ...and finally, for underscore-separated in id
            $this->id = preg_replace('/_[\d]+_/', '_' . $group_index . '_', $this->id);
        }

        $columnHTML = $this->markupField($formBuilder);

        // if type is neither ignore  or hidden or readonly, wrap it in a container div
        if ($this->stateSpecificType != 'ignore' && $this->stateSpecificType != 'hidden' && $state != 'readonly') {

            $output .= $formBuilder->getErrorAnchor($this->fieldName);

            $output .= '<div class="' . $this->classBundle . '">';
            if (isset($this->prefix)) {
                $output .= MarkerUpper::wrapInTag($this->prefix, 'div', ['id' => $this->id]);
            }

            if (in_array($this->type, $this::MULTI_OPTION_TYPES)) {

                $output .= '<fieldset id="' . str_replace('.', '_',
                        $this->fieldName) . '">';
                $output .= '<legend>' . $this->label . $optional . '</legend>';
            } else {
                if ($this->type != 'checkbox') {
                    $output .= HTML::decode(Form::label(str_replace('.', '_', $this->fieldName), $this->label . $optional));
                }
            }

            if ($inlineErrors) {
                $output .= $inlineErrors;
            }

            if (!empty($this->helptext)) {
                $output .= '<div class="help_text">' . $this->helptext . '</div>';
            }

            $output .= $columnHTML;

            if (!empty($this->suffix)) {
                $output .= MarkerUpper::wrapInTag($this->suffix, 'div');
            }

            if (!empty($this->anchor)) {
                $a = explode("-", $this->anchor);
                $output .= "<span class=\"help\"><a href=\"#" . $a[0] . "\">" . $a[1] . "</a></span>";
            }

            if (in_array($this->type, $this::MULTI_OPTION_TYPES)) {
                $output .= '</fieldset>';
            }

            $output .= '</div>';
        } elseif ($state == 'readonly' && strlen($columnHTML)) {

            return new MarkUp($columnHTML);
        } else {

            return new MarkUp($columnHTML, MarkUp::NO_VISIBLE_CONTENT);
        }

        return new MarkUp($output);
    }

    /**
     * @param FormBuilder $formBuilder
     * @return string Defaults to 'editable'
     */
    private function getState(FormBuilder $formBuilder)
    {
        $key = $formBuilder->getStateKey();
        if (isset($this->states[$key])) {
            return $this->states[$key];
        }

        return 'editable';
    }

    /**
     * Returns the default value or a dynamically generated default if default is a keyword like "TODAY"
     *
     * @return null|string
     */
    private function parseDefaultValue(FormBuilder $formBuilder)
    {
        if ($this->default_value === 'TODAY') {
            return Carbon::now();
        }

        if ($this->default_value === 'INCREMENTS_FOR_USER') {
            $maxVal = $formBuilder->owner->formSubmissionFields
                ->where('row_name', $this->row_name)
                ->where('field_name', $this->field)
                ->max('value');

            return (int)$maxVal + 1;
        }

        return $this->default_value;
    }

    /**
     * @return string
     */
    protected function stripTagsTextarea(): string
    {
        return strip_tags($this->value, [
            'p',
            'strong',
            'em',
            'b',
            'i',
            'ol',
            'ul',
            'li',
            'br',
            'span',
            'div',
            'wbr'
        ]);
    }
}
