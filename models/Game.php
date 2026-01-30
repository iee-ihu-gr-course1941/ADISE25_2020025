<?php

class Game {
    private PDO $conn;
    private string $table_name = "games";

    public int $id;
    public string $player1;
    public string $player2;
    public string $state_json;

    public function __construct(PDO $db) {
        $this->conn = $db;
    }

    public function load(int $id): bool {
        $stmt = $this->conn->prepare("SELECT * FROM {$this->table_name} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return false;

        $this->id        = (int)$row['id'];
        $this->player1   = (string)$row['player1'];
        $this->player2   = (string)$row['player2'];
        $this->state_json = (string)$row['state_json'];

        return true;
    }

    // Αποθηκεύει ΜΟΝΟ state_json
    public function saveState(array $state): bool {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE);

        $stmt = $this->conn->prepare("UPDATE {$this->table_name} SET state_json = :state_json WHERE id = :id");
        return $stmt->execute([
            ':state_json' => $json,
            ':id' => $this->id
        ]);
    }

    // Δημιουργία νέου game (μόνο state_json)
    public function create(string $player1, string $player2, array $initialState): int {
        $json = json_encode($initialState, JSON_UNESCAPED_UNICODE);

        $stmt = $this->conn->prepare(
            "INSERT INTO {$this->table_name} (player1, player2, state_json) VALUES (:p1, :p2, :state_json)"
        );
        $stmt->execute([
            ':p1' => $player1,
            ':p2' => $player2,
            ':state_json' => $json
        ]);

        return (int)$this->conn->lastInsertId();
    }
}
