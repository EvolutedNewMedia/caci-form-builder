<?php

namespace Nomensa\FormBuilder\Model;

use Illuminate\Database\Eloquent\Model;

class FormSubmissionField extends Model
{

    /**
     * @var bool
     */
    public $timestamps = true;

    /**
     * @var array
     */
    protected $guarded = [];

    protected $casts = [
        'value_date' => 'date',
        'updated_at' => 'datetime',
        'created_at' => 'datetime',
    ];
    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function formSubmission()
    {
        return $this->belongsTo('App\FormSubmission');
    }


    public static function FilterEntryFormSubmissionsByFieldDateValue($field_name,$operator,$value, array $form_submission_ids)
    {
        return self::FilterEntryFormSubmissionsByFieldValue($field_name,$operator,$value, $form_submission_ids, true);
    }


    /**
     * Get array of IDs of EntryFormSubmissions based on values
     *
     * @param string $field_name
     * @param string $operator - Example values: '=', 'LIKE', '>', '<', '<>'
     * @param string $value
     * @param array $form_submission_ids
     * @param bool $use_value_date
     *
     * @return array
     */
    public static function FilterEntryFormSubmissionsByFieldValue($field_name,$operator,$value, array $form_submission_ids, $use_value_date = false) : array
    {
        if (count($form_submission_ids)==0) {
            return [];
        }

        $query = self::select('form_submission_id')
            ->where('field_name',$field_name);

        if ($use_value_date) {

            if ($operator == '<>' && $value == '') {
                // Stricter db engines do not treat "more or less than blank string" as "NOT NULL" so we need to convert
                $query->whereNotNull('value_date');
            } else {
                $query->where('value_date', $operator, $value);
            }

        } else if (preg_match('/^[0-9]+$/', $value)) {
            $query->where('value_int', $operator, $value);
        } else {
            $query->where('value', $operator, $value);
        }

        $query->whereIn('form_submission_id', $form_submission_ids);

        return $query->get()->pluck('form_submission_id')->toArray();
    }


    /**
     * Filter Rows by formInstanceId
     *
     * @param $query
     * @param $form_instance_id
     *
     * @return mixed
     */
    public function scopeBelongingToFormInstance($query, $form_instance_id)
    {
        return $query->leftJoin('form_submissions', 'form_submission_id', '=', 'form_submissions.id')
            ->select($this->getTable() . '.*')
            ->where('form_submissions.form_instance_id', $form_instance_id);
    }

}
