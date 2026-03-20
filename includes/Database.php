<?php
/**
 * Database connection using PDO
 */
class Database {
    private $host = 'localhost';
    private $db_name = 'resultados_di'; // Altere conforme necessário
    private $username = 'root'; // Altere conforme necessário
    private $password = ''; // Altere conforme necessário
    private $conn;

    /**
     * Get database connection
     *
     * @return PDO|null
     */
    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8", $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $exception) {
            echo "Erro na conexão com o banco de dados: " . $exception->getMessage();
        }

        return $this->conn;
    }
}
?>
