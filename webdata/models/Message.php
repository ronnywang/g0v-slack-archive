<?php

class Message extends Pix_Table
{
    public function init()
    {
        $this->_name = 'message';
        $this->_primary = array('channel_id', 'ts');

        $this->_columns['ts'] = array('type' => 'NUMERIC');
        $this->_columns['channel_id'] = array('type' => 'varchar', 'size' => 16);
        $this->_columns['data'] = array('type' => 'json');
    }

    public static function getUserName($user_id)
    {
        if (!$user_id) {
            return '@null';
        }
        if (!$user = User::find($user_id)) {
            return '@' . $user_id;
        }
        $user_data = json_decode($user->data);
        if ($user_data->profile->display_name) {
            return $user_data->profile->display_name;
        }
        return $user_data->name;
    }

    protected static $_emoji = null;
    protected static $_emoji2 = null;

    public static function getEmoji($id)
    {
        self::loadEmoji();

        if (property_exists(self::$_emoji, $id)) {
            $url = self::$_emoji->{$id};
            if (strpos($url, 'alias:') === 0) {
                return self::getEmoji(explode(':', $url))[1];
            }
            return sprintf("<img src=\"%s\" width=\"24\" height=\"24\">", $url);
        }

        if (property_exists(self::$_emoji2, $id)) {
            return self::$_emoji2->{$id};
        }
        return null;
    }

    public static function loadEmoji()
    {
        if (is_null(self::$_emoji)) {
            self::$_emoji = json_decode(KeyValue::get('emoji'));
            self::$_emoji2 = json_decode(file_get_contents(__DIR__ . "/../emojis.json"));
        }
    }

    public static function getHTML($message_data)
    {
        $generated_text = '';
        if (is_scalar($message_data)) {
            $text = $message_data;
        } else {
            $text = $message_data->text;
        }
        $text = str_replace('&amp;', '&', $text);
        while (true) {
            if (strpos($text, '<') === false) {
                $text = str_replace('&lt;', '<', $text);
                $text = str_replace('&gt;', '>', $text);
                $generated_text .= nl2br(htmlspecialchars($text));
                break;
            }
            list($plain_text, $other) = explode('<', $text, 2);
            $plain_text = str_replace('&lt;', '<', $plain_text);
            $plain_text = str_replace('&gt;', '>', $plain_text);
            $generated_text .= nl2br(htmlspecialchars($plain_text));

            list($special_text, $text) = explode('>', $other, 2);
            if ($special_text[0] == '@') {
                if (!$user = User::find(ltrim($special_text, '@'))) {
                    $name = '@' . $matches[1];
                } else {
                    $name = '@' . $user->name;
                }
                $generated_text .= '<b>' . nl2br(htmlspecialchars($name)) . '</b>';
            } elseif (strpos($special_text, 'mailto:') === 0) {
                list($link, $linktext) = explode('|', $special_text, 2);
                $generated_text .= sprintf("<a href=\"%s\">%s</a>", htmlspecialchars($link), htmlspecialchars($linktext));

            } elseif (strpos($special_text, 'http') === 0) {
                if (strpos($special_text, '|') !== false) {
                    list($link, $linktext) = explode('|', $special_text, 2);
                } else {
                    $link = $linktext = $special_text;
                }
                $generated_text .= sprintf("<a href=\"%s\">%s</a>", htmlspecialchars($link), htmlspecialchars($linktext));
            } elseif (preg_match("/^#(.*)\|(.*)$/", $special_text, $matches)) {
                $generated_text .= sprintf("<a href=\"/index/channel/%s\">#%s</a>", htmlspecialchars($matches[1]), htmlspecialchars($matches[2]));
            } else {
                $generated_text .= '&lt;' . $special_text . '&gt;';
            }
        }

        $generated_text = preg_replace_callback('#:([^:]+):#', function($matches) use ($emoji) {
            $id = $matches[1];

            $str = Message::getEmoji($id);
            if (is_null($str)) {
                return ":{$id}:";
            }
            return $str;
        }, $generated_text);
        return $generated_text;
    }
}
