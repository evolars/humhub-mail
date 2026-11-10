<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2020 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\mail\controllers\rest;

use humhub\modules\mail\models\forms\CreateMessage;
use humhub\modules\mail\models\Message;
use humhub\modules\mail\permissions\StartConversation;
use humhub\modules\rest\components\BaseController;
use humhub\modules\mail\helpers\RestDefinitions;
use Yii;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;

/**
 * Class MessageController
 */
class MessageController extends BaseController
{
    /**
     * Get list of mail conversations
     *
     * @return array
     */
    public function actionIndex()
    {
        $results = [];
        $messagesQuery = Message::find()
            ->innerJoin('user_message', 'message_id = id')
            ->where(['user_id' => Yii::$app->user->id]);

        $pagination = $this->handlePagination($messagesQuery);
        foreach ($messagesQuery->all() as $message) {
            $results[] = RestDefinitions::getMessage($message);
        }
        return $this->returnPagination($messagesQuery, $pagination, $results);
    }

    /**
     * Evolars: caixa de entrada do usuário, mais recentes primeiro, com participantes,
     * última mensagem e se há algo não lido.
     */
    public function actionInbox()
    {
        $me = Yii::$app->user->id;
        $query = Message::find()
            ->innerJoin('user_message', 'message_id = id')
            ->where(['user_id' => $me])
            ->orderBy(['message.updated_at' => SORT_DESC]);
        $pagination = $this->handlePagination($query);
        $results = [];
        foreach ($query->all() as $message) {
            $last = $message->getLastEntry();
            $um = $message->getUserMessage($me);
            $users = [];
            foreach ($message->getUsers()->all() as $u) {
                $users[] = ['id' => $u->id, 'username' => $u->username, 'display_name' => $u->displayName];
            }
            $results[] = RestDefinitions::getMessage($message) + [
                'users' => $users,
                'unread' => $um ? $um->isUnread() : false,
                'last_entry' => $last ? RestDefinitions::getMessageEntry($last) : null,
            ];
        }
        return $this->returnPagination($query, $pagination, $results);
    }

    /**
     * Evolars: últimas mensagens de uma conversa (ordem cronológica) e marca como lida.
     */
    public function actionRecent($id, $limit = 50)
    {
        $message = static::getMessage($id);
        $entries = $message->getEntries()->orderBy(['id' => SORT_DESC])->limit(min(200, (int) $limit))->all();
        $message->seen(Yii::$app->user->id);
        $users = [];
        foreach ($message->getUsers()->all() as $u) {
            $users[] = ['id' => $u->id, 'username' => $u->username, 'display_name' => $u->displayName];
        }
        return RestDefinitions::getMessage($message) + [
            'users' => $users,
            'entries' => array_map([RestDefinitions::class, 'getMessageEntry'], array_reverse($entries)),
        ];
    }

    /**
     * Get a mail conversation by id
     *
     * @param $id
     * @return array
     * @throws HttpException
     */
    public function actionView($id)
    {
        $message = static::getMessage($id);
        return RestDefinitions::getMessage($message);
    }

    /**
     * Create a mail conversation
     *
     * @return array
     * @throws \Throwable
     */
    public function actionCreate()
    {
        if (!Yii::$app->user->isAdmin() && !Yii::$app->user->getPermissionManager()->can(StartConversation::class)) {
            return $this->returnError(403, 'You cannot create conversations!');
        }

        $message = new CreateMessage();
        $message->load(['CreateMessage' => Yii::$app->request->post()]);

        if ($message->save()) {
            return $this->actionView($message->messageInstance->id);
        }

        if ($message->hasErrors()) {
            return $this->returnError(400, 'Validation failed', $message->getErrors());
        }

        Yii::error('Could not create validated conversation.', 'api');
        return $this->returnError(500, 'Internal error while save conversation!');
    }

    /**
     * Get conversation by id
     *
     * @param $id
     * @return Message
     * @throws HttpException
     */
    public static function getMessage($id)
    {
        $message = Message::findOne(['id' => $id]);
        if ($message === null) {
            throw new HttpException(404, 'Message not found!');
        }

        if (!$message->isParticipant(Yii::$app->user)) {
            throw new ForbiddenHttpException('You must be a participant of the conversation.');
        }

        return $message;
    }
}
