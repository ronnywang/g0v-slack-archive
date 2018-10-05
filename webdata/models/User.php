<?php

class User extends Pix_Table
{
    public function init()
    {
        $this->_name = 'user';
        $this->_primary = 'id';

        $this->_columns['id'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['name'] = array('type' => 'varchar', 'size' => 64);
        $this->_columns['data'] = array('type' => 'json');
    }

    public static function updateUserData()
    {
        $access_token = getenv('SLACK_ACCESS_TOKEN');
        $cursor = null;
        while (true) {
            $url = sprintf("https://slack.com/api/users.list?token=%s&cursor=%s",
                urlencode($access_token),
                urlencode($cursor)
            );

            $obj = json_decode(file_get_contents($url));
            if (!property_exists($obj, 'ok') or !$obj->ok) {
                throw new MyException("fail to users.list: " . $obj->error);
            }

            $db = User::getDb();
            $terms = array_map(function($member) use ($db) {
                return sprintf("(%s,%s,%s)",
                    $db->quoteWithColumn('data', $member->id),
                    $db->quoteWithColumn('data', $member->name),
                    $db->quoteWithColumn('data', json_encode($member))
                );
            }, $obj->members);
            User::getDb()->query("INSERT INTO \"user\" (id, name, data) VALUES " . implode(',', $terms) . " ON CONFLICT (id) DO UPDATE SET name = excluded.name, data = excluded.data");

            if (!$obj->response_metadata->next_cursor) {
                break;
            }
            $cursor = $obj->response_metadata->next_cursor;
        }
    }
}

