<?php
/**
 * @author: Pan Wenbin <panwenbin@gmail.com>
 */

namespace common\models;


use common\models\gii\PostGii;
use yii\behaviors\TimestampBehavior;
use yii\helpers\ArrayHelper;

/**
 * Class Post
 * @package common\models
 * @property User $user
 * @property Post $nextPost
 * @property Post $prevPost
 * @property Post[] $archives
 * @property Post $latest
 * @property PostTagRelation[] $postTagRelations
 * @property Tag[] $tags
 */
class Post extends PostGii
{
    protected $tagNames;

    public function behaviors()
    {
        return [
            TimestampBehavior::className(),
        ];
    }

    public function rules()
    {
        return array_merge(parent::rules(), [
            ['tagNames', 'safe'],
        ]);
    }

    public function getTagNames()
    {
        if ($this->tagNames) return $this->tagNames;

        return ArrayHelper::getColumn($this->tags, 'name');
    }

    public function setTagNames($tagNames)
    {
        $this->tagNames = $tagNames;
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        /**
         * 处理tag
         */
        if ($this->tagNames) {
            $tagNames = $this->tagNames;

            /* 对比出要创建的标签 */
            $existsTags = Tag::findAll(['name' => $tagNames]);
            $existsTagNames = ArrayHelper::getColumn($existsTags, 'name');
            $createTagNames = array_udiff($tagNames, $existsTagNames, function ($tagName, $existsTagName) {
                return strcmp(strtolower($tagName), strtolower($existsTagName)); // 数据库不区分大小写
            });

            /* @var $createdTags Tag[] 创建的新标签 */
            $createdTags = [];
            foreach ($createTagNames as $createTagName) {
                $createTag = new Tag();
                $createTag->name = $createTagName;
                $createTag->save();
                $createTag->setIsNewRecord(false); // link需要
                $createdTags[] = $createTag;
            }
            /* @var $newTags Tag[] 更新后的标签 */
            $newTags = array_merge($existsTags, $createdTags);
            $newTagIds = ArrayHelper::getColumn($newTags, 'id');

            /* @var $oldTags Tag[] 更新前的标签 */
            $oldTags = $this->tags;
            $oldTagIds = ArrayHelper::getColumn($oldTags, 'id');

            /* 删除标签关系 */
            foreach ($oldTags as $oldTag) {
                if (in_array($oldTag->id, $newTagIds) == false) {
                    $this->unlink('tags', $oldTag, true);
                }
            }

            /* 添加标签关系 */
            foreach ($newTags as $createTag) {
                if (in_array($createTag->id, $oldTagIds) == false) {
                    $this->link('tags', $createTag);
                }
            }
        }
    }

    /**
     * 获取发布本日志的用户
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::className(), ['id' => 'user_id']);
    }

    /**
     * @return PostQuery
     */
    public function getNextPost()
    {
        return self::find()->andWhere(['>', 'created_at', $this->created_at])->andWhere(['archive_of_id' => null])->orderBy('created_at ASC')->limit(1);
    }

    /**
     * @return PostQuery
     */
    public function getPrevPost()
    {
        return self::find()->andWhere(['<', 'created_at', $this->created_at])->andWhere(['archive_of_id' => null])->orderBy('created_at DESC')->limit(1);
    }

    /**
     * 获取此新版日志的旧版存档列表
     * @return \yii\db\ActiveQuery
     */
    public function getArchives()
    {
        return $this->hasMany(Post::className(), ['archive_of_id' => 'id'])->inverseOf('latest');
    }

    /**
     * 获取此存档日志的最新版本日志
     * @return \yii\db\ActiveQuery
     */
    public function getLatest()
    {
        return $this->hasOne(Post::className(), ['id' => 'archive_of_id'])->inverseOf('archives');
    }

    /**
     * 获取此日志与标签的关系列表
     * @return \yii\db\ActiveQuery
     */
    public function getPostTagRelations()
    {
        return $this->hasMany(PostTagRelation::className(), ['post_id' => 'id']);
    }

    /**
     * 获取此日志关联的标签列表
     * @return \yii\db\ActiveQuery
     */
    public function getTags()
    {
        return $this->hasMany(Tag::className(), ['id' => 'tag_id'])->via('postTagRelations');
    }

    /**
     * @inheritdoc
     * @return PostQuery the active query used by this AR class.
     */
    public static function find()
    {
        return new PostQuery(get_called_class());
    }
}