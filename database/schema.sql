CREATE TABLE IF NOT EXISTS payments (
    id VARCHAR(36) PRIMARY KEY,
    payment_number VARCHAR(50) NOT NULL UNIQUE,
    payable_type VARCHAR(50) NOT NULL,
    payable_id VARCHAR(100) NOT NULL,
    payer_type VARCHAR(50) NULL,
    payer_id VARCHAR(100) NULL,
    method VARCHAR(50) NOT NULL,
    gateway_provider VARCHAR(50) NOT NULL,
    status VARCHAR(30) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'IDR',
    amount BIGINT NOT NULL DEFAULT 0,
    fee BIGINT NOT NULL DEFAULT 0,
    total_amount BIGINT NOT NULL DEFAULT 0,
    paid_amount BIGINT NOT NULL DEFAULT 0,
    refunded_amount BIGINT NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    paid_at DATETIME NULL,
    metadata TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_payments_payable ON payments (payable_type, payable_id);
CREATE INDEX IF NOT EXISTS idx_payments_payer ON payments (payer_type, payer_id);
CREATE INDEX IF NOT EXISTS idx_payments_status ON payments (status);
CREATE INDEX IF NOT EXISTS idx_payments_created_at ON payments (created_at);

CREATE TABLE IF NOT EXISTS payment_attempts (
    id VARCHAR(36) PRIMARY KEY,
    payment_id VARCHAR(36) NOT NULL,
    gateway_provider VARCHAR(50) NOT NULL,
    transaction_reference VARCHAR(150) NULL,
    status VARCHAR(30) NOT NULL,
    amount BIGINT NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'IDR',
    raw_request TEXT NULL,
    raw_response TEXT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_payment_attempts_payment_id ON payment_attempts (payment_id);
CREATE INDEX IF NOT EXISTS idx_attempts_tx_ref ON payment_attempts (gateway_provider, transaction_reference);

CREATE TABLE IF NOT EXISTS payment_refunds (
    id VARCHAR(36) PRIMARY KEY,
    payment_id VARCHAR(36) NOT NULL,
    refund_number VARCHAR(50) NOT NULL UNIQUE,
    amount BIGINT NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'IDR',
    reason TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'completed',
    gateway_refund_id VARCHAR(150) NULL,
    actor_type VARCHAR(50) NULL,
    actor_id VARCHAR(100) NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_payment_refunds_payment_id ON payment_refunds (payment_id);

CREATE TABLE IF NOT EXISTS payment_histories (
    id VARCHAR(36) PRIMARY KEY,
    payment_id VARCHAR(36) NOT NULL,
    action VARCHAR(50) NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NULL,
    actor_type VARCHAR(50) NULL,
    actor_id VARCHAR(100) NULL,
    note TEXT NULL,
    metadata TEXT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_payment_histories_payment_id ON payment_histories (payment_id);

CREATE TABLE IF NOT EXISTS webhook_events (
    id VARCHAR(36) PRIMARY KEY,
    gateway_provider VARCHAR(50) NOT NULL,
    event_id VARCHAR(150) NULL,
    payload_fingerprint VARCHAR(64) NOT NULL,
    payload TEXT NOT NULL,
    is_processed TINYINT(1) NOT NULL DEFAULT 0,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_webhook_fingerprint ON webhook_events (gateway_provider, payload_fingerprint);
CREATE INDEX IF NOT EXISTS idx_webhook_event_id ON webhook_events (gateway_provider, event_id);
