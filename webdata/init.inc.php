<?php

error_reporting(E_ALL ^ E_STRICT ^ E_NOTICE);

include(__DIR__ . '/stdlibs/pixframework/Pix/Loader.php');
set_include_path(__DIR__ . '/stdlibs/pixframework/'
    . PATH_SEPARATOR . __DIR__ . '/models'
);

Pix_Loader::registerAutoLoad();

if (file_exists(__DIR__ . '/config.php')) {
    include(__DIR__ . '/config.php');
}
Pix_Table::setLongQueryTime(3);
// TODO: 之後要搭配 geoip
date_default_timezone_set('Asia/Taipei');


if (preg_match('#pgsql://([^:]*):([^@]*)@([^/]*)/(.*)#', strval(getenv('PGSQL_DATABASE_URL')), $matches)) {
    $options = array(
        'host' => $matches[3],
        'user' => $matches[1],
        'password' => $matches[2],
        'dbname' => $matches[4],
    );
    Pix_Table::setDefaultDb(new Pix_Table_Db_Adapter_PgSQL($options));
} else if (preg_match('#mysql://([^:]*):([^@]*)@([^/]*)/(.*)#', strval(getenv('MYSQL_DATABASE_URL')), $matches)) {
    $db = new StdClass;
    $db->host = $matches[3];
    $db->username = $matches[1];
    $db->password = $matches[2];
    $db->dbname = $matches[4];
    $config = new StdClass;
    $config->master = $config->slave = $db;
    Pix_Table::setDefaultDb(new Pix_Table_Db_Adapter_MysqlConf(array($config)));
}
