<?php

/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace mirocow\queue\drivers;

use mirocow\queue\drivers\common\BaseConnection;
use yii\db\Connection;
use yii\helpers\Json;
use yii\mutex\Mutex;

/**
 * MysqlConnection Driver
 *
 * @author Mitrofanov Nikolay <mitrofanovnk@gmail.com>
 */
class MysqlConnection extends BaseConnection
{

    /**
     * @var Mutex|array|string
     */
    public $mutex = 'mutex';

    /**
     * @var int timeout
     */
    public $mutexTimeout = 3;

    /**
     * @var null
     */
    public $connection = null;

    /**
     * @var string
     */
    private $tableName = '{{%qwm_queue}}';

    /**
     * @return string
     */
    public function getTableName()
    {
        return $this->tableName;
    }

    /**
     * @param $tableName
     */
    public function setTableName(string $tableName)
    {
        $this->tableName = $tableName;
    }

    /**
     *
     */
    function init()
    {
        parent::init();

        $this->connection = \yii\di\Instance::ensure(
          \Yii::$app->{$this->connection},
          Connection::className()
        );

        $this->connection = \yii\di\Instance::ensure(
          $this->mutex,
          Mutex::className()
        );
    }

    /**
     * @param string $queueName
     * @param int $timeout
     * @return bool
     */
    public function pop(string $queueName, $timeout = 0)
    {
        if (!$this->mutex->acquire(__CLASS__ . $queueName, $this->mutexTimeout)) {
            throw new Exception("Has not waited the lock.");
        }

        $transaction = $this->connection->beginTransaction();

        $message = (new \yii\db\Query())
            ->select('*')
            ->from($this->getTableName())
            ->where(['status' => self::STATUS_NEW, 'queue' => $queueName])
            ->limit(1)
            ->one($this->connection);

        if (!$message || $this->connection->createCommand()
                ->update(
                    $this->tableName,
                    ['status' => self::STATUS_DONE],
                    [
                        'id' => $message['id']
                    ]
                )->execute() !== 1
        ) {
            $transaction->rollBack();
            return false;
        };
        $transaction->commit();

        $this->mutex->release(__CLASS__ . $queueName);

        return $message['message'];
    }

    /**
     * @param string $payload
     * @param string $queueName
     * @param int $delay
     * @param null $priority
     * @return mixed
     */
    public function push(string $payload, string $queueName, $delay = 0, $priority = NULL)
    {
        if ($delay !== null) {
            throw new NotSupportedException('Job delay is not supported in the driver.');
        }

        if ($priority !== null) {
            throw new NotSupportedException('Job priority is not supported in the driver.');
        }

        return $this->connection->createCommand()->insert($this->getTableName(), [
            'message' => $payload,
            'queue' => $queueName
        ])->execute();
    }

    /**
     * @param string $queueName
     * @return mixed
     */
    public function purge(string $queueName)
    {
        return $this->connection->createCommand()->update($this->getTableName(), [
            'status' => self::STATUS_DELETE
        ], ['queue' => $queueName]);
    }

    /**
     * @param array $message
     * @return bool
     */
    public function delete(array $message)
    {
        if (!empty($message)) {
            return $this->connection->createCommand()->update($this->getTableName(), [
                'status' => self::STATUS_DELETE
            ], ['id' => $message['id']]);
        }
        return false;
    }

    /**
     * @param array $message
     * @param int $delay
     */
    public function release(array $message, $delay = 0)
    {

    }

    /**
     * @param string $queueName
     */
    public function status(string $queueName){

    }

}
