<?php
require_once dirname(__FILE__) . '/../app_config.php';

function getDatabaseConnection()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    try {
        global $db_host, $db_user, $db_pass, $db_name;

        $servername = isset($db_host) ? $db_host : '';
        $username = isset($db_user) ? $db_user : '';
        $password = isset($db_pass) ? $db_pass : '';
        $dbName = isset($db_name) ? $db_name : '';

        if ($servername === '' || $username === '' || $dbName === '') {
            throw new Exception('Missing DB config values. Please check app_config.php.');
        }

        $pdo = new PDO(
            "mysql:host=$servername;dbname=$dbName;charset=utf8mb4",
            $username,
            $password,
            array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_PERSISTENT => false
            )
        );
        $pdo->exec("SET NAMES utf8mb4");
        return $pdo;
    } catch (Exception $e) {
        die(json_encode(array(
            "status" => 500,
            "msg" => $e->getMessage() . ", Some Error Occured Please try Reloading the Page",
        )));
    }
}