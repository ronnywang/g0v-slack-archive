<?php

class DirectChannelRow extends Pix_Table_Row
{
    public function getName($looking_user)
    {
        $user_ids = DirectChannelUser::search(array('channel_id' => $this->id))->toArray('user_id');
        $users = User::search(1)->searchIn('id', $user_ids);
        $terms = array();
        foreach ($users as $user) {
            if ($user->id == $looking_user->id) {
                continue;
            }
            $terms[] = $user->name;
        }
        return implode(',', $terms);
    }

    public function fetchMessages()
    {
        $data = json_decode($this->data);
        $user_ids = DirectChannelUser::search(array('channel_id' => $this->id))->toArray('user_id');
        if (!count($user_ids)) {
            return;
        }
        $users = DirectUser::search(1)->searchIn('id', $user_ids);
        foreach ($users as $user) {
            $access_token = json_decode($user->data)->access_token;
        }
        $api = 'conversations.info';
        $url = sprintf("https://slack.com/api/{$api}?token=%s&channel=%s",
            urlencode($access_token),
            urlencode($this->id)
        );

        $now = time();
        $max_ts = DirectMessage::search(array('channel_id' => $this->id))->max('ts')->ts;
        $obj = json_decode(file_get_contents($url));
        if (!$obj->channel->latest) {
            $this->update(array('last_fetched_at' => $now));
            return;
        }
        if ($obj->channel->latest->ts <= $max_ts) {
            $this->update(array('last_fetched_at' => $now));
            return;
        }

        $api = 'conversations.history';

        $latest = time();
        $last_fetched_at = $this->last_fetched_at;
        $messages = array();
        while (true) {
            $url = sprintf("https://slack.com/api/{$api}?token=%s&channel=%s&latest=%s",
                urlencode($access_token),
                urlencode($this->id),
                urlencode($latest)
            );

            $obj = json_decode(file_get_contents($url));
            if (!property_exists($obj, 'ok') or !$obj->ok) {
                throw new MyException("fail to conversations.history: " . $obj->error);
            }

            foreach ($obj->messages as $message) {
                if (floatval($message->ts) <= $last_fetched_at - 2 * 86400) {
                    break 2;
                }
                $messages[] = $message;
                $latest = floatval($message->ts);
            }
            if (!$obj->has_more or $obj->is_limited) {
                break;
            }
        }

        $channel_id = $this->id;
        $db = Message::getDb();
        $terms = array_map(function($message) use ($channel_id, $db) {
            return sprintf("(%f,%s,%s)",
                floatval($message->ts),
                $db->quoteWithColumn('data', $channel_id),
                $db->quoteWithColumn('data', json_encode($message))
            );
        }, $messages);

        if ($terms) {
            $db->query("INSERT INTO direct_message (ts, channel_id, data) VALUES " . implode(',', $terms) . " ON CONFLICT (ts, channel_id) DO UPDATE SET data = excluded.data");
        }
        $this->update(array('last_fetched_at' => $now));
    }
}

class DirectChannel extends Pix_Table
{
    public function init()
    {
        $this->_name = 'direct_channel';
        $this->_primary = 'id';
        $this->_rowClass = 'DirectChannelRow';

        $this->_columns['id'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['data'] = array('type' => 'json');
        $this->_columns['last_updated_at'] = array('type' => 'numeric', 'default' => 0);
        $this->_columns['last_fetched_at'] = array('type' => 'numeric', 'default' => 0);
    }
}
