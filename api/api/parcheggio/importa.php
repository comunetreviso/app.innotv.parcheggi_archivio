<?php

if ($_SERVER["REQUEST_METHOD"] == "OPTIONS") {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method, Access-Control-Request-Headers, Authorization");
    http_response_code(200);
    exit;
}

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit;
}

require_once("../../config/config.php");
require_once("../../config/database.php");
require_once("../../models/parcheggio.php");
require_once("../../models/utente.php");

if (!isset($_SERVER["PHP_AUTH_USER"]) || !isset($_SERVER["PHP_AUTH_PW"]) || $_SERVER["PHP_AUTH_USER"] != API_USER || $_SERVER["PHP_AUTH_PW"] != API_PW) {
    http_response_code(401);
    exit;
}

$db = new database();
$conn = null;

try {
    if (empty($_FILES["fupParcheggi"])) {
        throw new Exception("File vuoto.");
    }
    
    $conn = $db->connect();
    $utente = new utente($conn, null, null, $_POST["token"]);
    
    if (!$utente->check_token()) {
        http_response_code(401);
        echo json_encode(array("error_message" => "Token non valido."));
    }
    
    else {
        $parcheggio = new parcheggio($conn, null, null, null, $_FILES["fupParcheggi"]);
        $filePath = $parcheggio->importa();

        // elimino i file temporanei

        if (!empty($filePath)) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            $csvPath = $filePath . ".csv";

            if (file_exists($csvPath)) {
                unlink($csvPath);
            }
        }

        echo json_encode(array("error_message" => "-"));
    }
}   

catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array("error_message" => $e->getMessage()));
}  

finally {
    $db->disconnect($conn);
}