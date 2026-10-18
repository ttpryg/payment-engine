-- 001: Tambahkan indeks performa pada tabel payment-engine.
--
-- Tujuan: mempercepat lookup berdasarkan payable/payer, filter status &
-- tanggal, serta menjaga idempotensi webhook di level database.
-- Jalankan hanya pada database yang dibuat sebelum indeks diimplementasikan.
-- Fresh install via DatabaseMigrator::migrate() sudah mencakup indeks ini di
-- schema.sql dan menandai migrasi ini sebagai baseline secara otomatis.
CREATE INDEX IF NOT EXISTS idx_payments_payable ON payments (payable_type, payable_id);
CREATE INDEX IF NOT EXISTS idx_payments_payer ON payments (payer_type, payer_id);
CREATE INDEX IF NOT EXISTS idx_payments_status ON payments (status);
CREATE INDEX IF NOT EXISTS idx_payments_created_at ON payments (created_at);
CREATE INDEX IF NOT EXISTS idx_payment_attempts_payment_id ON payment_attempts (payment_id);
CREATE INDEX IF NOT EXISTS idx_attempts_tx_ref ON payment_attempts (gateway_provider, transaction_reference);
CREATE INDEX IF NOT EXISTS idx_payment_refunds_payment_id ON payment_refunds (payment_id);
CREATE INDEX IF NOT EXISTS idx_payment_histories_payment_id ON payment_histories (payment_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_webhook_fingerprint ON webhook_events (gateway_provider, payload_fingerprint);
CREATE UNIQUE INDEX IF NOT EXISTS uq_webhook_event_id ON webhook_events (gateway_provider, event_id);
