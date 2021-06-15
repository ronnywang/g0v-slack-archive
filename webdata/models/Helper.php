<?php

class Helper
{
    public static function updateEmoji()
    {
        $access_token = getenv('SLACK_ACCESS_TOKEN');
        $url = sprintf('https://slack.com/api/emoji.list?token=%s', urlencode($access_token));
        $obj = json_decode(file_get_contents($url));

        KeyValue::set('emoji', json_encode($obj->emoji));
    }
}
