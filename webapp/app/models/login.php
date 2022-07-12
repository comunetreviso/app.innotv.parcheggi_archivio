<?php

if (!empty($_SESSION["user_id"])) {
    header("Location: index.php?page=home");
    exit;
}

if (!empty($_POST["user_id"])) {
    session_start();
    session_regenerate_id();
    $_SESSION["user_id"] = $_POST["user_id"];
    $_SESSION["user_email"] = $_POST["user_email"];
    $_SESSION["user_nominativo"] = $_POST["user_nominativo"];
    $_SESSION["user_token"] = $_POST["user_token"];
    exit;
}