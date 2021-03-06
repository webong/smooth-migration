<?php

namespace Nwogu\SmoothMigration\Helpers;

use Illuminate\Support\Str;
use Nwogu\SmoothMigration\Helpers\Constants;

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
     * Drop Morph Changes
     * @var array
     */
    protected $dropMorphs = [];

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
     * Drop Primary Key Changes
     * @var array
     */
    protected $dropPrimaries = [];

    /**
     * Drop Unique Key Changes
     * @var array
     */
    protected $dropUniques = [];

    /**
     * Drop Index Key Changes
     * @var array
     */
    protected $dropIndices = [];

    /**
     * Add Foreign Key Changes
     * @var array
     */
    protected $addForeigns = [];

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
            $this->pushChange(Constants::TABLE_RENAME_UP_ACTION, [
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
                $this->pushChanges(Constants::COLUMN_RENAME_UP_ACTION, [
                    $this->previousColumns[$index],
                    $this->currentColumns[$index]
                ]);
            }
            return $this->readByColumn($index = $index + 1);
        }
        return $this->readBySchema();
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
                $this->pushChanges(Constants::DEF_CHANGE_UP_ACTION, [
                    $index, $previousSchemaArray, $currentSchemaArray
                ]);
            }
            return $this->readBySchema($index = $index + 1);

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
        return ! ($previous == $current);
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
            $this->pushChanges(Constants::COLUMN_DROP_UP_ACTION, array_diff(
                $this->previousColumns, $this->currentColumns));
        } else {
            $this->pushChanges(Constants::COLUMN_ADD_UP_ACTION, array_diff(
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
                $this->pushChanges(Constants::DEF_CHANGE_UP_ACTION, [
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
            return strpos($schema, "on:");
        };

        $hasReference = function ($schema) {
            return strpos($schema, "references:");
        };

        $hasOptions = function ($schema) {
            return strpos($schema, ":");
        };

        $getOptions = function ($schema) use ($hasOptions){
            if ($index = $hasOptions($schema)) {

                $options = explode(",", trim(\substr($schema, $index + 1)));

                $method = trim(\substr($schema, 0, $index));

                array_push($options, $method);

                return $options;
            }
            return [trim($schema)];
        };

        $arrayedSchema = explode("|" , $schema);

        $finalSchema = [];

        if ($hasForeign($schema)) {
            if (! $hasReference($schema)) {
                array_push($arrayedSchema, "references:id");
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
            return $this->$method($affected);
        }
        throw new \Exception("Schema Read Method {$action} not supported");
    }

    /**
     * Push Table Rename Action
     * @param array $affected
     */
    protected function pushTableRenameUpAction($affected = [])
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
    protected function pushColumnRenameUpAction($affected = [])
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
    protected function pushDefChangeUpAction($affected = [])
    {
        if (empty($affected)) return;

        $shouldDrop = function ($previous, $current, $value = "on") {
            if (in_array($value, $previous) && !in_array($value, $current)) {
                return true;
            }
            return in_array("on", $previous) && in_array("on", $current);
        };

        $shouldAddForeign = function ($previous, $current) {
            if (!in_array("on", $previous) && in_array("on", $current)) {
                return true;
            }
            return in_array("on", $previous) && in_array("on", $current);
        };

        $column = $this->currentColumns[$affected[0]];
        $previousColumn = $this->previousColumns[$affected[0]];

        if ($shouldDrop($affected[1], $affected[2])) {
            $this->pushChanges(Constants::FOREIGN_DROP_UP_ACTION, [
                $column
            ]);
        }

        if ($shouldDrop($affected[1], $affected[2], "primary")) {
            $this->pushChanges(Constants::DROP_PRIMARY_UP_ACTION,[
                $column]);
        }

        if ($shouldDrop($affected[1], $affected[2], "unique")) {
            $this->pushChanges(Constants::DROP_UNIQUE_UP_ACTION, [
                $column]);
        }

        if ($shouldDrop($affected[1], $affected[2], "index")) {
            $this->pushChanges(Constants::DROP_INDEX_UP_ACTION,[
                $column]);
        }

        if ($shouldAddForeign($affected[1], $affected[2])) {
            $this->pushChanges(Constants::FOREIGN_ADD_UP_ACTION, [
                $column]);
        }

        $this->defChanges[$previousColumn] = $column;
        
        $changelog = "Column '{$column}' schema altered";

        array_push($this->changelogs, $changelog);
    }

    /**
     * Push Primary Drop Action
     * @param array $affected
     */
    protected function pushDropPrimaryUpAction($affected = [])
    {
        if (empty($affected)) return;

        array_push($this->dropPrimaries, $affected[0]);

        $changelog = "Primary Key Dropped on ". $affected[0];

        array_push($this->changelogs, $changelog);
    }

    /**
     * Push Unique Drop Action
     * @param array $affected
     */
    protected function pushDropUniqueUpAction($affected = [])
    {
        if (empty($affected)) return;

        array_push($this->dropUniques, $affected[0]);

        $changelog = "Unique Key Dropped on ". $affected[0];

        array_push($this->changelogs, $changelog);
    }

    /**
     * Push Index Drop Action
     * @param array $affected
     */
    protected function pushDropIndexUpAction($affected = [])
    {
        if (empty($affected)) return;

        array_push($this->dropIndices, $affected[0]);

        $changelog = "Index Key Dropped on ". $affected[0];

        array_push($this->changelogs, $changelog);
    }

    /**
     * Push Foreign Drop Action
     * @param array $affected
     */
    protected function pushAddForeignUpAction($affected = [])
    {
        if (empty($affected)) return;

        array_push($this->addForeigns, $affected[0]);

        $changelog = "Foreign Key added on ". $affected[0];

        array_push($this->changelogs, $changelog);
    }

     /**
     * Push Foreign Drop Action
     * @param array $affected
     */
    protected function pushDropForeignUpAction($affected = [])
    {
        if (empty($affected)) return;

        array_push($this->dropForeigns, $affected[0]);

        $changelog = "Foreign Key dropped on ". $affected[0];

        array_push($this->changelogs, $changelog);
    }

    /**
     * Push polymorphic relationship drop action
     * @param array $affected
     */
    protected function pushDropMorphUpAction($affected = [])
    {
        if (empty($affected)) return;

        array_push($this->dropMorphs, $affected[0]);

        $changelog = "Morphable Relationship dropped on ". $affected[0];

        array_push($this->changelogs, $changelog);
    }

    /**
     * Push Column Drop Changes
     * @param array $affected
     */
    protected function pushColumnDropUpAction($affected = [])
    {
        if (empty($affected)) return;

        $this->shouldPushDropMorph($affected);

        $withoutMorphs = array_filter($affected, function($column){
            return !in_array($column, $this->dropMorphs());
        });

        $this->columnDrops = array_merge($this->columnDrops, $withoutMorphs);

        $changelog = count($this->columnDrops) . " Column(s) Dropped";

        array_push($this->changelogs, $changelog); 
    }

    /**
     * Push Column Add Changes
     * @param array $affected
     */
    protected function pushColumnAddUpAction($affected = [])
    {
        if (empty($affected)) return;

        $this->shouldPushForeign($affected, "currentLoad");

        $this->columnAdds = array_merge($this->columnAdds, $affected);

        $changelog = count($this->columnAdds) . " Column(s) Added";

        array_push($this->changelogs, $changelog); 
    }

    /**
     * Checks whether to push to foreign keys
     * @param array
     * @param bool
     */
    protected function shouldPushForeign(array $affected, $load, $addForeign = true)
    {
        foreach ($affected as $column) {
            $arrayed = $this->schemaToArray(
                $this->$load[$column] );
            if (in_array("on", $arrayed) && $addForeign) {
                $this->pushChanges(
                    Constants::FOREIGN_ADD_UP_ACTION, [$column]);
            } else if (in_array("on", $arrayed) && !$addForeign) {
                $this->pushChanges(
                    Constants::FOREIGN_DROP_UP_ACTION, [$column]);
            }
        }
    }

    /**
     * Checks whether to push to drop morphs
     * @param array
     * @return void
     */
    protected function shouldPushDropMorph(array $affected)
    {
        foreach ($affected as $column) {
            $arrayed = $this->schemaToArray(
                $this->previousLoad[$column] );
            if (in_array("morphs", $arrayed) || in_array("nullableMorphs", $arrayed)) {
                $this->pushChanges(
                    Constants::DROP_MORPH_UP_ACTION, [$column]);
            }
        }
    }

    /**
     * Checks whether foreign key methods has changed
     */
    protected function foreignKeySchemaHasChanged($previous, $current)
    {
        if (in_array("on", $previous) && in_array("on", $current)) {
            if (array_diff($previous, $current) || array_diff($current, $previous)) {
                return true;
            }
        }
        return false;
    }


    /**
     * Return Change Logs
     * @return array
     */
    public function changelogs()
    {
        return $this->changelogs;
    }

    /**
     * Table Rename Changes
     * @return array
     */
    public function tableRenames()
    {
        return $this->tableRenames;
    } 

    /**
     * Column Rename Changes
     * @return array
     */
    public function columnRenames()
    {
        return $this->columnRenames;
    } 

    /**
     * Column Drop Changes
     * @return array
     */
    public function columnDrops()
    {
        return $this->columnDrops;
    } 

    /**
     * Column Add Changes
     * @return array
     */
    public function columnAdds()
    {
        return $this->columnAdds;
    } 

     /**
     * Column Defination Changes
     * @return array
     */
    public function defChanges()
    {
        return $this->defChanges;
    } 

    /**
     * Drop Foreign Key Changes
     * @return array
     */
    public function dropForeigns()
    {
        return $this->dropForeigns;
    }

    /**
     * Previoustable
     * @return array
     */
    public function previousTable()
    {
        return $this->previousTable;
    }

    /**
     * Currenttable
     * @return array
     */
    public function currentTable()
    {
        return $this->currentTable;
    }

    /**
     * Previousload
     * @return array
     */
    public function previousLoad()
    {
        return $this->previousLoad;
    }

    /**
     * Currentload
     * @return array
     */
    public function currentLoad()
    {
        return $this->currentLoad;
    }

    /**
     * Add Foreign Key Changes
     * @return array
     */
    public function addForeigns()
    {
        return $this->addForeigns;
    }

    /**
     * Drop Primary Key Changes
     * @return array
     */
    public function dropPrimaries()
    {
        return $this->dropPrimaries;
    }

    /**
     * Drop Unique Key Changes
     * @return array
     */
    public function dropUniques()
    {
        return $this->dropUniques;
    }

    /**
     * Drop Index Key Changes
     * @return array
     */
    public function dropIndices()
    {
        return $this->dropIndices;
    }

    /**
     * Drop Morph Changes
     * @return array
     */
    public function dropMorphs()
    {
        return $this->dropMorphs;
    }

}