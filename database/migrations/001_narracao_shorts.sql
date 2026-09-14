-- Narração própria -> sugestão de corte automático (Shorts)
--
-- Fluxo: o usuário grava a narração de um Short com a própria voz,
-- sobe o áudio. A IA transcreve (com timestamp por trecho) e sugere,
-- pra cada trecho, qual bruto do capítulo melhor combina. A escolha
-- fina do timestamp dentro do bruto continua manual (ou usando o
-- refinamento de timestamps que já existe para clipes de edição).

ALTER TABLE capitulo_short_roteiros
    ADD COLUMN narracao_audio_path   VARCHAR(255) NULL     AFTER observacoes_ia,
    ADD COLUMN narracao_transcricao  TEXT         NULL     AFTER narracao_audio_path,
    ADD COLUMN narracao_status       VARCHAR(30)  NOT NULL DEFAULT 'sem_narracao' AFTER narracao_transcricao,
    ADD COLUMN narracao_enviado_at   TIMESTAMP    NULL     AFTER narracao_status;

CREATE TABLE IF NOT EXISTS capitulo_short_cortes_sugeridos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    short_roteiro_id    INT UNSIGNED NOT NULL,
    ordem               INT UNSIGNED NOT NULL DEFAULT 0,

    narracao_inicio_ms  INT UNSIGNED NOT NULL,
    narracao_fim_ms     INT UNSIGNED NOT NULL,
    narracao_texto      TEXT NOT NULL,

    arquivo_id          BIGINT UNSIGNED NULL,
    trecho_transcricao  TEXT NULL,
    justificativa       TEXT NULL,

    aprovado            TINYINT(1) NOT NULL DEFAULT 0,

    criado_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_corte_short_roteiro
        FOREIGN KEY (short_roteiro_id) REFERENCES capitulo_short_roteiros(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_corte_arquivo
        FOREIGN KEY (arquivo_id) REFERENCES capitulo_arquivos(id)
        ON DELETE SET NULL,

    INDEX idx_corte_short (short_roteiro_id, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
