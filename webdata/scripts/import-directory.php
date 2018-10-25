<?php

include(__DIR__ . '/../init.inc.php');

$dir = $_SERVER['argv'][1];
if (!$dir) {
    throw new Exception("Usage: php import-directory.php [slack export directory]");
} else if (!is_dir($dir)) {
    throw new Exception("{$dir} is not a directory");
}

try {
    Message::createTable();
    Channel::createTable();
    User::createTable();
    ChannelUser::createTable();
} catch (Exception $e) {
}

// import channels
if (!file_exists($dir . '/channels.json')) {
    throw new Exception("channels.json not found in {$dir}");
}

$channel_info = json_decode(file_get_contents($dir . '/channels.json'));
$channel_id_to_name = array();
$channel_name_to_id = array();
$db = Message::getDb();

$max_ts = 0;
Message::getDb()->query("CREATE TEMP TABLE message_temp (LIKE message)");
$terms = array();
$c = 0;
foreach ($channel_info as $channel) {
    try {
        $channel_id_to_name[$channel->id] = $channel->name;
        $channel_name_to_id[$channel->name] = $channel->id;

        Channel::insert(array(
            'id' => $channel->id,
            'name' => $channel->name,
            'data' => json_encode($channel),
        ));
    } catch (Pix_Table_DuplicateException $e) {
        if (Channel::find($channel->id)->name != $channel->name) {
            // TODO: 看哪邊新...
            //throw new Exception("id = {$channel->id} 的頻道名稱是 " . Channel::find($channel->id)->name . ", 與 {$channel->name} 不吻合");
        }
    }

    foreach (glob($dir . '/' . $channel->name . '/*.json') as $message_json) {
        $messages = json_decode(file_get_contents($message_json));
        foreach ($messages as $message) {
            $max_ts = max($max_ts, $message->ts);
            $terms[] = sprintf("(%lf,%s,%s)",
                floatval($message->ts),
                $db->quoteWithColumn('data', $channel_name_to_id[$channel->name]),
                $db->quoteWithColumn('data', json_encode($message))
            );
            $c ++;
            if (count($terms) >= 1000) {
                $db->query("INSERT INTO message_temp (ts, channel_id, data) VALUES " . implode(',', $terms));
                $terms = array();
                error_log("insert {$c} records");
            }
        }
    }
}

if (count($terms)) {
    $db->query("INSERT INTO message_temp (ts, channel_id, data) VALUES " . implode(',', $terms));
    $terms = array();
}

$sql = "UPDATE message SET data = message_temp.data FROM message_temp WHERE message.ts = message_temp.ts AND message.channel_id= message_temp.channel_id AND (message.data)::text != (message_temp.data)::text RETURNING message.ts";
$res = $db->query($sql);
$u = 0;
while ($row = $res->fetch_array()) {
    $u ++;
}
error_log("一共變更 {$u} 筆訊息");

$sql = "INSERT INTO message SELECT * FROM message_temp WHERE (ts, channel_id) IN (SELECT ts, channel_id FROM message_temp EXCEPT SELECT ts, channel_id FROM message) RETURNING message.ts";
$res = $db->query($sql);
$insert = 0;
while ($row = $res->fetch_array()) {
    $insert ++;
}

error_log("一共新增 {$insert} 訊息");

foreach ($channel_info as $channel) {
    $c = Channel::find($channel->id);
    if ($max_ts > $c->last_fetched_at) {
        $c->update(array(
            'last_fetched_at' => $max_ts,
            'last_updated_at' => $max_ts,
        ));
    }
}
