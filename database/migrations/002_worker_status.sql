-- Sinal de vida do worker local (heartbeat) + o que ele está fazendo
-- agora, pra mostrar isso no painel sem depender de olhar o terminal.

CREATE TABLE IF NOT EXISTS worker_status (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    atividade_atual VARCHAR(255) NULL,
    ultimo_ping_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO worker_status (id, atividade_atual)
VALUES (1, NULL)
ON DUPLICATE KEY UPDATE id = id;
