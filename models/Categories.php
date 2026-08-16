<?php

namespace app\models;

use Yii;

/**
 * This is the model class for table "categories".
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $tag
 * @property string|null $remarks
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string|null $date_created
 * @property string|null $date_updated
 */
class Categories extends \yii\db\ActiveRecord
{


    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'categories';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['name', 'tag', 'remarks', 'created_by', 'updated_by', 'date_created', 'date_updated'], 'default', 'value' => null],
            [['name', 'tag', 'remarks'], 'string'],
            [['created_by', 'updated_by'], 'integer'],
            [['date_created', 'date_updated'], 'safe'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => 'ID',
            'name' => 'Name',
            'tag' => 'Tag',
            'remarks' => 'Remarks',
            'created_by' => 'Created By',
            'updated_by' => 'Updated By',
            'date_created' => 'Date Created',
            'date_updated' => 'Date Updated',
        ];
    }

}
