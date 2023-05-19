<?php

namespace Nomensa\FormBuilder\Model;

use Illuminate\Database\Eloquent\Model;
use Nomensa\FormBuilder\Helpers\DateStringHelper;
use Nomensa\FormBuilder\Helpers\HTML;

class FormSubmission extends Model
{

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function formInstance()
    {
        return $this->belongsTo('App\FormInstance');
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function formVersion()
    {
        return $this->belongsTo('App\FormVersion');
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function formParticipants()
    {
        return $this->hasMany('App\FormParticipant', 'form_submission_id');
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function formSubmissionFields()
    {
        return $this->hasMany('App\FormSubmissionField', 'form_submission_id');
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function formAssociationsAsRoot()
    {
        return $this->hasMany('App\FormAssociation', 'root_form_submission_id');
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function formAssociationsAsDestination()
    {
        return $this->hasMany('App\FormAssociation', 'destination_form_submission_id');
    }


    public function assignFormAssociation($type, FormSubmission $formSubmission)
    {
        return FormAssociation::create([
            'type' => $type,
            'root_form_submission_id' => $this->id,
            'destination_form_submission_id' => $formSubmission->id
        ]);
    }


    /**
     * Updates fields based on schema in a specific state
     *
     * @param array $inputs
     * @param string $state - The name/key of the state
     *
     * @return array
     */
    public function updateFieldsInState($inputs, $state)
    {
        $formBuilder = $this->formInstance->formVersion->getFormBuilder();

        $inputStructure = $formBuilder->getRequestInputStructureInState($state);

        $i = 0;

        // Iterate over only the fields that should exist in this state (ignore any others that may have been injected)
        foreach ($inputStructure as $row_name => $row) {

            if ($row->cloneable) {

                $group_index = 0;
                while (isset($inputs[$row_name][$group_index])) {
                    foreach ($row->fields as $field_name) {
                        if (isset($inputs[$row_name][$group_index][$field_name])) {
                            $value = $inputs[$row_name][$group_index][$field_name];

                            $this->saveOrUpdateField($i, $row_name, $group_index, $field_name, $value);
                            $i++;
                        }
                    }
                    $group_index++;
                }

            } else {
                foreach ($row->fields as $field_name) {
                    if (array_key_exists($row_name, $inputs) && array_key_exists($field_name, $inputs[$row_name])) {

                        $value = $inputs[$row_name][$field_name];

                        $this->saveOrUpdateField($i, $row_name, null, $field_name, $value);
                        $i++;

                    }
                }
            }
        }

        if (isset($inputs['cloneableRowGroupsCounts'])) {

            foreach ($inputs['cloneableRowGroupsCounts'] as $row_name => $count) {

                if ($count > 0) {
                    // Delete any form_submission_fields with this row_name and a group index higher than $count-1
                    $this->deleteFieldsWithGroupIndexAbove($row_name, $count - 1);
                }
            }

        }

        // If the formSubmissionFields relationship has been eager-loaded, unset it because it will be out-of-date
        if (array_key_exists('formSubmissionFields', $this->relations) ) {
            unset($this->relations['formSubmissionFields']);
        }

        return $inputs;
    }


    /**
     * This is used to delete cloneable row groups that were saved, but removed on edit
     * and don't exist in the
     *
     * @param string $row_name
     * @param int $max_group_index
     *
     * @return integer
     */
    protected function deleteFieldsWithGroupIndexAbove($row_name, $max_group_index)
    {
        return $this->formSubmissionFields()
            ->where('row_name', $row_name)
            ->where('group_index', '>', $max_group_index)
            ->delete();
    }


    /**
     * Update field with new value
     *
     * @param string $row_name
     * @param string $field_name
     * @param mixed $value
     *
     * @return \App\FormSubmissionField
     */
    public function updateRowFieldValue($row_name, $field_name, $value): FormSubmissionField
    {
        return $this->saveOrUpdateField(0, $row_name, null, $field_name, $value);
    }


    /**
     * @param $weight
     * @param string $row_name
     * @param $group_index
     * @param string $field_name
     * @param $value
     *
     * @return \App\FormSubmissionField - The same field passed in, but updated
     */
    public function saveOrUpdateField($weight, $row_name, $group_index, $field_name, $value): FormSubmissionField
    {
        // Check if existing row
        $field = FormSubmissionField::where([
            'row_name' => $row_name,
            'group_index' => $group_index,
            'field_name' => $field_name,
            'form_submission_id' => $this->id,
        ])->first();

        if ($field) {
            // It exists, let's update it
            $field = $this->populateFormSubmissionField($weight, $row_name, $group_index, $field_name, $value, $field);
            $field->save();
            return $field;
        } else {
            // It doesn't exist
            return $this->saveField($weight, $row_name, $group_index, $field_name, $value);
        }
    }


    /**
     * Creates a new FormSubmissionField
     *
     * @param integer $weight
     * @param string $row_name
     * @param integer $group_index
     * @param string $field_name
     * @param $value
     *
     * @return FormSubmissionField - The newly created field
     */
    public function saveField($weight, string $row_name, $group_index, string $field_name, $value): FormSubmissionField
    {
        $formSubmissionField = new FormSubmissionField();
        $formSubmissionField = $this->populateFormSubmissionField($weight, $row_name, $group_index, $field_name, $value,
            $formSubmissionField);
        $this->formSubmissionFields()->save($formSubmissionField);
        return $formSubmissionField;
    }


    /**
     * @param integer $weight
     * @param string $row_name
     * @param integer $group_index
     * @param string $field_name
     * @param $value
     * @param FormSubmissionField $formSubmissionField
     *
     * @return FormSubmissionField
     */
    private function populateFormSubmissionField(
        $weight,
        string $row_name,
        $group_index,
        string $field_name,
        $value,
        FormSubmissionField $formSubmissionField
    ): FormSubmissionField {
        $formSubmissionField->weight = $weight;
        $formSubmissionField->row_name = $row_name;

        if(is_array($value)) {
            foreach ($value as $key => $val) {
                $value[$key] = strip_tags($val);
            }
        } elseif (is_string($value)) {
            $value = strip_tags($value);
        }

        if ($group_index !== null) {
            $formSubmissionField->group_index = $group_index;
        }

        $formSubmissionField->field_name = $field_name;

        // Firstly, nullify all the value types as we don't know if the type has changed since last save
        $formSubmissionField->value = null;
        $formSubmissionField->value_int = null;
        $formSubmissionField->value_date = null;

        // Generate a random number for ordering (used by MSF)
        $formSubmissionField->randomiser = mt_rand(000000, 999999);

        // If the value is a Carbon date, set value_date and return
        if (is_object($value) && get_class($value) == 'Carbon\Carbon') {
            $formSubmissionField->value_date = $value;
            return $formSubmissionField;
        }

        $size = is_object($value) == true ? $value->getSize() : null;

        if (isset($size) and $size > 0) {
            $filename = time() . $value->getClientOriginalName();
            $path = $value->move('private', $filename);
            $formSubmissionField->value = $path;
        } else {

            if (is_array($value) == true) {

                $arrayKeys = implode('', array_keys($value));

                // This is a bit of a strange check to handle associative arrays with numeric, but not sequential keys
                // as handed in by some browsers (Behat) when posting a request with an array of checkbox values.
                if (preg_match('/^[\d]+$/', $arrayKeys)) {
                    $value = json_encode( array_values($value) );
                } else {
                    $value = json_encode($value);
                }
            }

            // If value is only numeric characters, save it in the dedicated integer field
            if (preg_match('/^[0-9]+$/', $value) && strlen($value) < 11) {
                $formSubmissionField->value_int = (int)$value;
                return $formSubmissionField;
            }

            // If the value looks like a date string we're assuming its a date field
            // A future improvement would be to reference the schema at this point to know for sure
            if ($formSubmissionField->value_date = DateStringHelper::FormatIfDateString($value)) {
                $formSubmissionField->value = null;
            } else {
                $formSubmissionField->value = is_null($value) ? null : HTML::encodeAmpersands($value);
            }
        }

        return $formSubmissionField;
    }


    public function deleteField(string $row_name, string $field_name)
    {
        return $this->formSubmissionFields()
            ->where('row_name', $row_name)
            ->where('field_name', $field_name)
            ->limit(1)
            ->delete();
    }

}
