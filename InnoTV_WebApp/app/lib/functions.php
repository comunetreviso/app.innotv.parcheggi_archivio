<?php

function get_page($name, $def) {
    return isset($_REQUEST[$name]) ? $_REQUEST[$name] : $def;
}