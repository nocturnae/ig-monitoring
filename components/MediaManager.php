<?php
/**
 * Created for IG Monitoring.
 * User: jakim <pawel@jakimowski.info>
 * Date: 12.01.2018
 */

namespace app\components;


use app\models\Account;
use app\models\Media;
use app\models\MediaAccount;
use app\models\MediaStats;
use app\models\MediaTag;
use app\models\Tag;
use jakim\ig\Text;
use Jakim\Model\Post;
use yii\base\Component;
use yii\base\Exception;

class MediaManager extends Component
{
    /**
     * @var \app\models\Account
     */
    public $account;

    /**
     * @param \app\models\Media $media
     * @param \Jakim\Model\Post $data
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    public function update(Media $media, Post $data)
    {
        $media = $this->updateDetails($media, $data);

        if (!$this->account) {
            $this->account = $media->account;
            $this->account->refresh();
        }

        if ($media->caption) {
            $tags = (array) Text::getTags($media->caption);
            $this->updateTags($media, $tags);

            $usernames = (array) Text::getUsernames($media->caption);
            // ignore owner of media
            ArrayHelper::removeValue($usernames, $this->account->username);
            $this->updateUsernames($media, $usernames);
        }

        $this->updateStats($media, $data);
    }

    /**
     * @param \app\models\Media $media
     * @param \Jakim\Model\Post $data
     * @return \app\models\Media
     * @throws \yii\base\Exception
     */
    public function updateDetails(Media $media, Post $data): Media
    {
        $media->instagram_id = $data->id;
        $media->shortcode = $data->shortcode;
        $media->is_video = $data->isVideo;
        $media->caption = $data->caption;
        $media->taken_at = (new \DateTime('@' . $data->takenAt))->format('Y-m-d H:i:s');

        if (!$media->account_id && $this->account) {
            $media->account_id = $this->account->id;
        }

        if (!$media->save()) {
            throw new Exception(json_encode($media->errors));
        }

        return $media;
    }

    /**
     * @param \app\models\Media $media
     * @param \Jakim\Model\Post $data
     * @return \app\models\MediaStats|null
     */
    public function updateStats(Media $media, Post $data): ?MediaStats
    {
        $account = $media->account ?? $this->account;
        if (empty($account->lastAccountStats)) {
            return null;
        }
        $mediaStats = null;
        if ($this->statsNeedUpdate($media, $data)) {
            $mediaStats = new MediaStats();
            $mediaStats->likes = $data->likes;
            $mediaStats->comments = $data->comments;
            $mediaStats->account_followed_by = $this->account->lastAccountStats->followed_by;
            $mediaStats->account_follows = $this->account->lastAccountStats->follows;
            $media->link('mediaStats', $mediaStats);
        }

        return $mediaStats;
    }

    public function updateUsernames(Media $media, array $usernames)
    {
        $manager = \Yii::createObject(AccountManager::class);
        $manager->saveUsernames($usernames);

        $createdAt = (new \DateTime())->format('Y-m-d H:i:s');
        $rows = array_map(function($id) use ($media, $createdAt) {
            return [
                $media->id,
                $id,
                $createdAt,
            ];
        }, Account::find()
            ->andWhere(['username' => $usernames])
            ->column());

        $sql = \Yii::$app->db->queryBuilder
            ->batchInsert(MediaAccount::tableName(), ['media_id', 'account_id', 'created_at'], $rows);
        $sql = str_replace('INSERT INTO ', 'INSERT IGNORE INTO ', $sql);
        \Yii::$app->db->createCommand($sql)
            ->execute();
    }

    /**
     * @param \app\models\Media $media
     * @param array $tags
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     */
    public function updateTags(Media $media, array $tags)
    {
        $manager = \Yii::createObject(TagManager::class);
        $manager->saveTags($tags);

        $createdAt = (new \DateTime())->format('Y-m-d H:i:s');
        $rows = array_map(function($id) use ($media, $createdAt) {
            return [
                $media->id,
                $id,
                $createdAt,
            ];
        }, Tag::find()
            ->andWhere(['name' => $tags])
            ->column());

        $sql = \Yii::$app->db->queryBuilder
            ->batchInsert(MediaTag::tableName(), ['media_id', 'tag_id', 'created_at'], $rows);
        $sql = str_replace('INSERT INTO ', 'INSERT IGNORE INTO ', $sql);
        \Yii::$app->db->createCommand($sql)
            ->execute();
    }

    /**
     * @param \app\models\Media $media
     * @param \Jakim\Model\Post $data
     * @return bool
     */
    private function statsNeedUpdate(Media $media, Post $data): bool
    {
        if (!$media->lastMediaStats) {
            return true;
        }

        return $media->lastMediaStats->likes != $data->likes ||
            $media->lastMediaStats->comments != $data->comments;
    }
}