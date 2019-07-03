<?php

namespace Nwogu\SmoothMigration\Helpers;

use Illuminate\Support\Str;
use const Nwogu\SmoothMigration\Helpers\TABLE_RENAME_ACTION;
use const Nwogu\SmoothMigration\Helpers\DEF_CHANGE_ACTION;
use const Nwogu\SmoothMigration\Helpers\COLUMN_RENAME_ACTION;
use const Nwogu\SmoothMigration\Helpers\COLUMN_ADD_ACTION;
use const Nwogu\SmoothMigration\Helpers\COLUMN_DROP_ACTION;
use const Nwogu\SmoothMigration\Helpers\FOREIGN_DROP_ACTION;

class SchemaReader
{
    /**
     * Previous Schemas
     * @var array
     */
    protected $previousSchemas = [];

    /**
     * Previous Table
     * @var string
     */
    protected $previousTable;

    /**
     * Current Table
     * @var string
     */
    protected $currentTable;

    /**
     * Current Schemas
     * @var array
     */
    protected $currentSchemas = [];

    /**
     * Change Logs
     * @var array
     */
    protected $changelogs = [];

    /**
     * Table Rename Changes
     * @var array
     */
    protected $tableRenames = [];

    /**
     * Column Rename Changes
     * @var array
     */
    protected $columnRenames = [];

    /**
     * Column Drop Changes
     * @var array
     */
    protected $columnDrops = [];

    /**
     * Column Add Changes
     * @var array
     */
    protected $columnAdds = [];

     /**
     * Column Defination Changes
     * @var array
     */
    protected $defChanges = [];

    /**
     * Drop Foreign Key Changes
     * @var array
     */
    protected $dropForeigns = [];

    /**
     * Check if schema has changed
     * @var bool
     */
    protected $hasChanged = false;

    /**
     * Previous Columns
     * @var array
     */
    protected $previousColumns = [];

    /**
     * Current Columns
     * @var array
     */
    protected $currentColumns = [];

    /**
     * Previous Load
     * @var array
     */
    protected $previousLoad = [];

    /**
     * Current Load
     * @var array
     */
    protected $currentLoad = [];

    /**
     * Construct
     * @param array $previousSchemaLoad
     * @param array $currentSchemaLoad
     */
    public function __construct(array $previousSchemaLoad, array $currentSchemaLoad)
    {
        $this->previousTable = $previousSchemaLoad["table"];
        $this->currentTable = $currentSchemaLoad["table"];
        $this->previousLoad = $previousSchemaLoad["schemas"];
        $this->currentLoad = $currentSchemaLoad["schemas"];
        $this->previousSchemas = array_values($previousSchemaLoad["schemas"]);
        $this->currentSchemas = array_values($currentSchemaLoad["schemas"]);
        $this->previousColumns = array_keys($previousSchemaLoad["schemas"]);
        $this->currentColumns = array_keys($currentSchemaLoad["schemas"]);
        $this->read();
    }

    /**
     * Read schema and checks for changes
     * @return void
     */
    protected function read()
    {
        if ($this->previousTable != $this->currentTable) {
            $this->pushChange(TABLE_RENAME_ACTION, [
                $this->previousTable,
                $this->currentTable
            ]);
        }
        if (($previous = count($this->previousColumns)) != 
            ($current = count($this->currentColumns))) {
            return $this->readByColumnDifference($previous, $current);
        }
        $this->readByColumn();
    }

    /**
     * Read Schema By Columns
     * @return void
     */
    protected function readByColumn($index = 0)
    {
        if ($index < count($this->previousColumns)) {
            if ($this->previousColumns[$index] != $this->currentColumns[$index]) {
                $this->pushChanges(COLUMN_RENAME_ACTION, [
                    $this->previousColumns[$index],
                    $this->currentColumns[$index]
                ]);
            }
            $this->readByColumn($index++);
        }
        $this->readBySchema();
    }

    /**
     * Read Schema by Schema
     * @return void
     */
    protected function readBySchema($index = 0)
    {
        if ($index < count($this->previousSchemas)) {
            $previousSchemaArray = $this->schemaToArray($this->previousSchemas[$index]);
            $currentSchemaArray = $this->schemaToArray($this->currentSchemas[$index]);
            if ($this->schemaisDifferent($previousSchemaArray, $currentSchemaArray)) {
                $this->pushChanges(DEF_CHANGE_ACTION, [
                    $index, $previousSchemaArray, $currentSchemaArray
                ]);
            }
            $this->readBySchema($index++);

        }
    }

    /**
     * Checks if schema is different
     * @param array $previous
     * @param array $current
     * @return bool
     */
    protected function schemaIsDifferent($previous, $current)
    {
        if (empty(array_diff($previous, $current)) && 
                empty(array_diff($current, $previous))) {
                return false;
            }

        return true;
    }

    /**
     * Read By Column when previous and current column
     * count do not match
     * @param int $previousCount
     * @param int $currentCount
     * @return void
     */
    protected function readByColumnDifference($previousCount, $currentCount)
    {
        $shouldDropColumn = function ($previous, $current) {
            return $previous > $current;
        };

        if ($shouldDropColumn($previousCount, $currentCount)) {
            $this->pushChanges(COLUMN_DROP_ACTION, array_diff(
                $this->previousColumns, $this->currentColumns));
        } else {
            $this->pushChanges(COLUMN_ADD_ACTION, array_diff(
                $this->currentColumns, $this->previousColumns
            ));
        }

        return $this->readBySchemaDifference();

    }

    /**
     * Read Schema of Columns when count do not match
     * @return void
     */
    protected function readBySchemaDifference()
    {
        $unchangedColumns = array_intersect(
            $this->previousColumns, $this->currentColumns);

        foreach ($unchangedColumns as $column) {
            $previousSchemaArray = $this->schemaToArray(
                $this->previousLoad[$column]);
            $currentSchemaArray = $this->schemaToArray(
                $this->currentLoad[$column]);
            $index = array_search($column, $this->currentColumns);
            if ($this->schemaisDifferent($previousSchemaArray, $currentSchemaArray)) {
                $this->pushChanges(DEF_CHANGE_ACTION, [
                    $index, $previousSchemaArray, $currentSchemaArray
                ]);
            }
        }
    }

    /**
     * Get Array Representation of stringed Schema
     * @param string $schema
     * @return array
     */
    protected function schemaToArray($schema)
    {
        $hasForeign = function ($schema) {
            return strpos($schema, "on=");
        };

        $hasReference = function ($schema) {
            return strpos($schema, "references=");
        };

        $hasOptions = function ($schema) {
            return strpos($schema, "=");
        };

        $getOptions = function ($schema) use ($hasOptions){
            if ($index = $hasOptions($schema)) {

                $options = explode(" ", trim(\substr($schema, $index + 1)));

                $method = trim(\substr($schema, 0, $index));

                array_push($options, $method);

                return $options;
            }
            return [trim($schema)];
        };

        $arrayedSchema = explode("," , $schema);

        $finalSchema = [];

        if ($hasForeign($schema)) {
            if (! $hasReference($schema)) {
                array_push($arrayedSchema, "references=id");
            }
        }

        foreach ($arrayedSchema as $arraySchema) {
            $finalSchema = array_merge_recursive($finalSchema, $getOptions($arraySchema));
        }

        return $finalSchema;
    }

    /**
     * Reads Change value
     * @return bool
     */
    public function hasChanged()
    {
        return ! empty($this->changelogs);
    }

    /**
     * Pushes Change to Change Holders
     * @param string $action
     * @param array $affected
     */
    protected function pushChanges($action, $affected)
    {
        $method = "push" . Str::studly($action) . "Action";

        if (method_exists($this, $method)) {
            $this->$method($affected);
        }
        throw new \Exception("Schema Read Method {$action} not supported");
    }

    /**
     * Push Table Rename Action
     * @param array $affected
     */
    protected function pushTableRenameAction($affected = [])
    {
        if (empty($affected)) return;

        array_push($this->tableRenames, $affected[1]);

        $changelog = "Table renamed from ". $affected[0] . " to " . $affected[1];

        array_push($this->changelogs, $changelog);
    }

    /**
     * Push Column Rename Action
     * @param array $affected
     */
    protected function pushColumnRenameAction($affected = [])
    {
        if (empty($affected)) return;

        $this->columnRenames[$affected[0]] = $affected[1];

        $changelog = "Column renamed from ". $affected[0] . " to " . $affected[1];

        array_push($this->changelogs, $changelog);
    }

    /**
     * Push Column Defination Changes
     * @param array $index
     */
    protected function pushDefChangeAction($affected = [])
    {
        if (empty($affected)) return;

        $shouldDropForeign = function ($previous, $current) {
            if (in_array("on", $previous) && !in_array("on", $current)) {
                return true;
            }
            return false;
        };

        $column = $this->previousColumns[$affected[0]];

        if ($shouldDropForeign($affected[1], $affected[2])) {
            return $this->pushChanges(FOREIGN_DROP_ACTION, [
                $affected[0],
                $column
            ]);
        }

        array_push($this->defChanges, $affected[0]);

        $changelog = "Column '{$column}' schema altered";

        array_push($this->changelogs, $changelog);
    }

    /**
     * Push Foreign Drop Action
     * @param array $affected
     */
    protected function pushDropForeignAction($affected = [])
    {
        if (empty($affected)) return;

        array_push($this->dropForeigns, $affected[0]);

        $changelog = "Foreign Key dropped on ". $affected[1];

        array_push($this->changelogs, $changelog);
    }

    /**
     * Push Column Drop Changes
     * @param array $affected
     */
    protected function pushColumnDropAction($affected = [])
    {
        if (empty($affected)) return;

        $this->columnDrops = array_merge($this->columnDrops, $affected);

        $changelog = count($this->columnDrops) . "Column(s) Dropped";

        array_push($this->changelogs, $changelog); 
    }

    /**
     * Push Column Add Changes
     * @param array $affected
     */
    protected function pushColumnAddAction($affected = [])
    {
        if (empty($affected)) return;

        $this->columnAdds = array_merge($this->columnDrops, $affected);

        $changelog = count($this->columnDrops) . "Column(s) Added";

        array_push($this->changelogs, $changelog); 
    }

}