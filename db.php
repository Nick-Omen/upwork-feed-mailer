<?php

$mysqli = new mysqli("localhost", "root", "666", "upwork");

if ($mysqli->connect_errno) {
    echo "Не удалось подключиться к MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
}

function get_configs()
{
    global $mysqli;

    $configs = array();
    if ($results = $mysqli -> query("SELECT * FROM `upwork`.`config`")) {
        foreach ($results as $result) {
            $configs[$result['key']] = $result['value'];
        }
    }
    return $configs;
}
