<?php
class Database {
    private string $host = "localhost";
    private string $db_name = "xeri";
    private string $username = "root";
    private string $password = "";
    public ?PDO $conn = null;

    public function getConnection(): PDO {
        if ($this->conn instanceof PDO) return $this->conn;

        $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";

        try {
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // Μην κάνεις echo σε API· γυρνά JSON error (αν έχεις bootstrap)
            throw $e;
        }

        return $this->conn;
    }
}
