<?php

class ChannelRow extends Pix_Table_Row
{
    public function fetchMessages()
    {
        $data = json_decode($this->data);
        if ($data->is_private) {
            $private_config = json_decode($this->private_config);
            if (!property_exists($private_config, 'access_token')) {
                throw new MyException('no private channel access_token');
            }
            $access_token = $private_config->access_token;
        } else {
            $access_token = getenv('SLACK_ACCESS_TOKEN');
        }
        $api = 'conversations.history';

        $now = time();
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
            $db->query("INSERT INTO message (ts, channel_id, data) VALUES " . implode(',', $terms) . " ON CONFLICT (ts, channel_id) DO UPDATE SET data = excluded.data");
        }
        $this->update(array('last_fetched_at' => $now));
    }

    public function updateChannel($access_token = null)
    {
        $url = sprintf("https://slack.com/api/conversations.info?token=%s&channel=%s",
            urlencode($access_token),
            urlencode($this->id)
        );

        $obj = json_decode(file_get_contents($url));
        if (!property_exists($obj, 'ok') or !$obj->ok) {
            throw new MyException("fail to conversations.info: " . $obj->error);
        }

        $channel = $obj->channel;
        $this->update(array(
            'name' => $channel->name,
            'data' => json_encode($channel),
            'last_updated_at' => time(),
        ));

        if ($channel->is_private) {
            $cursor = null;
            $members = array();

            while (true) {
                $url = sprintf("https://slack.com/api/conversations.members?token=%s&channel=%s&cursor=%s",
                    urlencode($access_token),
                    urlencode($this->id),
                    urlencode($cursor)
                );
                $obj = json_decode(file_get_contents($url));
                if (!property_exists($obj, 'ok') or !$obj->ok) {
                    throw new MyException("fail to conversations.members: " . $obj->error);
                }
                $members = array_merge($members, $obj->members);
                if (!$obj->response_metadata->next_cursor) {
                    break;
                }
                $cursor = $obj->response_metadata->next_cursor;
            }
            $channel_id = $this->id;
            $db = ChannelUser::getDb();
            $db->query("DELETE FROM channel_user WHERE channel_id = " . $db->quoteWithColumn('data', $this->id));
            ChannelUser::bulkInsert(
                array('channel_id', 'user_id'),
                array_map(function($member_id) use ($channel_id) { return array($channel_id, $member_id); }, $members)
            );
        }
    }
}

class Channel extends Pix_Table
{
    public function init()
    {
        $this->_name = 'channel';
        $this->_primary = 'id';
        $this->_rowClass = 'ChannelRow';

        $this->_columns['id'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['name'] = array('type' => 'varchar', 'size' => 64);
        $this->_columns['data'] = array('type' => 'json');
        $this->_columns['last_updated_at'] = array('type' => 'numeric', 'default' => 0);
        $this->_columns['last_fetched_at'] = array('type' => 'numeric', 'default' => 0);
        $this->_columns['private_config'] = array('type' => 'json', 'default' => '{}');

        $this->addIndex('name', array('name'));
    }

    public static function updateChannelData()
    {
        $access_token = getenv('SLACK_ACCESS_TOKEN');
        $cursor = null;
        while (true) {
            $url = sprintf("https://slack.com/api/conversations.list?token=%s&cursor=%s",
                urlencode($access_token),
                urlencode($cursor)
            );

            $obj = json_decode(file_get_contents($url));
            if (!property_exists($obj, 'ok') or !$obj->ok) {
                throw new MyException("fail to conversations.list: " . $obj->error);
            }

            $db = Channel::getDb();
            $terms = array_map(function($channel) use ($db) {
                return sprintf("(%s,%s,%s)",
                    $db->quoteWithColumn('data', $channel->id),
                    $db->quoteWithColumn('data', $channel->name),
                    $db->quoteWithColumn('data', json_encode($channel))
                );
            }, $obj->channels);

            $db->query("INSERT INTO \"channel\" (id, name, data) VALUES " . implode(',', $terms) . " ON CONFLICT (id) DO UPDATE SET name = excluded.name, data = excluded.data");

            if (!$obj->response_metadata->next_cursor) {
                break;
            }
            $cursor = $obj->response_metadata->next_cursor;
        }
    }
}
