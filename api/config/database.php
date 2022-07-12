<?php

class database {
    private $db_name;
    private $db_username;
    private $db_password;
    private $db_host;
    private $db_port;
    
    function __construct() {
        $this->db_host = DB_HOST;
        $this->db_name = DB_NAME;
        $this->db_username = DB_USER;
        $this->db_password = DB_PWD;
        $this->db_port = DB_PORT;
    }

    function connect() {
        return new PDO("mysql:host=" . $this->db_host . ";dbname=" . $this->db_name . ";port=" . $this->db_port, $this->db_username, $this->db_password, array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'", PDO::MYSQL_ATTR_LOCAL_INFILE => true));
    }

    function disconnect(&$conn) {
        $conn = null;
    }
}