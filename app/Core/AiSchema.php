<?php
final class AiSchema
{
    public const VERSION='1.0.0';

    public static function migrate(PDO $pdo): void
    {
        foreach(self::tables() as $sql)$pdo->exec($sql);
        self::upgrades($pdo);
        self::permissions($pdo);
        setting_set('ai_schema_version',self::VERSION,0);
    }

    private static function tables(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS ai_worker_tokens (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                label VARCHAR(160) NOT NULL,
                token_prefix VARCHAR(20) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                capabilities_json JSON NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at DATETIME NULL,
                last_used_at DATETIME NULL,
                UNIQUE KEY uniq_ai_worker_token_hash (token_hash),
                INDEX idx_ai_worker_token_ws (workspace_id,active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS ai_worker_nodes (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                token_id BIGINT NOT NULL,
                node_uid VARCHAR(120) NOT NULL,
                node_name VARCHAR(190) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'online',
                os_name VARCHAR(190) NULL,
                cpu_model VARCHAR(255) NULL,
                cpu_cores INT NOT NULL DEFAULT 1,
                ram_mb INT NOT NULL DEFAULT 0,
                capabilities_json JSON NULL,
                models_json JSON NULL,
                metadata_json JSON NULL,
                current_jobs INT NOT NULL DEFAULT 0,
                last_seen_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_ai_worker_node (workspace_id,node_uid),
                INDEX idx_ai_worker_online (workspace_id,status,last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS ai_conversations (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NULL,
                user_id INT NOT NULL,
                title VARCHAR(190) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                INDEX idx_ai_conv_user (workspace_id,user_id,updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS ai_jobs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NULL,
                conversation_id BIGINT NULL,
                requested_by INT NOT NULL,
                job_type VARCHAR(50) NOT NULL DEFAULT 'agent_chat',
                prompt MEDIUMTEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'queued',
                priority INT NOT NULL DEFAULT 100,
                required_capability VARCHAR(80) NULL,
                context_json JSON NULL,
                result_text MEDIUMTEXT NULL,
                result_json JSON NULL,
                error_text MEDIUMTEXT NULL,
                worker_node_id BIGINT NULL,
                lease_hash CHAR(64) NULL,
                leased_at DATETIME NULL,
                lease_expires_at DATETIME NULL,
                started_at DATETIME NULL,
                completed_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                INDEX idx_ai_jobs_queue (workspace_id,status,priority,created_at),
                INDEX idx_ai_jobs_user (workspace_id,requested_by,created_at),
                INDEX idx_ai_jobs_company (workspace_id,company_id,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS ai_action_proposals (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NULL,
                job_id BIGINT NOT NULL,
                tool_name VARCHAR(120) NOT NULL,
                idempotency_key VARCHAR(190) NULL,
                arguments_json JSON NOT NULL,
                summary VARCHAR(500) NOT NULL,
                risk_level VARCHAR(20) NOT NULL DEFAULT 'medium',
                requires_approval TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(30) NOT NULL DEFAULT 'proposed',
                result_json JSON NULL,
                error_text MEDIUMTEXT NULL,
                proposed_at DATETIME NULL,
                approved_by INT NULL,
                approved_at DATETIME NULL,
                rejected_by INT NULL,
                rejected_at DATETIME NULL,
                executed_by INT NULL,
                executed_at DATETIME NULL,
                UNIQUE KEY uniq_ai_action_idempotency (workspace_id,job_id,idempotency_key),
                INDEX idx_ai_action_job (workspace_id,job_id,status),
                INDEX idx_ai_action_company (workspace_id,company_id,status,proposed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS ai_suggestions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NULL,
                user_id INT NULL,
                suggestion_type VARCHAR(60) NOT NULL,
                dedupe_key VARCHAR(190) NULL,
                title VARCHAR(190) NOT NULL,
                body TEXT NOT NULL,
                evidence_json JSON NULL,
                score DECIMAL(8,5) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'new',
                due_at DATETIME NULL,
                source_job_id BIGINT NULL,
                created_at DATETIME NULL,
                acted_at DATETIME NULL,
                UNIQUE KEY uniq_ai_suggestion_dedupe (workspace_id,user_id,dedupe_key),
                INDEX idx_ai_suggestion_user (workspace_id,user_id,status,created_at),
                INDEX idx_ai_suggestion_company (workspace_id,company_id,status,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS ai_feedback (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                user_id INT NOT NULL,
                job_id BIGINT NULL,
                proposal_id BIGINT NULL,
                suggestion_id BIGINT NULL,
                rating TINYINT NULL,
                feedback_type VARCHAR(40) NULL,
                comment TEXT NULL,
                created_at DATETIME NULL,
                INDEX idx_ai_feedback_job (workspace_id,job_id,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS ai_rag_sources (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NULL,
                source_type VARCHAR(60) NOT NULL,
                source_key VARCHAR(190) NOT NULL,
                title VARCHAR(255) NOT NULL,
                uri VARCHAR(1000) NULL,
                checksum CHAR(64) NULL,
                metadata_json JSON NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                indexed_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_ai_rag_source (workspace_id,source_type,source_key),
                INDEX idx_ai_rag_company (workspace_id,company_id,status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
    }

    private static function upgrades(PDO $pdo): void
    {
        $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_action_proposals' AND COLUMN_NAME='idempotency_key' LIMIT 1");
        $st->execute();
        if(!$st->fetchColumn()){
            $pdo->exec("ALTER TABLE ai_action_proposals ADD COLUMN idempotency_key VARCHAR(190) NULL AFTER tool_name");
            $pdo->exec("ALTER TABLE ai_action_proposals ADD UNIQUE KEY uniq_ai_action_idempotency (workspace_id,job_id,idempotency_key)");
        }
        $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ai_suggestions' AND COLUMN_NAME='dedupe_key' LIMIT 1");
        $st->execute();
        if(!$st->fetchColumn()){
            $pdo->exec("ALTER TABLE ai_suggestions ADD COLUMN dedupe_key VARCHAR(190) NULL AFTER suggestion_type");
            $pdo->exec("ALTER TABLE ai_suggestions ADD UNIQUE KEY uniq_ai_suggestion_dedupe (workspace_id,user_id,dedupe_key)");
        }
    }

    private static function permissions(PDO $pdo): void
    {
        $defs=[
            ['ai.use','استفاده از دستیار هوشمند','ai',300],
            ['ai.actions.approve','تایید عملیات پیشنهادی ایجنت','ai',301],
            ['ai.workers.manage','مدیریت نودها و توکن‌های موتور AI','ai',302],
            ['ai.audit.view','مشاهده ردپای اجرای AI','ai',303],
        ];
        $ins=$pdo->prepare("INSERT INTO workspace_permissions (permission_key,title,group_key,sort_order)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),group_key=VALUES(group_key),sort_order=VALUES(sort_order)");
        foreach($defs as $d)$ins->execute($d);

        $pid=$pdo->prepare("SELECT id FROM workspace_permissions WHERE permission_key=? LIMIT 1");
        $roles=$pdo->prepare("SELECT id,role_key FROM workspace_roles WHERE workspace_id=?");
        $rp=$pdo->prepare("INSERT IGNORE INTO workspace_role_permissions (role_id,permission_id) VALUES (?,?)");
        foreach($pdo->query("SELECT id FROM workspaces WHERE status='active'")->fetchAll() as $w){
            $roles->execute([(int)$w['id']]);
            foreach($roles->fetchAll() as $r){
                $keys=match($r['role_key']){
                    'owner','workspace_admin'=>array_column($defs,0),
                    'manager','accountant'=>['ai.use','ai.actions.approve','ai.audit.view'],
                    'viewer'=>['ai.use'],
                    default=>[]
                };
                foreach($keys as $key){$pid->execute([$key]);$p=(int)$pid->fetchColumn();if($p)$rp->execute([(int)$r['id'],$p]);}
            }
        }
    }
}
