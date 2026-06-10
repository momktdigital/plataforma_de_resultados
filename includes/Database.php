<?php
class Database {
    private string $host;
    private string $db_name;
    private string $username;
    private string $password;
    private string $charset;
    private ?PDO $conn = null;

    public function __construct() {
        $configPath = __DIR__ . '/../config/db.config.php';

        if (!file_exists($configPath)) {
            error_log('db.config.php não encontrado em: ' . $configPath);
            die('Erro de configuração do servidor. Contate o administrador.');
        }

        $cfg = require $configPath;
        $this->host     = $cfg['host']     ?? 'localhost';
        $this->db_name  = $cfg['db_name']  ?? '';
        $this->username = $cfg['username'] ?? '';
        $this->password = $cfg['password'] ?? '';
        $this->charset  = $cfg['charset']  ?? 'utf8mb4';
    }

    public function getConnection(): ?PDO {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('Falha na conexão com o banco: ' . $e->getMessage());
            die('Erro ao conectar com o banco de dados. Tente novamente mais tarde.');
        }

        return $this->conn;
    }
}
