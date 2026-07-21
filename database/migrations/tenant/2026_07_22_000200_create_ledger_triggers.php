<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T1–T5 + audit_log append-only triggers (docs/specs/01 §2.2, §6).
 * DB safety net only — PostingService is the primary enforcer (01 §2.3).
 * MariaDB-only: sqlite (fast suite) has no trigger support worth relying on;
 * the immutability tests skip there and run on the CI mariadb job.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        // T1 — lines only while draft; postable+active accounts; denormalize
        // entry_date / fiscal_period_id from the header.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bi_journal_lines BEFORE INSERT ON journal_lines FOR EACH ROW
            BEGIN
              DECLARE v_status VARCHAR(10);
              DECLARE v_date DATE;
              DECLARE v_period SMALLINT UNSIGNED;
              SELECT status, entry_date, fiscal_period_id INTO v_status, v_date, v_period
                FROM journal_entries WHERE id = NEW.journal_entry_id;
              IF v_status IS NULL OR v_status <> 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal lines may only be added to draft entries';
              END IF;
              IF (SELECT COUNT(*) FROM accounts WHERE id = NEW.account_id AND is_postable = 1 AND is_active = 1) = 0 THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'account is not postable or not active';
              END IF;
              SET NEW.entry_date = v_date;
              SET NEW.fiscal_period_id = v_period;
            END
            SQL);

        // T2/T3 — posted/void lines are immutable.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bu_journal_lines BEFORE UPDATE ON journal_lines FOR EACH ROW
            BEGIN
              IF (SELECT status FROM journal_entries WHERE id = OLD.journal_entry_id) <> 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'posted journal lines are immutable';
              END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bd_journal_lines BEFORE DELETE ON journal_lines FOR EACH ROW
            BEGIN
              IF (SELECT status FROM journal_entries WHERE id = OLD.journal_entry_id) <> 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'posted journal lines are immutable';
              END IF;
            END
            SQL);

        // T4 — header transitions. Void is frozen; posted allows exactly
        // (a) posted→void + metadata, (b) reversed_by_entry_id NULL→value once;
        // draft→posted re-checks period/lock/balance at the flip.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bu_journal_entries BEFORE UPDATE ON journal_entries FOR EACH ROW
            BEGIN
              DECLARE v_sum_d BIGINT;
              DECLARE v_sum_c BIGINT;
              DECLARE v_count INT;
              DECLARE v_pstatus VARCHAR(10);
              DECLARE v_period_no TINYINT UNSIGNED;
              DECLARE v_lock DATE;

              IF OLD.status = 'void' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'void entries are frozen';
              END IF;

              IF OLD.status = 'posted' THEN
                IF NOT (NEW.entry_date <=> OLD.entry_date)
                   OR NOT (NEW.entry_number <=> OLD.entry_number)
                   OR NOT (NEW.fiscal_period_id <=> OLD.fiscal_period_id)
                   OR NOT (NEW.journal_book <=> OLD.journal_book)
                   OR NOT (NEW.source_type <=> OLD.source_type)
                   OR NOT (NEW.source_id <=> OLD.source_id)
                   OR NOT (NEW.posted_at <=> OLD.posted_at)
                   OR NOT (NEW.posted_by <=> OLD.posted_by)
                   OR NOT (NEW.total_debit_centavos <=> OLD.total_debit_centavos)
                   OR NOT (NEW.total_credit_centavos <=> OLD.total_credit_centavos)
                   OR NOT (NEW.posting_hash <=> OLD.posting_hash)
                   OR NOT (NEW.created_by <=> OLD.created_by)
                   OR NOT (NEW.reverses_entry_id <=> OLD.reverses_entry_id) THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'posted entries are immutable';
                END IF;

                IF NEW.status = 'void' THEN
                  IF NEW.voided_at IS NULL OR NEW.voided_by IS NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'void requires voided_at and voided_by';
                  END IF;
                ELSEIF NEW.status = 'posted' THEN
                  IF NOT (OLD.reversed_by_entry_id IS NULL AND NEW.reversed_by_entry_id IS NOT NULL) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'posted entries allow only void or reversal linkage';
                  END IF;
                ELSE
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'posted entries cannot return to draft';
                END IF;
              END IF;

              IF OLD.status = 'draft' THEN
                IF NEW.status = 'void' THEN
                  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'draft entries are deleted, not voided';
                END IF;
                IF NEW.status = 'posted' THEN
                  SELECT status, period_no INTO v_pstatus, v_period_no
                    FROM fiscal_periods WHERE id = NEW.fiscal_period_id;
                  IF v_pstatus <> 'open' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fiscal period is not open';
                  END IF;
                  SELECT posting_lock_date INTO v_lock FROM ledger_settings WHERE id = 1;
                  IF v_lock IS NOT NULL AND NEW.entry_date <= v_lock AND v_period_no <> 13 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'entry date is on or before the posting lock date';
                  END IF;
                  SELECT COALESCE(SUM(debit_centavos), 0), COALESCE(SUM(credit_centavos), 0), COUNT(*)
                    INTO v_sum_d, v_sum_c, v_count
                    FROM journal_lines WHERE journal_entry_id = NEW.id;
                  IF v_count < 2 OR v_sum_d = 0 OR v_sum_d <> v_sum_c THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'journal entry does not balance';
                  END IF;
                END IF;
              END IF;
            END
            SQL);

        // T5 — only drafts are deletable.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bd_journal_entries BEFORE DELETE ON journal_entries FOR EACH ROW
            BEGIN
              IF OLD.status <> 'draft' THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'posted/void entries cannot be deleted - use reversal';
              END IF;
            END
            SQL);

        // audit_log append-only (layer 1; layer 2 is the INSERT/SELECT-only grant).
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bu_audit_log BEFORE UPDATE ON audit_log FOR EACH ROW
            BEGIN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bd_audit_log BEFORE DELETE ON audit_log FOR EACH ROW
            BEGIN
              SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only';
            END
            SQL);
    }

    public function down(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ([
            'bi_journal_lines', 'bu_journal_lines', 'bd_journal_lines',
            'bu_journal_entries', 'bd_journal_entries',
            'bu_audit_log', 'bd_audit_log',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
