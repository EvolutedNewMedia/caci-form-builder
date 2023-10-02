<?php

namespace Nomensa\FormBuilder\Model;

use Illuminate\Database\Eloquent\Model;
use DB;
use Nomensa\FormBuilder\Exceptions\InvalidSchemaException;
use Nomensa\FormBuilder\FormBuilder;
use App\EntryForm;
use File;

class FormVersion extends Model
{

    const BASIC_SCHEMA_JSON = '[' . PHP_EOL . '  {' . PHP_EOL .
        '    "type": "dynamic",' . PHP_EOL .
        '    "rows": [' . PHP_EOL . '    ]' . PHP_EOL .
        '  }' . PHP_EOL . ']' . PHP_EOL;

    const BASIC_OPTIONS_JSON = '{' . PHP_EOL .
        '  "rules": {' . PHP_EOL .
        '    "draft": {},' . PHP_EOL .
        '    "default": {' . PHP_EOL .
        '    }' . PHP_EOL .
        '  }' . PHP_EOL . '}';

    /** @var string Location of form definitions (relative to app folder */
    protected $formDefinitionsFolder = 'FormBuilder/Forms/';

    protected $options_filename = 'options.json';
    protected $schema_filename = 'schema.json';

    private $formInstanceCount;
    private $formSubmissionCount;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * @param EntryForm $entryForm
     *
     * @return FormVersion
     */
    public static function createFirst(EntryForm $entryForm)
    {
        $attributes = [
            'entry_form_id' => $entryForm->id,
            'is_current' => false,
            'version_number' => 1,
        ];

        $formVersion = self::create($attributes);

        $formVersion->setSchema(self::BASIC_SCHEMA_JSON);
        $formVersion->setOptions(self::BASIC_OPTIONS_JSON);
        $formVersion->save();

        return $formVersion;
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function entryForm()
    {
        return $this->belongsTo('App\EntryForm');
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function formInstances()
    {
        return $this->hasMany('App\FormInstance');
    }


    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function formSubmissions()
    {
        return $this->hasMany('App\FormSubmission');
    }


    /**
     * @return int
     */
    public function formInstanceCount() : int
    {
        if (!is_null($this->formInstanceCount)) {
            return $this->formInstanceCount;
        }
        return $this->formInstanceCount = DB::table('form_instances')
            ->where('form_version_id',$this->id)
            ->count();
    }


    /**
     * @return int
     */
    public function formSubmissionCount() : int
    {
        if (!is_null($this->formSubmissionCount)) {
            return $this->formSubmissionCount;
        }
        return $this->formSubmissionCount = $this->formSubmissions()->count();
    }


    public function getVersionNameAttribute() : string
    {
        return 'v' . $this->version_number;
    }


    /**
     * Returns a human-readable string of the updated_at field
     * or blank string if not available.
     *
     * @return string
     */
    public function getUpdatedAtHumanAttribute() : string
    {
        if (is_null($this->updated_at)) {
            return '';
        }

        return $this->updated_at->format('j F Y');
    }


    public function setSchema($schema)
    {
        if (is_array($schema)) {
            $schema = json_encode($schema);
        }
        $this->schema = $schema;
        $this->regenerateHash();
        return $this;
    }


    public function setOptions($options)
    {
        if (is_array($options)) {
            $options = json_encode($options);
        }
        $this->options = $options;
        $this->regenerateHash();
        return $this;
    }


    protected function regenerateHash()
    {
        $this->hash = md5($this->schema . $this->options);
    }


    public function scopeIsCurrent($query)
    {
        return $query->where('is_current',1);
    }


    /**
     * @param $query
     * @param array $entryFormIds
     *
     * @return mixed
     */
    public function scopeByEntryFormIds($query,array $entryFormIds)
    {
        return $query->whereIn('entry_form_id',$entryFormIds);
    }


    public function getFormBuilder() : FormBuilder
    {
        return new FormBuilder($this->getSchema(),$this->getOptions());
    }


    public function getSchema() : array
    {
        $output = $this->getRawSchema();

        $strSchema = $this->modifySchema($output);

        $schema = json_decode($strSchema, true);

        if (is_null($schema)) {
            throw new InvalidSchemaException('Invalid JSON in schema file');
        }

        return $schema;
    }


    public function getRawSchema() : string
    {
        if ($this->file_defined) {
            return $this->getSchemaFromFile();
        } else {
            return $this->schema;
        }
    }


    /**
     * This exists so the developer can override it an do string-replace operations
     * on the saved JSON.
     *
     * @param string $jsonSchema
     *
     * @return string
     */
    protected function modifySchema(string $jsonSchema) : string
    {
        return $jsonSchema;
    }


    /**
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function getOptions() : array
    {
        $output = $this->getRawOptions();

        $options = json_decode($output, true);

        if (is_null($options)) {
            throw new InvalidSchemaException('Invalid JSON in options file');
        }

        return $options;
    }


    public function getRawOptions() : string
    {
        if ($this->file_defined) {
            return $this->getOptionsFromFile();
        } else {
            return $this->options;
        }
    }


    /**
     * Load Default schema if the field isnt present in the db
     *
     * @return string
     *
     * @throws \Nomensa\FormBuilder\Exceptions\InvalidSchemaException
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function getSchemaFromFile() : string
    {
        $output = !empty($this->schema) ? $this->schema : $this->readSchemaFile();

        if (isset($output)) {
            return $output;
        } else {
            throw new InvalidSchemaException('Schema file was empty');
        }
    }


    /**
     * Load Default options if the field isnt present in the db
     *
     * @return string
     *
     * @throws \Nomensa\FormBuilder\Exceptions\InvalidSchemaException
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function getOptionsFromFile() : string
    {
        $output = !empty($this->options) ? $this->options : $this->readOptionsFile();

        if (isset($output)) {
            return $output;
        } else {
            throw new InvalidSchemaException('Options file was empty');
        }
    }


    /**
     * Get Form Schema saved in the file system
     *
     * @return string
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function readSchemaFile()
    {
        return File::get($this->getFormDefinitionFolder() . '/' . $this->schema_filename);
    }


    /**
     * Get Form Options saved in the file system
     *
     * @return string
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function readOptionsFile()
    {
        return File::get($this->getFormDefinitionFolder() . '/' . $this->options_filename);
    }


    /**
     * In most cases this will return '/path/to/laravel/app/FormBuilder/Forms/Your_Form_Name'
     *
     * @return string
     */
    protected function getFormDefinitionFolder() : string
    {
        return app_path(trim($this->formDefinitionsFolder, '/') . '/' . $this->entryForm->slug);
    }

}
