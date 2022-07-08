<?php

const DS = DIRECTORY_SEPARATOR;
defined("APPLICATION_PATH") || define("APPLICATION_PATH", realpath(dirname(__FILE__) . DS . "app"));
require APPLICATION_PATH . DS . "config.php";
require LIB_PATH . "functions.php";

$page = get_page("page", "home");
$model = MODEL_PATH . $page . ".php";
$view = VIEW_PATH . $page . ".phtml";
$_404 = VIEW_PATH . "404.phtml";

if (file_exists($model)) {
    require $model;
}

$main_content = $_404;

if (file_exists($view)) {
    $main_content = $view;
}

require VIEW_PATH . "layout.phtml";