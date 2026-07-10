-- Ask Yada session and log tables
-- Tracks usage metadata and question/response history

CREATE TABLE IF NOT EXISTS yy_ask_session (
    ask_session_key          SERIAL PRIMARY KEY,
    session_id               VARCHAR(128) NOT NULL,
    ip_address               VARCHAR(45),
    user_agent               TEXT,
    referer                  VARCHAR(500),
    accept_language          VARCHAR(255),
    ask_session_dtime        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ask_session_end_dtime    TIMESTAMP,
    ask_session_question_count INT NOT NULL DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_ask_session_session_id ON yy_ask_session (session_id);
CREATE INDEX IF NOT EXISTS idx_ask_session_ip_address ON yy_ask_session (ip_address);
CREATE INDEX IF NOT EXISTS idx_ask_session_dtime ON yy_ask_session (ask_session_dtime);

CREATE TABLE IF NOT EXISTS yy_ask_session_log (
    ask_session_log_key      SERIAL PRIMARY KEY,
    ask_session_key          INT NOT NULL REFERENCES yy_ask_session (ask_session_key),
    ask_log_question         TEXT NOT NULL,
    ask_log_response         TEXT,
    ask_log_context_count    SMALLINT,
    ask_log_prompt_tokens    INT,
    ask_log_completion_tokens INT,
    ask_log_model            VARCHAR(100),
    ask_log_error            TEXT,
    ask_log_dtime            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ask_log_duration_ms      INT
);

CREATE INDEX IF NOT EXISTS idx_ask_session_log_session ON yy_ask_session_log (ask_session_key);
CREATE INDEX IF NOT EXISTS idx_ask_session_log_dtime ON yy_ask_session_log (ask_log_dtime);
