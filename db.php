<?php

$mysqli = new mysqli(MYSQL_SERVER, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DB_NAME);

if ($mysqli->connect_errno) {
    echo "Не удалось подключиться к MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
}

function get_configs()
{
    global $mysqli;

    $configs = array();
    if ($results = $mysqli -> query("SELECT * FROM `config`")) {
        foreach ($results as $result) {
            $configs[$result['key']] = $result['value'];
        }
    }
    return $configs;
}

$configs = get_configs();
