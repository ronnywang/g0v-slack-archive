<?php

class DirectUserRow extends Pix_Table_Row
{
    public function fetchData()
    {
        $data = json_decode($this->data);
        $access_token = $data->access_token;
        $cursor = null;
        while (true) {
            $url = sprintf('https://slack.com/api/conversations.list?token=%s&types=im&cursor=%s',
                urlencode($access_token),
                urlencode($cursor)
            );

            $obj = json_decode(file_get_contents($url));
            foreach ($obj->channels as $channel) {
                $user = $channel->user;
                unset($channel->user);

                try {
                    DirectChannel::insert(array(
                        'id' => $channel->id,
                        'data' => $channel,
                        'last_updated_at' => 0,
                        'last_fetched_at' => 0,
                    ));
                } catch (Pix_Table_DuplicateException $e) {
                }

                try {
                    DirectChannelUser::insert(array(
                        'channel_id' => $channel->id,
                        'user_id' => $this->id,
                    ));
                } catch (Pix_Table_DuplicateException $e) {
                }

                try {
                    DirectChannelUser::insert(array(
                        'channel_id' => $channel->id,
                        'user_id' => $user,
                    ));
                } catch (Pix_Table_DuplicateException $e) {
                }
            }
            if (!$obj->response_metadata->next_cursor) {
                break;
            }
            $cursor = $obj->response_metadata->next_cursor;
        }
    }
}

class DirectUser extends Pix_Table
{
    public function init()
    {
        $this->_name = 'direct_user';
        $this->_primary  = 'id';
        $this->_rowClass = 'DirectUserRow';

        $this->_columns['id'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['created_at'] = array('type' => 'int');
        $this->_columns['data'] = array('type' => 'json');
    }
}
