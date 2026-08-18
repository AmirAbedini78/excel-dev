<?php
/**
 * Accounting platform extensions that are intentionally kept separate from the
 * original V6 industrial-accounting schema. This makes upgrades reversible and
 * keeps the stable accounting core readable.
 */
final class AccountingExtendedSchema
{
    public const VERSION='7.0.0';

    public static function migrate(PDO $pdo): void
    {
        foreach(self::tableSql() as $sql)$pdo->exec($sql);
        self::permissions($pdo);
        setting_set('accounting_extended_schema_version',self::VERSION,0);
    }

    private static function tableSql(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS acc_sales_docs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                doc_type VARCHAR(40) NOT NULL DEFAULT 'invoice',
                document_no VARCHAR(120) NOT NULL,
                document_date DATE NOT NULL,
                due_date DATE NULL,
                party_id BIGINT NOT NULL,
                warehouse_id BIGINT NULL,
                cost_center_id BIGINT NULL,
                project_id BIGINT NULL,
                currency_code VARCHAR(12) NOT NULL DEFAULT 'IRR',
                exchange_rate DECIMAL(20,6) NOT NULL DEFAULT 1,
                notes TEXT NULL,
                workflow_status VARCHAR(30) NOT NULL DEFAULT 'draft',
                taxpayer_status VARCHAR(40) NOT NULL DEFAULT 'not_sent',
                taxpayer_reference VARCHAR(160) NULL,
                total_before_discount DECIMAL(20,2) NOT NULL DEFAULT 0,
                discount_total DECIMAL(20,2) NOT NULL DEFAULT 0,
                tax_total DECIMAL(20,2) NOT NULL DEFAULT 0,
                net_total DECIMAL(20,2) NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_acc_sales_no (workspace_id,company_id,doc_type,document_no),
                INDEX idx_acc_sales_company_date (workspace_id,company_id,document_date,workflow_status),
                INDEX idx_acc_sales_party (workspace_id,company_id,party_id,document_date),
                INDEX idx_acc_sales_taxpayer (workspace_id,company_id,taxpayer_status,document_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_sales_lines (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                sales_doc_id BIGINT NOT NULL,
                line_no INT NOT NULL,
                item_id BIGINT NOT NULL,
                unit_id BIGINT NULL,
                warehouse_id BIGINT NULL,
                cost_center_id BIGINT NULL,
                project_id BIGINT NULL,
                description VARCHAR(500) NULL,
                quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
                unit_price DECIMAL(20,2) NOT NULL DEFAULT 0,
                discount_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                discount_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
                tax_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
                tax_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                line_total DECIMAL(20,2) NOT NULL DEFAULT 0,
                created_at DATETIME NULL,
                INDEX idx_acc_sales_line_doc (workspace_id,sales_doc_id,line_no),
                INDEX idx_acc_sales_line_item (workspace_id,item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_inventory_docs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                doc_type VARCHAR(40) NOT NULL,
                document_no VARCHAR(120) NOT NULL,
                document_date DATE NOT NULL,
                source_warehouse_id BIGINT NULL,
                target_warehouse_id BIGINT NULL,
                party_id BIGINT NULL,
                source_type VARCHAR(60) NULL,
                source_id BIGINT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                notes TEXT NULL,
                created_by INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_acc_inventory_no (workspace_id,company_id,doc_type,document_no),
                INDEX idx_acc_inventory_company_date (workspace_id,company_id,document_date,status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_inventory_lines (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                inventory_doc_id BIGINT NOT NULL,
                line_no INT NOT NULL,
                item_id BIGINT NOT NULL,
                unit_id BIGINT NULL,
                quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
                unit_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
                batch_no VARCHAR(120) NULL,
                serial_no VARCHAR(190) NULL,
                expiry_date DATE NULL,
                created_at DATETIME NULL,
                INDEX idx_acc_inventory_line_doc (workspace_id,inventory_doc_id,line_no),
                INDEX idx_acc_inventory_line_item (workspace_id,item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_cash_transactions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                transaction_no VARCHAR(120) NOT NULL,
                transaction_date DATE NOT NULL,
                direction VARCHAR(20) NOT NULL,
                cash_account_id BIGINT NOT NULL,
                party_id BIGINT NULL,
                amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                method VARCHAR(30) NOT NULL DEFAULT 'bank',
                reference_no VARCHAR(190) NULL,
                check_id BIGINT NULL,
                cost_center_id BIGINT NULL,
                project_id BIGINT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                description VARCHAR(500) NULL,
                source_type VARCHAR(60) NULL,
                source_id BIGINT NULL,
                created_by INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_acc_cash_tx_no (workspace_id,company_id,transaction_no),
                INDEX idx_acc_cash_tx_date (workspace_id,company_id,transaction_date,direction,status),
                INDEX idx_acc_cash_tx_party (workspace_id,company_id,party_id,transaction_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_employees (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                personnel_no VARCHAR(80) NOT NULL,
                first_name VARCHAR(120) NOT NULL,
                last_name VARCHAR(120) NOT NULL,
                national_id VARCHAR(20) NULL,
                insurance_no VARCHAR(80) NULL,
                mobile VARCHAR(80) NULL,
                iban VARCHAR(80) NULL,
                hire_date DATE NULL,
                termination_date DATE NULL,
                employment_type VARCHAR(40) NULL,
                base_salary DECIMAL(20,2) NOT NULL DEFAULT 0,
                cost_center_id BIGINT NULL,
                project_id BIGINT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                extra_json JSON NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_acc_employee_no (workspace_id,company_id,personnel_no),
                INDEX idx_acc_employee_company (workspace_id,company_id,active,last_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_payroll_runs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                fiscal_year_id BIGINT NULL,
                period_key VARCHAR(20) NOT NULL,
                title VARCHAR(160) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                gross_total DECIMAL(20,2) NOT NULL DEFAULT 0,
                deductions_total DECIMAL(20,2) NOT NULL DEFAULT 0,
                insurance_total DECIMAL(20,2) NOT NULL DEFAULT 0,
                tax_total DECIMAL(20,2) NOT NULL DEFAULT 0,
                net_total DECIMAL(20,2) NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_acc_payroll_period (workspace_id,company_id,period_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_payroll_lines (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                payroll_run_id BIGINT NOT NULL,
                employee_id BIGINT NOT NULL,
                work_days DECIMAL(9,2) NOT NULL DEFAULT 0,
                base_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                overtime_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                benefits_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                deductions_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                insurance_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                tax_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                net_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                detail_json JSON NULL,
                created_at DATETIME NULL,
                INDEX idx_acc_payroll_line_run (workspace_id,payroll_run_id,employee_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_fixed_assets (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                asset_no VARCHAR(100) NOT NULL,
                title VARCHAR(190) NOT NULL,
                asset_group VARCHAR(120) NULL,
                acquisition_date DATE NOT NULL,
                acquisition_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
                residual_value DECIMAL(20,2) NOT NULL DEFAULT 0,
                useful_life_months INT NULL,
                depreciation_method VARCHAR(40) NOT NULL DEFAULT 'straight_line',
                accumulated_depreciation DECIMAL(20,2) NOT NULL DEFAULT 0,
                location VARCHAR(190) NULL,
                custodian VARCHAR(190) NULL,
                cost_center_id BIGINT NULL,
                project_id BIGINT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                extra_json JSON NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_acc_asset_no (workspace_id,company_id,asset_no),
                INDEX idx_acc_asset_company (workspace_id,company_id,status,asset_group)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_asset_events (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                asset_id BIGINT NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                event_date DATE NOT NULL,
                amount DECIMAL(20,2) NOT NULL DEFAULT 0,
                notes VARCHAR(500) NULL,
                created_by INT NULL,
                created_at DATETIME NULL,
                INDEX idx_acc_asset_event (workspace_id,company_id,asset_id,event_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_period_closes (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                fiscal_year_id BIGINT NOT NULL,
                period_key VARCHAR(20) NOT NULL,
                close_type VARCHAR(30) NOT NULL DEFAULT 'monthly',
                status VARCHAR(30) NOT NULL DEFAULT 'open',
                checklist_json JSON NULL,
                closed_by INT NULL,
                closed_at DATETIME NULL,
                reopened_by INT NULL,
                reopened_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_acc_period_close (workspace_id,company_id,fiscal_year_id,period_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_compliance_rule_packs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL DEFAULT 0,
                jurisdiction VARCHAR(20) NOT NULL DEFAULT 'IR',
                rule_family VARCHAR(80) NOT NULL,
                version VARCHAR(80) NOT NULL,
                effective_from DATE NULL,
                effective_to DATE NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                schema_version VARCHAR(80) NULL,
                payload_json JSON NOT NULL,
                source_reference VARCHAR(1000) NULL,
                checksum CHAR(64) NULL,
                created_by INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uniq_acc_rule_pack (workspace_id,jurisdiction,rule_family,version),
                INDEX idx_acc_rule_effective (jurisdiction,rule_family,status,effective_from,effective_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS acc_tax_submissions (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                workspace_id INT NOT NULL,
                company_id INT NOT NULL,
                submission_type VARCHAR(50) NOT NULL,
                period_key VARCHAR(30) NULL,
                source_type VARCHAR(50) NULL,
                source_id BIGINT NULL,
                external_reference VARCHAR(190) NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'draft',
                request_json JSON NULL,
                response_json JSON NULL,
                submitted_at DATETIME NULL,
                created_by INT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                INDEX idx_acc_tax_submission (workspace_id,company_id,submission_type,status,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        ];
    }

    private static function permissions(PDO $pdo): void
    {
        $defs=[
            ['accounting.sales.view','مشاهده فروش و صورتحساب‌ها','accounting',213],
            ['accounting.sales.manage','مدیریت فروش و صورتحساب‌ها','accounting',214],
            ['accounting.inventory.view','مشاهده گردش و موجودی انبار','accounting',215],
            ['accounting.inventory.manage','مدیریت گردش و موجودی انبار','accounting',216],
            ['accounting.payroll.view','مشاهده حقوق و دستمزد','accounting',217],
            ['accounting.payroll.manage','مدیریت حقوق و دستمزد','accounting',218],
            ['accounting.assets.view','مشاهده دارایی ثابت','accounting',219],
            ['accounting.assets.manage','مدیریت دارایی ثابت','accounting',220],
            ['accounting.close.manage','مدیریت بستن دوره و سال مالی','accounting',221],
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
                    'manager','accountant'=>array_column($defs,0),
                    'viewer'=>array_values(array_filter(array_column($defs,0),fn($k)=>str_ends_with($k,'.view'))),
                    default=>[]
                };
                foreach($keys as $key){$pid->execute([$key]);$p=(int)$pid->fetchColumn();if($p)$rp->execute([(int)$r['id'],$p]);}
            }
        }
    }
}
