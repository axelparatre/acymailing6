<?php

class acymqueueClass extends acymClass
{
    /**
     * Get users depending on filters (search, status, pagination)
     *
     * @param $settings
     *
     * @return mixed
     */

    public function getMatchingCampaigns($settings)
    {
        $campaignClass = acym_get('class.campaign');
        $mailStatClass = acym_get('class.mailstat');
        $query = 'FROM #__acym_mail AS mail
                    LEFT JOIN #__acym_queue AS queue ON mail.id = queue.mail_id 
                    LEFT JOIN #__acym_campaign AS campaign ON mail.id = campaign.mail_id ';

        // This query returns an array like "number of mails" => score. cf the equivalent in the list class to understand how it works
        $queryStatus = 'SELECT COUNT(DISTINCT mail.id) AS number, IF(queue.mail_id IS NULL, campaign.active + 2, campaign.active) AS score
                        FROM #__acym_mail AS mail
                        LEFT JOIN #__acym_queue AS queue ON queue.mail_id = mail.id 
                        LEFT JOIN #__acym_campaign AS campaign ON mail.id = campaign.mail_id';

        $filters = [];
        $filters[] = '(campaign.id IS NULL AND queue.mail_id IS NOT NULL) OR (campaign.id IS NOT NULL AND campaign.draft = 0 AND ((queue.mail_id IS NULL AND campaign.sending_type = '.acym_escapeDB($campaignClass::SENDING_TYPE_SCHEDULED).' AND campaign.sent = 0) OR queue.mail_id IS NOT NULL))';

        if (!empty($settings['tag'])) {
            $query .= ' JOIN #__acym_tag AS tag ON mail.id = tag.id_element AND tag.type = "mail" AND tag.name = '.acym_escapeDB($settings['tag']);
            $queryStatus .= ' JOIN #__acym_tag AS tag ON mail.id = tag.id_element AND tag.type = "mail" AND tag.name = '.acym_escapeDB($settings['tag']);
        }

        if (!empty($settings['search'])) {
            $filters[] = 'mail.subject LIKE '.acym_escapeDB('%'.$settings['search'].'%').' OR mail.name LIKE '.acym_escapeDB('%'.$settings['search'].'%');
        }

        if (!empty($filters)) {
            $queryStatus .= ' WHERE ('.implode(') AND (', $filters).')';
        }

        if (!empty($settings['status'])) {
            $allowedStatus = [
                'sending' => 'campaign.active = 1 AND queue.mail_id IS NOT NULL',
                'paused' => 'campaign.active = 0',
                'scheduled' => 'campaign.active = 1 AND queue.mail_id IS NULL',
                'automation' => 'mail.type = '.acym_escapeDB('automation'),
            ];

            if (empty($allowedStatus[$settings['status']])) {
                die('Unauthorized filter: '.$settings['status']);
            }

            $filters[] = $allowedStatus[$settings['status']];
        }

        if (!empty($filters)) {
            $query .= ' WHERE ('.implode(') AND (', $filters).')';
        }

        $queryCount = 'SELECT COUNT(DISTINCT mail.id) '.$query;
        $query .= ' GROUP BY mail.id';

        $query = 'SELECT mail.name, mail.subject, mail.id, campaign.id AS campaign, IF(campaign.sending_date IS NULL, queue.sending_date, campaign.sending_date) AS sending_date, campaign.sending_type, campaign.active, COUNT(queue.mail_id) AS nbqueued '.$query.' ORDER BY queue.sending_date ASC';

        $mailClass = acym_get('class.mail');
        $results['elements'] = $mailClass->decode(acym_loadObjectList($query, '', $settings['offset'], $settings['campaignsPerPage']));
        $results['total'] = acym_loadResult($queryCount);

        // Get the recipients
        foreach ($results['elements'] as $i => $oneCampaign) {
            if (empty($oneCampaign->campaign)) {
                $results['elements'][$i]->iscampaign = false;
                $results['elements'][$i]->lists = acym_translation('ACYM_MAIL_FROM_AUTOMATION_SENT_TO');
                $results['elements'][$i]->recipients = acym_loadResult('SELECT COUNT(*) FROM #__acym_queue WHERE mail_id = '.intval($oneCampaign->id));
            } else {
                $results['elements'][$i]->iscampaign = true;
                $results['elements'][$i]->lists = acym_loadObjectList(
                    'SELECT l.color, l.name , l.id
                    FROM #__acym_list AS l 
                    JOIN #__acym_mail_has_list AS ml ON ml.list_id = l.id 
                    WHERE ml.mail_id = '.intval($oneCampaign->id)
                );
                $results['elements'][$i]->recipients = intval($mailStatClass->getTotalSubscribersByMailId($oneCampaign->id));
            }
        }

        $automationNumber = acym_loadResult('SELECT COUNT(DISTINCT mail.id) FROM #__acym_mail as mail JOIN #__acym_queue AS queue ON mail.id = queue.mail_id WHERE mail.type = '.acym_escapeDB('automation'));

        $elementsPerStatus = acym_loadObjectList($queryStatus.' GROUP BY score', 'score');
        for ($i = 0 ; $i < 4 ; $i++) {
            $elementsPerStatus[$i] = empty($elementsPerStatus[$i]) ? 0 : $elementsPerStatus[$i]->number;
        }

        $results['status'] = [
            'all' => array_sum($elementsPerStatus) + $automationNumber,
            'sending' => $elementsPerStatus[1],
            'paused' => $elementsPerStatus[0] + $elementsPerStatus[2],
            'scheduled' => $elementsPerStatus[3],
            'automation' => $automationNumber,
        ];

        return $results;
    }

    /**
     * Get users depending on filters (search, status, pagination)
     *
     * @param $settings
     *
     * @return mixed
     */
    public function getMatchingResults($settings)
    {
        $query = 'FROM #__acym_queue AS queue 
                    JOIN #__acym_mail AS mail ON mail.id = queue.mail_id 
                    JOIN #__acym_user AS user ON queue.user_id = user.id ';

        $filters = [];

        if (!empty($settings['tag'])) {
            $query .= ' JOIN #__acym_tag AS tag ON queue.mail_id = tag.id_element AND tag.type = "mail" AND tag.name = '.acym_escapeDB($settings['tag']);
        }

        if (!empty($settings['search'])) {
            $filters[] = 'mail.subject LIKE '.acym_escapeDB('%'.$settings['search'].'%').' OR mail.name LIKE '.acym_escapeDB('%'.$settings['search'].'%');
        }

        if (!empty($filters)) {
            $query .= ' WHERE ('.implode(') AND (', $filters).')';
        }

        if (!empty($settings['tag'])) {
            $query .= ' GROUP BY queue.mail_id, queue.user_id';
        }

        $queryCount = 'SELECT COUNT(queue.mail_id) '.$query;
        $query = 'SELECT mail.id, queue.sending_date, mail.name, mail.subject, user.email, user.name AS user_name, queue.user_id, queue.try '.$query.' ORDER BY queue.sending_date ASC';

        $mailClass = acym_get('class.mail');
        $results['elements'] = $mailClass->decode(acym_loadObjectList($query, '', $settings['offset'], $settings['elementsPerPage']));
        $results['total'] = acym_loadResult($queryCount);

        return $results;
    }

    public function scheduleReady()
    {
        $this->messages = [];

        $campaignClass = acym_get('class.campaign');
        $mailClass = acym_get('class.mail');
        $mailReady = $mailClass->decode(
            acym_loadObjectList(
                'SELECT mail.id, campaign.sending_date, mail.name 
            FROM #__acym_campaign AS campaign 
            JOIN #__acym_mail AS mail 
                ON campaign.mail_id = mail.id 
            WHERE campaign.sending_type = '.acym_escapeDB($campaignClass::SENDING_TYPE_SCHEDULED).' 
                AND campaign.draft = 0
                AND campaign.sending_date <= '.acym_escapeDB(acym_date('now', 'Y-m-d H:i:s', false)).'  
                AND campaign.sent = 0',
                'id'
            )
        );


        if (empty($mailReady)) {
            return false;
        }

        $nbQueue = [];

        foreach ($mailReady as $mailid => $mail) {
            $nbQueue[$mailid] = $this->queue($mailid, $mail->sending_date);
            $this->messages[] = acym_translation_sprintf('ACYM_ADDED_QUEUE_SCHEDULE', $nbQueue, '<b>'.$mail->name.'</b>');
        }

        $mailIds = array_keys($mailReady);
        acym_arrayToInteger($mailIds);
        $campaigns = acym_loadObjectList('SELECT id, mail_id FROM #__acym_campaign WHERE mail_id IN ('.implode(',', $mailIds).')');
        $campaignClass = acym_get('class.campaign');
        foreach ($campaigns as $campaign) {
            $campaignClass->send($campaign->id, $nbQueue[$campaign->mail_id]);
        }

        return count($mailReady);
    }

    public function delete($elements)
    {
        if (!is_array($elements)) {
            $elements = [$elements];
        }

        if (empty($elements)) {
            return 0;
        }

        acym_arrayToInteger($elements);

        $query = 'DELETE FROM #__acym_queue WHERE mail_id IN ('.implode(',', $elements).')';
        $result = acym_query($query);

        acym_query('UPDATE #__acym_campaign SET draft = 1, active = 1 WHERE mail_id IN ('.implode(',', $elements).')');

        if (!$result) {
            return false;
        }

        return $result;
    }

    public function deleteOne($elements, $mailId = null)
    {
        if (!is_array($elements)) {
            $elements = [$elements];
        }

        if (empty($elements)) {
            return 0;
        }

        $nbDeleted = 0;
        foreach ($elements as $one) {
            if (strpos($one, '_')) {
                list($mailId, $userId) = explode('_', $one);
            } else {
                $userId = $one;
            }

            $query = 'DELETE FROM #__acym_queue WHERE user_id = '.intval($userId);
            if (!empty($mailId)) {
                $query .= ' AND mail_id = '.intval($mailId);
            }

            $res = acym_query($query);
            if ($res === false) {
                $this->errors[] = acym_getDBError();
            } else {
                $nbDeleted += $res;
            }
        }

        return $res;
    }

    public function getReady($limit, $mailid = 0)
    {
        if (empty($limit)) {
            return [];
        }

        $order = $this->config->get('sendorder');
        if (empty($order)) {
            $order = 'queue.`user_id` ASC';
        } else {
            if ($order == 'rand') {
                $order = 'RAND()';
            } else {
                $ordering = explode(',', $order);
                $order = 'queue.`'.acym_secureDBColumn(trim($ordering[0])).'` '.acym_secureDBColumn(trim($ordering[1]));
            }
        }

        $query = 'SELECT queue.* FROM #__acym_queue AS queue';
        $query .= ' JOIN #__acym_user AS user ON queue.`user_id` = user.`id` ';
        $query .= ' JOIN #__acym_mail AS mail ON queue.`mail_id` = mail.`id` ';
        $query .= ' LEFT JOIN #__acym_campaign AS campaign ON campaign.`mail_id` = mail.`id` ';
        $query .= ' WHERE queue.`sending_date` <= '.acym_escapeDB(acym_date('now', 'Y-m-d H:i:s', false)).' AND (campaign.mail_id IS NULL OR (campaign.`active` = 1 AND campaign.`draft` = 0 AND user.active = 1))';

        if ($this->config->get('require_confirmation', 1) == 1) {
            $query .= ' AND (user.confirmed = 1 OR mail.type = "notification")';
        }

        if (!empty($this->emailtypes)) {
            foreach ($this->emailtypes as &$oneType) {
                $oneType = acym_escapeDB($oneType);
            }
            $query .= ' AND (mail.type = '.implode(' OR mail.type = ', $this->emailtypes).')';
        }
        if (!empty($mailid)) {
            $query .= ' AND queue.`mail_id` = '.intval($mailid);
        }
        $query .= ' ORDER BY queue.`priority` ASC, queue.`sending_date` ASC, '.$order;
        //You can add a "startqueue" parameter to the url so Acy will not load the first e-mails but will start directly with the 300 or 500 or...
        $startqueue = acym_getVar('int', 'startqueue', 0);
        $query .= ' LIMIT '.intval($startqueue).','.intval($limit);
        try {
            $results = acym_loadObjectList($query);
        } catch (Exception $e) {
            $results = null;
        }

        if ($results === null) {
            //We got an issue here... maybe the table is crashed so we will repair it.
            acym_query('REPAIR TABLE #__acym_queue, #__acym_user, #__acym_mail, #__acym_campaign');
        }

        if (empty($results)) {
            return [];
        }

        //We update the first entry from the queue and change its sending_date with +1 so it does not get sent immediately after in case of we had an issue (a time out execution)...
        //That way e-mails which can't be sent will be sent at the end and we will be able to clean the queue and don't care about what's left in the queue any more
        //Also it will avoid the same user to receive messages again and again and again in case of there is a problem
        if (!empty($results)) {
            $firstElementQueued = reset($results);
            acym_query('UPDATE #__acym_queue SET sending_date = DATE_ADD(sending_date, INTERVAL 1 SECOND) WHERE mail_id = '.intval($firstElementQueued->mail_id).' AND user_id = '.intval($firstElementQueued->user_id).' LIMIT 1');
        }

        //We need to load users as well...
        $userIds = [];
        foreach ($results as $oneRes) {
            //intval to make sure we load at least one value...
            $userIds[$oneRes->user_id] = intval($oneRes->user_id);
        }

        //If we find users which have nothing to do in the queue, we will clean the queue table.
        $cleanQueue = false;
        if (!empty($userIds)) {
            $allusers = acym_loadObjectList('SELECT * FROM #__acym_user WHERE id IN ('.implode(',', $userIds).')', 'id');
            foreach ($results as $oneId => $oneRes) {
                //We could not load the users ?
                if (empty($allusers[$oneRes->user_id])) {
                    $cleanQueue = true;
                    continue;
                }
                foreach ($allusers[$oneRes->user_id] as $oneVar => $oneVal) {
                    $results[$oneId]->$oneVar = $oneVal;
                }
            }
        }

        //We clean the queue... in case of we didn't find users in the table which are in the queue table
        if ($cleanQueue) {
            acym_query('DELETE queue.* FROM #__acym_queue AS queue LEFT JOIN #__acym_user AS user ON queue.user_id = user.id WHERE user.id IS NULL');
        }

        return $results;
    }

    public function delayFailed($mailId, $userIds)
    {
        acym_arrayToInteger($userIds);
        if (empty($mailId) || empty($userIds)) {
            return false;
        }

        return acym_query(
            'UPDATE #__acym_queue 
            SET sending_date = DATE_ADD(sending_date, INTERVAL 1 HOUR), try = try +1 
            WHERE mail_id = '.intval($mailId).' 
                AND user_id IN ('.implode(',', $userIds).')'
        );
    }

    public function queue($mailId, $sending_date)
    {
        $priority = $this->config->get('priority_newsletter', 3);

        return acym_query(
            'INSERT IGNORE INTO #__acym_queue 
                SELECT '.intval($mailId).', userlist.user_id, '.acym_escapeDB($sending_date).', '.intval($priority).', 0 
                FROM #__acym_user_has_list AS userlist 
                JOIN #__acym_mail_has_list AS maillist ON userlist.list_id = maillist.list_id 
                WHERE userlist.status = 1 AND maillist.mail_id = '.intval($mailId)
        );
    }

    public function addQueue($userId, $mailId, $sendingDate)
    {
        $priority = $this->config->get('priority_newsletter', 3);

        return acym_query('INSERT IGNORE INTO #__acym_queue VALUES ('.intval($mailId).', '.intval($userId).', '.acym_escapeDB($sendingDate).', '.intval($priority).', 0)');
    }

    public function unpauseCampaign($campaignId, $active)
    {
        if (acym_query('UPDATE #__acym_campaign SET active = '.intval($active).' WHERE id = '.intval($campaignId))) {
            acym_enqueueMessage(acym_translation($active ? 'ACYM_UNPAUSE_CAMPAIGN_SUCCESSFUL' : 'ACYM_PAUSE_CAMPAIGN_SUCCESSFUL'), "success");
        } else {
            acym_enqueueMessage(acym_translation($active ? 'ACYM_UNPAUSE_CAMPAIGN_FAIL' : 'ACYM_PAUSE_CAMPAIGN_FAIL'), "error");
        }
    }

    public function emptyQueue()
    {
        return acym_query('DELETE FROM `#__acym_queue`');
    }

    public function cleanQueue()
    {
        $twoDaysEarlier = acym_date(time() - 172800, 'Y-m-d H:i:s', false);

        $condition = '`user`.`active` = 0';
        if ($this->config->get('require_confirmation', 1) == 1) $condition .= ' OR `user`.`confirmed` = 0';

        return acym_query(
            'DELETE `queue`.* 
            FROM `#__acym_queue` AS `queue` 
            JOIN `#__acym_user` AS `user` ON `queue`.`user_id` = `user`.`id` 
            WHERE ('.$condition.') 
                AND `queue`.`sending_date` < '.acym_escapeDB($twoDaysEarlier)
        );
    }
}