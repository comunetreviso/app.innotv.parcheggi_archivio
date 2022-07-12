<?php

require_once(ROOT_PATH . "/lib/PHPExcel/PHPExcel.php");

class parcheggio {
    private $conn;
    private $via;
    private $dataIni;
    private $dataFin;
    private $fileInfo;
    
    function __construct($conn, $via = null, $dataIni = null, $dataFin = null, $fileInfo = null) {
        $this->conn = $conn;
        $this->via = $via;
        $this->dataIni = $dataIni;
        $this->dataFin = $dataFin;
        $this->fileInfo = $fileInfo;
    }
    
    function importa() {
        $filePath = realpath(dirname(__DIR__)) . DIRECTORY_SEPARATOR . "temp" . DIRECTORY_SEPARATOR . basename($this->fileInfo["name"]);
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        
        if ($ext != "csv") {
            throw new Exception("Sono ammessi solo file CSV.");
        }
                
        if (!move_uploaded_file($this->fileInfo["tmp_name"], $filePath)) {
            throw new Exception("Errore durante il caricamento del file.");
        }
        
        // lettura file CSV (il delimitatore è la virgola)
        
        $inputFileType = PHPExcel_IOFactory::identify($filePath);
        $objReader = PHPExcel_IOFactory::createReader($inputFileType);
        $objPHPExcel = $objReader->load($filePath);
        $sheet = $objPHPExcel->getSheet(0);
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        $columnCount = PHPExcel_Cell::columnIndexFromString($highestColumn);
        $titles = $sheet->rangeToArray("A1:" . $highestColumn . "1");
        
        // verifico che il modello per l'importazione sia rispettato
        
        $importColumns = array("dataServer", "SerialNumber", "Vie", "Evento", "PaystationNum", "dataEvento", "dataSensore");
        
        if (count(array_diff($importColumns, $titles[0])) > 0) {
            throw new Exception("Il file caricato non contiene tutti i campi richiesti per l'importazione.");
        }
                
        $body = $sheet->rangeToArray("A2:" . $highestColumn . $highestRow);      
        $data = array();
        
        for ($row = 0; $row <= $highestRow - 2; $row++) {
            $temp = array();
            
            for ($column = 0; $column <= $columnCount - 1; $column++) {
                $columnName = $titles[0][$column];
                
                if (in_array($columnName, $importColumns)) {
                    if ($columnName == "dataServer" || $columnName == "dataEvento" || $columnName == "dataSensore") {
                        $temp[$columnName] = date("Y-m-d H:i:s", strtotime(str_replace("/", "-", $body[$row][$column])));
                    }

                    else {
                        $temp[$columnName] = $body[$row][$column];
                    }                 
                }
            }
            
            $data[$row] = $temp;
        }
                        
        $sort = array();
        $checkDateEvento = array();
        
        foreach ($data as $k => $v) {
            $sort["SerialNumber"][$k] = $v["SerialNumber"];
            $sort["dataEvento"][$k] = strtotime($v["dataEvento"]);
            $checkDateEvento[] = date("Y-m-d", $sort["dataEvento"][$k]); 
        }
        
        // controllo che i record inseriti facciano riferimento ad un'unica data (il file deve essere giornaliero)
        
        $dateEvento = array_unique($checkDateEvento);
        
        if (count($dateEvento) != 1) {
            throw new Exception("Il file deve fare riferimento ad un'unica data.");
        }
                        
        // ordinamento per SerialNumber e dataEvento e creazione file CSV ordinato
        
        array_multisort($sort["SerialNumber"], SORT_ASC, $sort["dataEvento"], SORT_ASC, $data);
                
        $index = 1;
        $fo = fopen($filePath, "w+");
        
        foreach ($data as $d) {
            $d["id"] = $index;
            $index++;
            fputcsv($fo, $d);
        }
        
        fclose($fo);
                        
        // importazione dati nel database
                
        try {
            $this->conn->beginTransaction();
            
            // controllo che non esistano già record delle soste relativi al giorno di riferimento
            
            if (!$stmt_1 = $this->conn->prepare("SELECT COUNT(*) FROM parcheggi_soste WHERE DATE(data_inizio_sosta) = ?")) {
                throw new Exception("Errore preparazione statement 1.");
            }
            
            if (!$stmt_1->execute(array($dateEvento[0]))) {
                throw new Exception("Errore esecuzione statement 1.");
            }
            
            $numRows = $stmt_1->fetchColumn();

            if ($numRows > 0) {
                throw new Exception("Esiste già un'importazione relativa al giorno " . date("d/m/Y", strtotime($dateEvento[0])) . ".");
            }
                        
            // svuoto la tabella temporanea
            
            $this->conn->exec("DELETE FROM temp_parcheggi_eventi");
                        
            // importazione nella tabella temporanea
            
            $qry = "LOAD DATA LOCAL INFILE '" . str_replace("\\", "/", $filePath) . "' "
                   . "INTO TABLE temp_parcheggi_eventi "
                   . "FIELDS TERMINATED BY ',' "
                   . "OPTIONALLY ENCLOSED BY '\"' "
                   . "LINES TERMINATED BY '\n' "
                   . "(data_server, serial_number, via, evento, paystation_num, data_evento, data_sensore, id)";
            
            if (!$stmt_2 = $this->conn->prepare($qry)) {
                throw new Exception("Errore preparazione statement 2.");
            }
            
            if (!$stmt_2->execute()) {
                throw new Exception("Errore esecuzione statement 2.");
            }
                                    
            // eliminazione eventi consecutivi multipli
            
            $qry = "DELETE FROM temp_parcheggi_eventi WHERE id IN ("
                   . "SELECT id FROM (SELECT * FROM temp_parcheggi_eventi) AS e1 "
                   . "WHERE (SELECT COUNT(*) FROM (SELECT * FROM temp_parcheggi_eventi) AS e2 WHERE e2.id = e1.id - 1 AND e2.serial_number = e1.serial_number AND e2.evento = e1.evento) > 0)";
                             
            if (!$stmt_3 = $this->conn->prepare($qry)) {
                throw new Exception("Errore preparazione statement 3.");
            }
            
            if (!$stmt_3->execute()) {
                throw new Exception("Errore esecuzione statement 3.");
            }
                        
            // se l'ultimo evento per ciascun parcheggio è "Occupato" ne devo inserire un altro "Libero" alle 23:59:59 dello stesso giorno 
            
            $qry = "INSERT INTO temp_parcheggi_eventi (id, data_server, serial_number, via, evento, paystation_num, data_evento, data_sensore) "
                   . "SELECT @i := @i + 1, CONCAT(DATE(data_server), ' 23:59:59'), serial_number, via, 'Libero', paystation_num, CONCAT(DATE(data_evento), ' 23:59:59'), CONCAT(DATE(data_sensore), ' 23:59:59') "
                   . "FROM temp_parcheggi_eventi, (SELECT @i := (SELECT MAX(id) FROM temp_parcheggi_eventi)) AS vars WHERE id IN ("
                   . "SELECT MAX(id) FROM temp_parcheggi_eventi GROUP BY serial_number) "
                   . "AND evento = 'Occupato'";
            
            if (!$stmt_4 = $this->conn->prepare($qry)) {
                throw new Exception("Errore preparazione statement 4.");
            }
            
            if (!$stmt_4->execute()) {
                throw new Exception("Errore esecuzione statement 4.");
            }
                        
            // se il primo evento per ciascun parcheggio è "Libero" ne devo inserire un altro "Occupato" alle 00:00:00 dello stesso giorno 
            
            $qry = "INSERT INTO temp_parcheggi_eventi (id, data_server, serial_number, via, evento, paystation_num, data_evento, data_sensore) "
                   . "SELECT @i := @i + 1, CONCAT(DATE(data_server), ' 00:00:00'), serial_number, via, 'Occupato', paystation_num, CONCAT(DATE(data_evento), ' 00:00:00'), CONCAT(DATE(data_sensore), ' 00:00:00') "
                   . "FROM temp_parcheggi_eventi, (SELECT @i := (SELECT MAX(id) FROM temp_parcheggi_eventi)) AS vars WHERE id IN ("
                   . "SELECT MIN(id) FROM temp_parcheggi_eventi GROUP BY serial_number) "
                   . "AND evento = 'Libero'";
            
            if (!$stmt_5 = $this->conn->prepare($qry)) {
                throw new Exception("Errore preparazione statement 5.");
            }
            
            if (!$stmt_5->execute()) {
                throw new Exception("Errore esecuzione statement 5.");
            }
                        
            // inserimento nella tabella effettiva delle soste
            
            $qry = "INSERT INTO parcheggi_soste (serial_number, paystation_num, data_inizio_sosta, data_fine_sosta, durata_sosta) "
                   . "SELECT e1.serial_number, e1.paystation_num, MAX(e2.data_evento), e1.data_evento, TIMESTAMPDIFF(MINUTE, MAX(e2.data_evento), e1.data_evento) "
                   . "FROM temp_parcheggi_eventi AS e1 INNER JOIN temp_parcheggi_eventi AS e2 ON e1.serial_number = e2.serial_number "
                   . "AND e2.data_evento < e1.data_evento "
                   . "AND e1.evento = 'Libero' "
                   . "AND e2.evento = 'Occupato' "
                   . "GROUP BY e1.serial_number, e1.paystation_num, e1.data_evento";
            
            if (!$stmt_6 = $this->conn->prepare($qry)) {
                throw new Exception("Errore preparazione statement 6.");
            }
            
            if (!$stmt_6->execute()) {
                throw new Exception("Errore esecuzione statement 6.");
            }
                    
            // scarto i record con durata della sosta minore o uguale a 1 minuto
                        
            if (!$stmt_7 = $this->conn->prepare("DELETE FROM parcheggi_soste WHERE durata_sosta <= 1 AND DATE(data_inizio_sosta) = ?")) {
                throw new Exception("Errore preparazione statement 7.");
            }
            
            if (!$stmt_7->execute(array($dateEvento[0]))) {
                throw new Exception("Errore esecuzione statement 7.");
            }
                        
            $this->conn->commit();
            return $filePath;
        }   
        
        catch (Exception $e) {
            if ($this->conn != null) {
                $this->conn->rollBack();
            }
            
            throw $e;
        }  
    }
    
    function get_occupazioni() {
        $results = array();
        $params = array();

        $qry = "SELECT qry.*, ROUND(qry.somma_durata_sosta / qry.num_parcheggi, 2) AS rapporto FROM ("
               . "SELECT via, COUNT(DISTINCT ps.serial_number) AS num_parcheggi, SUM(durata_sosta) AS somma_durata_sosta "
               . "FROM parcheggi_soste AS ps INNER JOIN parcheggi AS p ON p.serial_number = ps.serial_number";

        if (!empty($this->dataIni) || !empty($this->dataFin) || !empty($this->via)) {
            $qry .= " WHERE ";
            $primoFiltro = true;

            if (!empty($this->dataIni)) {
                $qry .= "ps.data_inizio_sosta >= :data_ini";
                $params[":data_ini"] = date("Y-m-d", strtotime(str_replace("/", "-", $this->dataIni)));
                $primoFiltro = false;
            }

            if (!empty($this->dataFin)) {
                if ($primoFiltro) {
                    $qry .= "ps.data_inizio_sosta <= :data_fin";
                    $primoFiltro = false;
                }

                else {
                    $qry .= " AND ps.data_inizio_sosta <= :data_fin";
                }

                $params[":data_fin"] = date("Y-m-d", strtotime(str_replace("/", "-", $this->dataFin)));
            }

            if (!empty($this->via)) {
                if ($primoFiltro) {
                    $qry .= "p.via LIKE :via";
                    $primoFiltro = false;
                }

                else {
                    $qry .= " AND p.via LIKE :via";
                }

                $params[":via"] = "%" . $this->via . "%";
            }
        }

        $qry .= " GROUP BY p.via) AS qry ORDER BY rapporto DESC, via";

        if (!$stmt = $this->conn->prepare($qry)) {
            throw new Exception("Errore preparazione statement.");
        }

        if (!$stmt->execute($params)) {
            throw new Exception("Errore esecuzione statement.");
        }

        while ($row = $stmt->fetch()) {
            $results[] = array(
                "via" => $row["via"],
                "num_parcheggi" => number_format($row["num_parcheggi"], 0, ",", "."),
                "somma_durata_sosta" => number_format($row["somma_durata_sosta"], 0, ",", "."),
                "rapporto" => number_format($row["rapporto"], 2, ",", ".")
            );
        }

        return $results;					
    }
    
    function get_rotazioni() {
        $results = array();
        $params = array();

        $qry = "SELECT qry.*, ROUND(qry.num_rotazioni / qry.num_parcheggi, 2) AS rapporto FROM ("
               . "SELECT via, COUNT(DISTINCT ps.serial_number) AS num_parcheggi, COUNT(*) AS num_rotazioni "
               . "FROM parcheggi_soste AS ps INNER JOIN parcheggi AS p ON p.serial_number = ps.serial_number";

        if (!empty($this->dataIni) || !empty($this->dataFin) || !empty($this->via)) {
            $qry .= " WHERE ";
            $primoFiltro = true;

            if (!empty($this->dataIni)) {
                $qry .= "ps.data_inizio_sosta >= :data_ini";
                $params[":data_ini"] = date("Y-m-d", strtotime(str_replace("/", "-", $this->dataIni)));
                $primoFiltro = false;
            }

            if (!empty($this->dataFin)) {
                if ($primoFiltro) {
                    $qry .= "ps.data_inizio_sosta <= :data_fin";
                    $primoFiltro = false;
                }

                else {
                    $qry .= " AND ps.data_inizio_sosta <= :data_fin";
                }

                $params[":data_fin"] = date("Y-m-d", strtotime(str_replace("/", "-", $this->dataFin)));
            }

            if (!empty($this->via)) {
                if ($primoFiltro) {
                    $qry .= "p.via LIKE :via";
                    $primoFiltro = false;
                }

                else {
                    $qry .= " AND p.via LIKE :via";
                }

                $params[":via"] = "%" . $this->via . "%";
            }
        }

        $qry .= " GROUP BY p.via) AS qry ORDER BY rapporto DESC, via";

        if (!$stmt = $this->conn->prepare($qry)) {
            throw new Exception("Errore preparazione statement.");
        }

        if (!$stmt->execute($params)) {
            throw new Exception("Errore esecuzione statement.");
        }

        while ($row = $stmt->fetch()) {
            $results[] = array(
                "via" => $row["via"],
                "num_parcheggi" => number_format($row["num_parcheggi"], 0, ",", "."),
                "num_rotazioni" => number_format($row["num_rotazioni"], 0, ",", "."),
                "rapporto" => number_format($row["rapporto"], 2, ",", ".")
            );
        }

        return $results;					
    }
}