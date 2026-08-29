<?php
final class AccountingSchema
{
    public const VERSION='6.0.0';

    public static function migrate(PDO $pdo): void
    {
        self::tables($pdo);
        self::permissions($pdo);
        self::defaults($pdo);
    }

    private static function tables(PDO $pdo): void
    {
        $sql=[];

        $sql[]="CREATE TABLE IF NOT EXISTS acc_company_profiles (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            activity_type VARCHAR(80) NULL,
            tax_office_code VARCHAR(80) NULL,
            tax_office_name VARCHAR(190) NULL,
            tax_case_class VARCHAR(120) NULL,
            trade_name VARCHAR(190) NULL,
            business_license_no VARCHAR(120) NULL,
            business_license_date DATE NULL,
            fax VARCHAR(80) NULL,
            mobile VARCHAR(120) NULL,
            email VARCHAR(190) NULL,
            messenger VARCHAR(500) NULL,
            taxpayer_token_type VARCHAR(40) NULL,
            taxpayer_branch_code VARCHAR(40) NULL,
            tax_memory_uid VARCHAR(80) NULL,
            taxpayer_private_key_enc MEDIUMTEXT NULL,
            taxpayer_public_key MEDIUMTEXT NULL,
            taxpayer_connection_status VARCHAR(40) NOT NULL DEFAULT 'not_tested',
            taxpayer_last_tested_at DATETIME NULL,
            extra_json JSON NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_profile (workspace_id,company_id),
            INDEX idx_acc_profile_ws (workspace_id,company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_fiscal_years (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            title VARCHAR(120) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_fiscal_title (workspace_id,company_id,title),
            INDEX idx_acc_fiscal_company (workspace_id,company_id,status,start_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_accounts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            parent_id BIGINT NULL,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(190) NOT NULL,
            level_no INT NOT NULL DEFAULT 1,
            nature VARCHAR(20) NOT NULL DEFAULT 'debit',
            account_type VARCHAR(40) NOT NULL DEFAULT 'asset',
            allow_posting TINYINT(1) NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_account_code (workspace_id,company_id,code),
            INDEX idx_acc_account_parent (workspace_id,company_id,parent_id,active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_parties (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            code VARCHAR(80) NULL,
            name VARCHAR(190) NOT NULL,
            party_type VARCHAR(30) NOT NULL DEFAULT 'both',
            national_id VARCHAR(40) NULL,
            economic_code VARCHAR(80) NULL,
            registration_no VARCHAR(80) NULL,
            mobile VARCHAR(80) NULL,
            phone VARCHAR(80) NULL,
            email VARCHAR(190) NULL,
            address TEXT NULL,
            credit_limit DECIMAL(20,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_acc_party_company (workspace_id,company_id,active,name),
            INDEX idx_acc_party_national (workspace_id,company_id,national_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_cost_centers (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            parent_id BIGINT NULL,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(190) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_cost_center (workspace_id,company_id,code),
            INDEX idx_acc_cost_company (workspace_id,company_id,active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_projects (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(190) NOT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_project (workspace_id,company_id,code),
            INDEX idx_acc_project_company (workspace_id,company_id,active,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_units (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            code VARCHAR(50) NULL,
            name VARCHAR(100) NOT NULL,
            decimal_places INT NOT NULL DEFAULT 3,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_unit_name (workspace_id,company_id,name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_warehouses (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            code VARCHAR(80) NOT NULL,
            name VARCHAR(190) NOT NULL,
            warehouse_type VARCHAR(40) NOT NULL DEFAULT 'general',
            address TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_warehouse (workspace_id,company_id,code),
            INDEX idx_acc_warehouse_company (workspace_id,company_id,active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_item_groups (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            parent_id BIGINT NULL,
            code VARCHAR(80) NULL,
            name VARCHAR(190) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_acc_item_group (workspace_id,company_id,active,parent_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            group_id BIGINT NULL,
            code VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            item_type VARCHAR(40) NOT NULL DEFAULT 'material',
            taxpayer_goods_id VARCHAR(80) NULL,
            base_unit_id BIGINT NULL,
            second_unit_id BIGINT NULL,
            second_rate DECIMAL(20,6) NULL,
            third_unit_id BIGINT NULL,
            third_rate DECIMAL(20,6) NULL,
            purchase_price_1 DECIMAL(20,2) NOT NULL DEFAULT 0,
            purchase_price_2 DECIMAL(20,2) NOT NULL DEFAULT 0,
            purchase_price_3 DECIMAL(20,2) NOT NULL DEFAULT 0,
            barcode VARCHAR(120) NULL,
            track_serial TINYINT(1) NOT NULL DEFAULT 0,
            min_stock DECIMAL(20,4) NOT NULL DEFAULT 0,
            max_stock DECIMAL(20,4) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            extra_json JSON NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_item_code (workspace_id,company_id,code),
            INDEX idx_acc_item_company (workspace_id,company_id,active,name),
            INDEX idx_acc_item_group (workspace_id,company_id,group_id,active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_purchase_docs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            doc_type VARCHAR(60) NOT NULL,
            document_no VARCHAR(120) NOT NULL,
            party_invoice_no VARCHAR(190) NULL,
            document_date DATE NOT NULL,
            party_id BIGINT NOT NULL,
            cost_center_id BIGINT NULL,
            project_id BIGINT NULL,
            warehouse_id BIGINT NULL,
            notes TEXT NULL,
            workflow_status VARCHAR(30) NOT NULL DEFAULT 'draft',
            taxpayer_status VARCHAR(40) NOT NULL DEFAULT 'not_sent',
            account_id BIGINT NULL,
            total_before_discount DECIMAL(20,2) NOT NULL DEFAULT 0,
            discount_total DECIMAL(20,2) NOT NULL DEFAULT 0,
            tax_total DECIMAL(20,2) NOT NULL DEFAULT 0,
            net_total DECIMAL(20,2) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_purchase_no (workspace_id,company_id,doc_type,document_no),
            INDEX idx_acc_purchase_company_date (workspace_id,company_id,document_date,workflow_status),
            INDEX idx_acc_purchase_party (workspace_id,company_id,party_id,document_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_purchase_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            purchase_doc_id BIGINT NOT NULL,
            line_no INT NOT NULL,
            item_id BIGINT NOT NULL,
            unit_id BIGINT NULL,
            description VARCHAR(500) NULL,
            quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
            second_unit_id BIGINT NULL,
            second_quantity DECIMAL(20,4) NULL,
            third_unit_id BIGINT NULL,
            third_quantity DECIMAL(20,4) NULL,
            unit_price DECIMAL(20,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            discount_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
            commission_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
            cost_center_id BIGINT NULL,
            warehouse_id BIGINT NULL,
            project_id BIGINT NULL,
            line_total DECIMAL(20,2) NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            INDEX idx_acc_purchase_line_doc (workspace_id,purchase_doc_id,line_no),
            INDEX idx_acc_purchase_line_item (workspace_id,item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_vouchers (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            fiscal_year_id BIGINT NULL,
            voucher_no VARCHAR(100) NOT NULL,
            voucher_date DATE NOT NULL,
            voucher_type VARCHAR(40) NOT NULL DEFAULT 'general',
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            description VARCHAR(500) NULL,
            source_type VARCHAR(80) NULL,
            source_id BIGINT NULL,
            auto_generated TINYINT(1) NOT NULL DEFAULT 0,
            total_debit DECIMAL(20,2) NOT NULL DEFAULT 0,
            total_credit DECIMAL(20,2) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_voucher_no (workspace_id,company_id,voucher_no),
            INDEX idx_acc_voucher_company_date (workspace_id,company_id,voucher_date,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_voucher_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            voucher_id BIGINT NOT NULL,
            line_no INT NOT NULL,
            account_id BIGINT NOT NULL,
            party_id BIGINT NULL,
            cost_center_id BIGINT NULL,
            project_id BIGINT NULL,
            description VARCHAR(500) NULL,
            debit DECIMAL(20,2) NOT NULL DEFAULT 0,
            credit DECIMAL(20,2) NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            INDEX idx_acc_voucher_line_doc (workspace_id,voucher_id,line_no),
            INDEX idx_acc_voucher_line_account (workspace_id,account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_boms (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            product_item_id BIGINT NOT NULL,
            code VARCHAR(100) NOT NULL,
            name VARCHAR(190) NOT NULL,
            version_no VARCHAR(50) NULL,
            output_qty DECIMAL(20,4) NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_bom_code (workspace_id,company_id,code),
            INDEX idx_acc_bom_product (workspace_id,company_id,product_item_id,active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_bom_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            bom_id BIGINT NOT NULL,
            material_item_id BIGINT NOT NULL,
            unit_id BIGINT NULL,
            quantity DECIMAL(20,6) NOT NULL DEFAULT 0,
            waste_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
            stage_no INT NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            INDEX idx_acc_bom_line (workspace_id,bom_id,stage_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_production_orders (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            order_no VARCHAR(100) NOT NULL,
            product_item_id BIGINT NOT NULL,
            bom_id BIGINT NULL,
            planned_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            actual_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            raw_warehouse_id BIGINT NULL,
            finished_warehouse_id BIGINT NULL,
            cost_center_id BIGINT NULL,
            project_id BIGINT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'planned',
            material_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
            labor_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
            overhead_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
            subcontract_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
            scrap_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
            actual_total_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_prod_order (workspace_id,company_id,order_no),
            INDEX idx_acc_prod_company (workspace_id,company_id,status,start_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_cash_accounts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            account_kind VARCHAR(30) NOT NULL DEFAULT 'bank',
            code VARCHAR(80) NULL,
            name VARCHAR(190) NOT NULL,
            bank_name VARCHAR(190) NULL,
            account_no VARCHAR(120) NULL,
            iban VARCHAR(80) NULL,
            card_no VARCHAR(80) NULL,
            opening_balance DECIMAL(20,2) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_acc_cash_company (workspace_id,company_id,active,account_kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_checks (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            direction VARCHAR(20) NOT NULL DEFAULT 'receivable',
            check_no VARCHAR(100) NOT NULL,
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            due_date DATE NULL,
            party_id BIGINT NULL,
            cash_account_id BIGINT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            notes VARCHAR(500) NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_acc_check_due (workspace_id,company_id,direction,status,due_date),
            INDEX idx_acc_check_no (workspace_id,company_id,check_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_inventory_receipts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            receipt_no VARCHAR(120) NOT NULL,
            receipt_date DATE NOT NULL,
            warehouse_id BIGINT NOT NULL,
            source_type VARCHAR(40) NOT NULL DEFAULT 'purchase',
            purchase_doc_id BIGINT NULL,
            supplier_id BIGINT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'posted',
            notes VARCHAR(1000) NULL,
            created_by INT NULL,
            approved_by INT NULL,
            posted_at DATETIME NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_inventory_receipt_no (workspace_id,company_id,receipt_no),
            INDEX idx_acc_inventory_receipt_source (workspace_id,company_id,purchase_doc_id,status),
            INDEX idx_acc_inventory_receipt_wh (workspace_id,company_id,warehouse_id,receipt_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_inventory_receipt_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            receipt_id BIGINT NOT NULL,
            line_no INT NOT NULL,
            purchase_line_id BIGINT NOT NULL,
            item_id BIGINT NOT NULL,
            expected_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            received_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            accepted_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            rejected_qty DECIMAL(20,4) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(20,2) NOT NULL DEFAULT 0,
            notes VARCHAR(500) NULL,
            created_at DATETIME NULL,
            INDEX idx_acc_inventory_receipt_line (workspace_id,receipt_id,line_no),
            INDEX idx_acc_inventory_purchase_line (workspace_id,purchase_line_id),
            INDEX idx_acc_inventory_receipt_item (workspace_id,item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_stock_movements (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            movement_date DATE NOT NULL,
            movement_type VARCHAR(50) NOT NULL,
            direction VARCHAR(10) NOT NULL,
            warehouse_id BIGINT NOT NULL,
            item_id BIGINT NOT NULL,
            quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(20,2) NOT NULL DEFAULT 0,
            source_type VARCHAR(60) NOT NULL,
            source_id BIGINT NOT NULL,
            source_line_id BIGINT NOT NULL,
            reference_no VARCHAR(120) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'posted',
            created_by INT NULL,
            created_at DATETIME NULL,
            UNIQUE KEY uniq_acc_stock_source (workspace_id,source_type,source_id,source_line_id,direction),
            INDEX idx_acc_stock_position (workspace_id,company_id,warehouse_id,item_id,status,movement_date),
            INDEX idx_acc_stock_item (workspace_id,company_id,item_id,status,movement_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $sql[]="CREATE TABLE IF NOT EXISTS acc_inventory_reservations (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            warehouse_id BIGINT NOT NULL,
            item_id BIGINT NOT NULL,
            source_type VARCHAR(60) NULL,
            source_id BIGINT NULL,
            quantity DECIMAL(20,4) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            expires_at DATETIME NULL,
            notes VARCHAR(500) NULL,
            created_by INT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_acc_inventory_reservation (workspace_id,company_id,warehouse_id,item_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

\n        $sql[]="CREATE TABLE IF NOT EXISTS acc_trade_cases (\n            id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, case_no VARCHAR(120) NOT NULL, purchase_doc_id BIGINT NOT NULL, supplier_id BIGINT NOT NULL, proforma_no VARCHAR(120) NULL, proforma_date DATE NULL, origin_country VARCHAR(120) NULL, destination_country VARCHAR(120) NULL, incoterm VARCHAR(20) NOT NULL, currency_code VARCHAR(10) NOT NULL DEFAULT 'IRR', fx_rate_to_irr DECIMAL(20,6) NOT NULL DEFAULT 1, status VARCHAR(30) NOT NULL DEFAULT 'planning', customs_declaration_no VARCHAR(120) NULL, customs_office VARCHAR(190) NULL, clearance_status VARCHAR(30) NOT NULL DEFAULT 'not_started', customs_entry_date DATE NULL, customs_release_date DATE NULL, notes TEXT NULL, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uniq_acc_trade_case_no (workspace_id,company_id,case_no), UNIQUE KEY uniq_acc_trade_purchase (workspace_id,company_id,purchase_doc_id), INDEX idx_acc_trade_case_status (workspace_id,company_id,status)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";\n\n        $sql[]="CREATE TABLE IF NOT EXISTS acc_trade_shipments (\n            id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, trade_case_id BIGINT NOT NULL, shipment_no VARCHAR(120) NOT NULL, mode VARCHAR(20) NOT NULL, carrier VARCHAR(190) NULL, forwarder VARCHAR(190) NULL, tracking_no VARCHAR(190) NULL, origin_location VARCHAR(190) NULL, destination_location VARCHAR(190) NULL, etd DATE NULL, eta DATE NULL, ata DATE NULL, status VARCHAR(30) NOT NULL DEFAULT 'planned', package_count INT NOT NULL DEFAULT 0, gross_weight_kg DECIMAL(20,4) NOT NULL DEFAULT 0, notes VARCHAR(1000) NULL, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uniq_acc_trade_shipment_no (workspace_id,company_id,shipment_no), INDEX idx_acc_trade_shipment_case (workspace_id,company_id,trade_case_id,status,eta)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";\n\n        $sql[]="CREATE TABLE IF NOT EXISTS acc_trade_costs (\n            id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, trade_case_id BIGINT NOT NULL, shipment_id BIGINT NULL, cost_type VARCHAR(40) NOT NULL, basis VARCHAR(20) NOT NULL, amount DECIMAL(20,4) NOT NULL DEFAULT 0, currency_code VARCHAR(10) NOT NULL DEFAULT 'IRR', fx_rate_to_irr DECIMAL(20,6) NOT NULL DEFAULT 1, amount_irr DECIMAL(20,2) NOT NULL DEFAULT 0, reference_no VARCHAR(190) NULL, status VARCHAR(30) NOT NULL DEFAULT 'active', notes VARCHAR(1000) NULL, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, INDEX idx_acc_trade_cost_case (workspace_id,company_id,trade_case_id,basis,cost_type,status), INDEX idx_acc_trade_cost_shipment (workspace_id,shipment_id,status)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";\n\n        $sql[]="CREATE TABLE IF NOT EXISTS acc_trade_milestones (\n            id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL, trade_case_id BIGINT NOT NULL, shipment_id BIGINT NULL, milestone_type VARCHAR(50) NOT NULL, planned_date DATE NULL, actual_date DATE NULL, status VARCHAR(30) NOT NULL DEFAULT 'planned', reference_no VARCHAR(190) NULL, notes VARCHAR(1000) NULL, created_by INT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, INDEX idx_acc_trade_milestone_case (workspace_id,company_id,trade_case_id,milestone_type,actual_date)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";\n
        $sql[]="CREATE TABLE IF NOT EXISTS acc_module_settings (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            section_key VARCHAR(60) NOT NULL,
            setting_key VARCHAR(120) NOT NULL,
            label VARCHAR(255) NOT NULL,
            control_type VARCHAR(30) NOT NULL DEFAULT 'text',
            options_json JSON NULL,
            value_text MEDIUMTEXT NULL,
            sort_order INT NOT NULL DEFAULT 100,
            source_note VARCHAR(500) NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_acc_setting (workspace_id,company_id,setting_key),
            INDEX idx_acc_setting_section (workspace_id,company_id,section_key,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        foreach($sql as $q)$pdo->exec($q);
    }

    private static function permissions(PDO $pdo): void
    {
        $defs=[
            ['accounting.view','مشاهده ماژول حسابداری صنعتی','accounting',200],
            ['accounting.master.manage','مدیریت اطلاعات پایه حسابداری','accounting',201],
            ['accounting.purchase.view','مشاهده خرید','accounting',202],
            ['accounting.purchase.manage','مدیریت خرید','accounting',203],
            ['accounting.vouchers.view','مشاهده اسناد حسابداری','accounting',204],
            ['accounting.vouchers.manage','مدیریت اسناد حسابداری','accounting',205],
            ['accounting.production.view','مشاهده حسابداری صنعتی و تولید','accounting',206],
            ['accounting.production.manage','مدیریت حسابداری صنعتی و تولید','accounting',207],
            ['accounting.treasury.view','مشاهده خزانه داری حسابداری','accounting',208],
            ['accounting.treasury.manage','مدیریت خزانه داری حسابداری','accounting',209],
            ['accounting.reports.view','مشاهده گزارش های مالی و صنعتی','accounting',210],
            ['accounting.settings.manage','مدیریت تنظیمات ماژول حسابداری','accounting',211],
            ['accounting.taxkeys.manage','مدیریت کلیدهای سامانه مودیان','accounting',212],
            ['inventory.view','مشاهده انبار و موجودی','inventory',220],
            ['inventory.manage','ثبت و مدیریت رسید و موجودی','inventory',221],
            ['procurement.view','مشاهده جریان تأمین و خرید','procurement',230],
            ['procurement.manage','مدیریت جریان تأمین و خرید','procurement',231],
            ['trade.view','مشاهده بازرگانی و لجستیک','trade',240],
            ['trade.manage','مدیریت پرونده، حمل، گمرک و هزینه بازرگانی','trade',241],
        ];
        $ins=$pdo->prepare("INSERT INTO workspace_permissions (permission_key,title,group_key,sort_order)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),group_key=VALUES(group_key),sort_order=VALUES(sort_order)");
        foreach($defs as $d)$ins->execute($d);

        $roleSets=[
            'owner'=>array_column($defs,0),
            'workspace_admin'=>array_column($defs,0),
            'manager'=>[
                'accounting.view','accounting.master.manage','accounting.purchase.view','accounting.purchase.manage',
                'accounting.vouchers.view','accounting.vouchers.manage','accounting.production.view','accounting.production.manage',
                'accounting.treasury.view','accounting.treasury.manage','accounting.reports.view',
                'inventory.view','inventory.manage','procurement.view','procurement.manage','trade.view','trade.manage'
            ],
            'accountant'=>[
                'accounting.view','accounting.master.manage','accounting.purchase.view','accounting.purchase.manage',
                'accounting.vouchers.view','accounting.vouchers.manage','accounting.production.view','accounting.production.manage',
                'accounting.treasury.view','accounting.treasury.manage','accounting.reports.view',
                'inventory.view','inventory.manage','procurement.view','procurement.manage','trade.view','trade.manage'
            ],
            'viewer'=>[
                'accounting.view','accounting.purchase.view','accounting.vouchers.view',
                'accounting.production.view','accounting.treasury.view','accounting.reports.view',
                'inventory.view','procurement.view','trade.view'
            ],
        ];
        $pid=$pdo->prepare("SELECT id FROM workspace_permissions WHERE permission_key=? LIMIT 1");
        $roles=$pdo->prepare("SELECT id,role_key FROM workspace_roles WHERE workspace_id=?");
        $rp=$pdo->prepare("INSERT IGNORE INTO workspace_role_permissions (role_id,permission_id) VALUES (?,?)");
        foreach($pdo->query("SELECT id FROM workspaces WHERE status='active'")->fetchAll() as $w){
            $roles->execute([(int)$w['id']]);
            foreach($roles->fetchAll() as $r){
                foreach($roleSets[$r['role_key']]??[] as $key){
                    $pid->execute([$key]);$permissionId=(int)$pid->fetchColumn();
                    if($permissionId)$rp->execute([(int)$r['id'],$permissionId]);
                }
            }
        }
    }

    public static function seedCompany(int $workspaceId,int $companyId): void
    {
        if($workspaceId<=0||$companyId<=0)return;
        $pdo=pdo();
        $st=$pdo->prepare("SELECT COUNT(*) FROM acc_units WHERE workspace_id=? AND company_id=?");
        $st->execute([$workspaceId,$companyId]);
        if((int)$st->fetchColumn()===0){
            $ins=$pdo->prepare("INSERT INTO acc_units (workspace_id,company_id,code,name,decimal_places,active,created_at,updated_at)
                VALUES (?,?,?,?,3,1,NOW(),NOW())");
            foreach([['EA','عدد'],['KG','کیلوگرم'],['M','متر'],['L','لیتر'],['H','ساعت']] as $u)$ins->execute([$workspaceId,$companyId,$u[0],$u[1]]);
        }
        $st=$pdo->prepare("SELECT COUNT(*) FROM acc_fiscal_years WHERE workspace_id=? AND company_id=?");
        $st->execute([$workspaceId,$companyId]);
        if((int)$st->fetchColumn()===0){
            $jy=(int)explode('/',Jalali::today())[0];
            $start=Jalali::parse(sprintf('%04d/01/01',$jy));
            $end=Jalali::parse(sprintf('%04d/12/29',$jy));
            if(!$end)$end=date('Y-m-d',strtotime(($start?:date('Y-m-d')).' +365 days'));
            $pdo->prepare("INSERT INTO acc_fiscal_years (workspace_id,company_id,title,start_date,end_date,status,is_active,created_at,updated_at)
                VALUES (?,?,?,?,?,'open',1,NOW(),NOW())")->execute([$workspaceId,$companyId,'سال مالی '.$jy,$start?:date('Y-m-d'),$end]);
        }
        $st=$pdo->prepare("SELECT COUNT(*) FROM acc_module_settings WHERE workspace_id=? AND company_id=?");
        $st->execute([$workspaceId,$companyId]);
        if((int)$st->fetchColumn()===0)self::seedSettings($workspaceId,$companyId);
    }

    public static function settingDefinitions(): array
    {
        return [
            ['general','national_id_unique_control','کنترل یکتا بودن کد/شناسه ملی','bool',null,'1',10,'فایل تنظیمات'],
            ['general','party_national_id_required','ثبت کد/شناسه ملی برای تامین‌کنندگان و مشتریان اجباری باشد','bool',null,'0',20,'فایل تنظیمات'],
            ['general','payable_check_reminder_days','یادآوری سررسید چک پرداختنی از چند روز قبل','number',null,'0',30,'فایل تنظیمات'],
            ['general','receivable_check_reminder_days','یادآوری سررسید چک دریافتنی از چند روز قبل','number',null,'0',40,'فایل تنظیمات'],
            ['general','stock_max_reminder','نمایش یادآوری حداکثر موجودی کالا','bool',null,'1',50,'فایل تنظیمات'],
            ['general','stock_min_reminder','نمایش یادآوری حداقل موجودی کالا','bool',null,'1',60,'فایل تنظیمات'],
            ['general','auto_reminder_window','نمایش خودکار پنجره یادآوری','bool',null,'1',70,'فایل تنظیمات'],
            ['general','calendar_type','نوع تقویم','select',['jalali'=>'هجری شمسی','gregorian'=>'میلادی'],'jalali',80,'فایل تنظیمات'],
            ['general','currency_enabled','فعال بودن ارز','bool',null,'0',90,'فایل تنظیمات'],
            ['general','backup_policy','سیاست نسخه پشتیبان','select',['manual'=>'دستی','daily'=>'روزانه','weekly'=>'هفتگی'],'daily',100,'فایل تنظیمات'],

            ['purchase','purchase_auto_subsidiary','تعیین خودکار معین حساب خرید','bool',null,'0',10,'شیت خرید/تنظیمات خرید'],
            ['purchase','purchase_party_invoice_required','شماره فاکتور طرف حساب اجباری باشد','bool',null,'1',20,'شیت خرید'],
            ['purchase','purchase_party_invoice_unique','شماره فاکتور طرف حساب تکراری نباشد','bool',null,'1',30,'شیت خرید'],
            ['purchase','purchase_numbering','روش شماره‌گذاری فاکتور خرید','select',['auto'=>'اتوماتیک','manual'=>'دستی','mixed'=>'اتوماتیک با امکان دستی'],'mixed',40,'شیت خرید'],
            ['purchase','purchase_auto_journal','صدور خودکار سند خرید/برگشت از خرید','bool',null,'0',50,'تنظیمات خرید'],
            ['purchase','purchase_service_hide_warehouse','در خرید خدمات فیلد انبار نمایش داده نشود','bool',null,'1',60,'شیت خرید'],
            ['purchase','purchase_extra_field_empty_policy','کنترل خالی بودن مشخصات اضافی هنگام ذخیره','select',['none'=>'کنترل انجام نشود','warn'=>'هشدار داده شود','error'=>'اعلام خطا شود'],'warn',70,'شیت خرید'],

            ['inventory','negative_stock_policy','کنترل موجودی منفی کالا','select',['none'=>'کنترل انجام نشود','warn'=>'هشدار داده شود','error'=>'اعلام خطا شود'],'warn',10,'تنظیمات انبار'],
            ['inventory','barcode_digits','تعداد ارقام بارکد','number',null,'13',20,'تنظیمات انبار'],
            ['inventory','auto_barcode','بارکدسازی اتوماتیک','bool',null,'0',30,'تنظیمات انبار'],
            ['inventory','weighted_barcode','بارکد وزنی فعال باشد','bool',null,'0',40,'تنظیمات انبار'],
            ['inventory','serial_required','ثبت کالا با سریال ضروری است','bool',null,'0',50,'تنظیمات انبار'],

            ['sales','sales_auto_journal','صدور خودکار سند فروش/برگشت از فروش','bool',null,'0',10,'تنظیمات فروش'],
            ['sales','sales_date_control','کنترل تاریخ در اسناد فروش','bool',null,'1',20,'تنظیمات فروش'],
            ['sales','sales_last_purchase_price_control','کنترل قیمت فروش با آخرین قیمت خرید','select',['none'=>'کنترل نشود','warn'=>'هشدار','error'=>'خطا'],'warn',30,'تنظیمات فروش'],
            ['sales','sales_order_point_control','کنترل موجودی قابل فروش با نقطه سفارش بحرانی','bool',null,'1',40,'تنظیمات فروش'],
            ['sales','sales_auto_warehouse_posting','ثبت اتوماتیک انبار در اسناد فروش','bool',null,'0',50,'تنظیمات فروش'],

            ['payroll','payroll_cost_center_mode','نحوه ثبت مرکز هزینه در اسناد حقوق و دستمزد','select',['document'=>'یک مرکز هزینه برای تمام سند','line'=>'یک مرکز هزینه برای هر سطر'],'line',10,'تنظیمات حقوق و دستمزد'],
            ['payroll','payroll_project_mode','نحوه ثبت پروژه در اسناد حقوق و دستمزد','select',['document'=>'یک پروژه برای تمام سند','line'=>'یک پروژه برای هر سطر'],'line',20,'تنظیمات حقوق و دستمزد'],

            ['tax','taxpayer_enabled','ارتباط با سامانه مودیان فعال باشد','bool',null,'0',10,'مشخصات شرکت/تنظیمات مالیاتی'],
            ['tax','taxpayer_default_invoice_status','وضعیت اولیه مودیان','select',['not_sent'=>'ارسال نشده','waiting'=>'در انتظار واکنش'],'not_sent',20,'شیت خرید'],
            ['tax','taxpayer_connection_test_required','قبل از ارسال صورتحساب تست اتصال انجام شود','bool',null,'1',30,'مشخصات سامانه مودیان'],

            ['treasury','treasury_auto_journal','تنظیم خودکار سند دریافت و پرداخت','bool',null,'0',10,'تنظیمات خزانه داری'],
            ['treasury','negative_bank_cash_policy','کنترل موجودی منفی بانک و صندوق','select',['none'=>'کنترل انجام نشود','warn'=>'هشدار داده شود','error'=>'اعلام خطا شود'],'warn',20,'تنظیمات خزانه داری'],
            ['treasury','duplicate_check_policy','ثبت چک تکراری در یک سند','select',['none'=>'کنترل انجام نشود','warn'=>'هشدار داده شود','error'=>'اعلام خطا شود'],'error',30,'تنظیمات خزانه داری'],
            ['treasury','bounced_check_party_policy','درصورت وجود چک برگشتی برای طرف حساب','select',['none'=>'کنترل انجام نشود','warn'=>'هشدار داده شود','error'=>'اعلام خطا شود'],'warn',40,'تنظیمات خزانه داری'],
            ['treasury','autosave_editing_docs','ذخیره اتوماتیک اسناد در حال ویرایش','bool',null,'1',50,'تنظیمات خزانه داری'],

            ['accounting','voucher_auto_numbering','شماره‌گذاری اتوماتیک اسناد حسابداری','bool',null,'1',10,'تنظیمات حسابداری'],
            ['accounting','voucher_date_control','کنترل تاریخ اسناد حسابداری','bool',null,'1',20,'تنظیمات حسابداری'],
            ['accounting','debit_rows_first','ابتدا ردیف‌های بدهکار و سپس بستانکار ذخیره شود','bool',null,'0',30,'تنظیمات حسابداری'],
            ['accounting','voucher_balance_control','کنترل بالانس بودن اسناد','select',['none'=>'کنترل انجام نشود','warn'=>'هشدار داده شود','error'=>'اعلام خطا شود'],'error',40,'تنظیمات حسابداری'],
            ['accounting','zero_amount_policy','صفر بودن مبلغ سند','select',['none'=>'کنترل انجام نشود','warn'=>'هشدار داده شود','error'=>'اعلام خطا شود'],'error',50,'تنظیمات حسابداری'],
            ['accounting','legal_books_grouping','دفاتر و اسناد قانونی','select',['document'=>'به تفکیک نوع سند','weekly'=>'تجمیع هفتگی','monthly'=>'تجمیع ماهانه'],'document',60,'تنظیمات حسابداری'],
            ['accounting','cost_center_mode','نحوه ثبت مرکز هزینه در اسناد حسابداری','select',['document'=>'یک مرکز هزینه برای تمام سند','line'=>'یک مرکز هزینه برای هر سطر'],'line',70,'تنظیمات حسابداری'],
            ['accounting','project_mode','نحوه ثبت پروژه در اسناد حسابداری','select',['document'=>'یک پروژه برای تمام سند','line'=>'یک پروژه برای هر سطر'],'line',80,'تنظیمات حسابداری'],
        ];
    }

    private static function seedSettings(int $wid,int $cid): void
    {
        $pdo=pdo();
        $ins=$pdo->prepare("INSERT INTO acc_module_settings
            (workspace_id,company_id,section_key,setting_key,label,control_type,options_json,value_text,sort_order,source_note,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE label=VALUES(label),control_type=VALUES(control_type),options_json=VALUES(options_json),
                sort_order=VALUES(sort_order),source_note=VALUES(source_note),updated_at=NOW()");
        foreach(self::settingDefinitions() as $d){
            [$section,$key,$label,$type,$options,$default,$sort,$note]=$d;
            $ins->execute([$wid,$cid,$section,$key,$label,$type,$options?json_encode($options,JSON_UNESCAPED_UNICODE):null,$default,$sort,$note]);
        }
    }

    private static function defaults(PDO $pdo): void
    {
        $st=$pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`) VALUES ('accounting_module_version',?,0,NOW())
            ON DUPLICATE KEY UPDATE `value`=VALUES(`value`),updated_at=NOW()");
        $st->execute([self::VERSION]);
    }
}
