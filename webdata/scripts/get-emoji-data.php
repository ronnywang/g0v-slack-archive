<?php

$url = "https://raw.githubusercontent.com/iamcal/emoji-data/master/emoji.json";
$obj = json_decode(file_get_contents($url));
$output = new StdClass;
foreach ($obj as $emoji) {
    $char = html_entity_decode(implode('', array_map(function($a) { return '&#x' . strtolower($a) . ';'; }, explode('-', $emoji->unified))));
    foreach ($emoji->short_names as $short_name) {
        $output->{$short_name} = $char;
    }
}
file_put_contents(__DIR__ . '/../emojis.json', json_encode($output, JSON_UNESCAPED_UNICODE));
