<?php

include(__DIR__ . '/../init.inc.php');

Pix_Table::enableLog(Pix_Table::LOG_QUERY);
User::updateUserData();
Channel::updateChannelData();
foreach (Channel::search(1) as $channel) {
    try {
        $channel->fetchMessages();
    } catch (Exception $e) {
    }
}
