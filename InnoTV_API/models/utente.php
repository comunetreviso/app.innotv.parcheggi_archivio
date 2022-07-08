<?php

class utente {
    private $conn;
    private $email;
    private $password;
    private $token;
    
    function __construct($conn, $email = null, $password = null, $token = null) {
        $this->conn = $conn;
        $this->email = $email;
        $this->password = $password;
        $this->token = $token;
    }
    
    function login() {
        if (!$stmt = $this->conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1")) {
            throw new Exception("Errore preparazione statement.");
        }

        if (!$stmt->execute(array($this->email))) {
            throw new Exception("Errore esecuzione statement.");
        }

        $row = $stmt->fetch();
        
        if (!$row) {
            throw new Exception("Nessun utente trovato con l'e-mail specificata.");
        }
        
        if (!password_verify($this->password, $row["password"])) {
            throw new Exception("La password inserita non è corretta.");
        }
        
        return array("user_id" => $row["id"], "user_email" => $row["email"], "user_nominativo" => $row["first_name"] . " " . $row["last_name"], "user_token" => $row["api_token"]);
    }
    
    function check_token() {
        if (!$stmt = $this->conn->prepare("SELECT * FROM users WHERE api_token = ? LIMIT 1")) {
            throw new Exception("Errore preparazione statement.");
        }

        if (!$stmt->execute(array($this->token))) {
            throw new Exception("Errore esecuzione statement.");
        }
        
        $row = $stmt->fetch();
        
        if (!$row) {
            return false;
        }
        
        return true;
    }
}