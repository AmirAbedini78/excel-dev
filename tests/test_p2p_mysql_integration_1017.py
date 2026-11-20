"""Isolated MySQL integration for v10.17 P2P hardening.

Requires disposable container (default localhost:3307) and pymysql.
Never connects to production. Skips cleanly when runtime unavailable.
"""
from __future__ import annotations

import os
import pathlib
import threading
import time
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

HOST = os.environ.get("ERPSMART_P2P_MYSQL_HOST", "127.0.0.1")
PORT = int(os.environ.get("ERPSMART_P2P_MYSQL_PORT", "3307"))
USER = os.environ.get("ERPSMART_P2P_MYSQL_USER", "p2p")
PASSWORD = os.environ.get("ERPSMART_P2P_MYSQL_PASSWORD", "p2p_test")
DATABASE = os.environ.get("ERPSMART_P2P_MYSQL_DB", "erpsmart_p2p_test")


def _pymysql():
    try:
        import pymysql  # type: ignore
        return pymysql
    except ImportError:
        return None


def _connect(**kwargs):
    pymysql = _pymysql()
    if pymysql is None:
        raise unittest.SkipTest("pymysql not installed")
    try:
        return pymysql.connect(
            host=HOST,
            port=PORT,
            user=USER,
            password=PASSWORD,
            database=DATABASE,
            charset="utf8mb4",
            autocommit=False,
            connect_timeout=3,
            **kwargs,
        )
    except Exception as exc:  # noqa: BLE001 — surface as skip for missing sandbox
        raise unittest.SkipTest(f"MySQL sandbox unavailable: {exc}") from exc


def _exec_script(cur, sql: str):
    for stmt in sql.split(";"):
        s = stmt.strip()
        if s:
            cur.execute(s)


SCHEMA = """
CREATE TABLE IF NOT EXISTS companies (
  id INT PRIMARY KEY, workspace_id INT NOT NULL, active TINYINT NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS workspace_modules (
  workspace_id INT NOT NULL, module_key VARCHAR(80) NOT NULL, enabled TINYINT NOT NULL DEFAULT 0,
  config_json JSON NULL, updated_by INT NULL, updated_at DATETIME NULL,
  PRIMARY KEY (workspace_id, module_key)
);
CREATE TABLE IF NOT EXISTS acc_purchase_docs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL, company_id INT NOT NULL, doc_type VARCHAR(64) NOT NULL,
  document_no VARCHAR(64) NOT NULL, party_invoice_no VARCHAR(120) NULL,
  document_date DATE NOT NULL, party_id BIGINT NOT NULL,
  cost_center_id BIGINT NULL, project_id BIGINT NULL, warehouse_id BIGINT NULL,
  notes TEXT NULL, workflow_status VARCHAR(32) NOT NULL, taxpayer_status VARCHAR(32) NOT NULL,
  total_before_discount DECIMAL(20,2) NOT NULL DEFAULT 0, discount_total DECIMAL(20,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(20,2) NOT NULL DEFAULT 0, net_total DECIMAL(20,2) NOT NULL DEFAULT 0,
  created_by INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
);
CREATE TABLE IF NOT EXISTS acc_purchase_lines (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, purchase_doc_id BIGINT NOT NULL,
  line_no INT NOT NULL, item_id BIGINT NOT NULL, unit_id BIGINT NULL, description VARCHAR(255) NULL,
  quantity DECIMAL(20,4) NOT NULL, unit_price DECIMAL(20,2) NOT NULL,
  discount_amount DECIMAL(20,2) NOT NULL DEFAULT 0, discount_percent DECIMAL(20,4) NOT NULL DEFAULT 0,
  warehouse_id BIGINT NULL, cost_center_id BIGINT NULL, project_id BIGINT NULL,
  line_total DECIMAL(20,2) NOT NULL, created_at DATETIME NOT NULL
);
CREATE TABLE IF NOT EXISTS acc_inventory_receipts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, company_id INT NOT NULL,
  purchase_doc_id BIGINT NOT NULL, warehouse_id BIGINT NOT NULL, supplier_id BIGINT NULL,
  status VARCHAR(32) NOT NULL, receipt_date DATE NOT NULL
);
CREATE TABLE IF NOT EXISTS acc_inventory_receipt_lines (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, receipt_id BIGINT NOT NULL,
  purchase_line_id BIGINT NOT NULL, item_id BIGINT NOT NULL,
  accepted_qty DECIMAL(20,4) NOT NULL DEFAULT 0, rejected_qty DECIMAL(20,4) NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS erp_p2p_vendor_bills (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL, company_id INT NOT NULL,
  purchase_order_id BIGINT NOT NULL, supplier_id BIGINT NOT NULL,
  supplier_invoice_no VARCHAR(120) NOT NULL, invoice_date DATE NOT NULL,
  payload_hash CHAR(64) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending',
  revision INT NOT NULL DEFAULT 1, canonical_doc_id BIGINT NULL,
  total_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
  submitted_by INT NOT NULL, reviewed_by INT NULL, reviewed_at DATETIME NULL,
  review_note VARCHAR(1000) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_p2p_supplier_invoice (workspace_id,company_id,supplier_id,supplier_invoice_no),
  UNIQUE KEY uq_p2p_canonical (workspace_id,canonical_doc_id)
);
CREATE TABLE IF NOT EXISTS erp_p2p_vendor_bill_lines (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT NOT NULL, bill_id BIGINT NOT NULL,
  purchase_line_id BIGINT NOT NULL, item_id BIGINT NOT NULL,
  quantity DECIMAL(20,4) NOT NULL, unit_price DECIMAL(20,2) NOT NULL, line_total DECIMAL(20,2) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_p2p_bill_line (workspace_id,bill_id,purchase_line_id)
);
CREATE TABLE IF NOT EXISTS erp_p2p_company_policy (
  workspace_id INT NOT NULL, company_id INT NOT NULL,
  enforce_goods_match TINYINT(1) NOT NULL DEFAULT 1,
  allow_service_manual TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT NULL, updated_at DATETIME NOT NULL,
  PRIMARY KEY (workspace_id, company_id)
);
CREATE TABLE IF NOT EXISTS erp_p2p_bill_revisions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL, company_id INT NOT NULL, bill_id BIGINT NOT NULL,
  revision INT NOT NULL, status VARCHAR(20) NOT NULL, payload_hash CHAR(64) NOT NULL,
  total_amount DECIMAL(20,2) NOT NULL, lines_json MEDIUMTEXT NOT NULL,
  review_note VARCHAR(1000) NULL, reviewed_by INT NULL, amended_by INT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_p2p_bill_rev (workspace_id,bill_id,revision)
);
CREATE TABLE IF NOT EXISTS erp_p2p_policy_exceptions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  workspace_id INT NOT NULL, company_id INT NOT NULL, source_path VARCHAR(80) NOT NULL,
  reason VARCHAR(1000) NOT NULL, actor_user_id INT NOT NULL, created_doc_id BIGINT NULL,
  created_at DATETIME NOT NULL
);
CREATE TABLE IF NOT EXISTS acc_vouchers (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT, company_id INT);
CREATE TABLE IF NOT EXISTS acc_stock_movements (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT, company_id INT);
CREATE TABLE IF NOT EXISTS acc_checks (id BIGINT AUTO_INCREMENT PRIMARY KEY, workspace_id INT, company_id INT);
"""


class P2PMysqlIntegration1017(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.conn = _connect()
        with cls.conn.cursor() as cur:
            cur.execute("SET FOREIGN_KEY_CHECKS=0")
            for table in (
                "erp_p2p_policy_exceptions",
                "erp_p2p_bill_revisions",
                "erp_p2p_vendor_bill_lines",
                "erp_p2p_vendor_bills",
                "erp_p2p_company_policy",
                "acc_inventory_receipt_lines",
                "acc_inventory_receipts",
                "acc_purchase_lines",
                "acc_purchase_docs",
                "workspace_modules",
                "companies",
                "acc_vouchers",
                "acc_stock_movements",
                "acc_checks",
            ):
                cur.execute(f"DROP TABLE IF EXISTS {table}")
            _exec_script(cur, SCHEMA)
            cur.execute("INSERT INTO companies (id,workspace_id,active) VALUES (1,1,1),(2,1,1),(3,2,1)")
            cur.execute("INSERT INTO workspace_modules (workspace_id,module_key,enabled,updated_at) VALUES (1,'p2p',1,NOW())")
            cur.execute(
                "INSERT INTO erp_p2p_company_policy (workspace_id,company_id,enforce_goods_match,allow_service_manual,updated_at) VALUES (1,1,1,1,NOW())"
            )
            # PO 10 units company 1
            cur.execute(
                """INSERT INTO acc_purchase_docs
                (workspace_id,company_id,doc_type,document_no,document_date,party_id,warehouse_id,notes,workflow_status,taxpayer_status,
                 discount_total,tax_total,net_total,created_by,created_at,updated_at)
                VALUES (1,1,'purchase_order_goods','PO-10','2026-01-01',10,1,'','approved','not_sent',0,0,100000,1,NOW(),NOW())"""
            )
            po_id = cur.lastrowid
            cur.execute(
                """INSERT INTO acc_purchase_lines
                (workspace_id,purchase_doc_id,line_no,item_id,quantity,unit_price,line_total,created_at)
                VALUES (1,%s,1,100,10.0000,10000.00,100000.00,NOW())""",
                (po_id,),
            )
            line_id = cur.lastrowid
            cur.execute(
                """INSERT INTO acc_inventory_receipts
                (workspace_id,company_id,purchase_doc_id,warehouse_id,supplier_id,status,receipt_date)
                VALUES (1,1,%s,1,10,'posted','2026-01-02')""",
                (po_id,),
            )
            receipt_id = cur.lastrowid
            cur.execute(
                """INSERT INTO acc_inventory_receipt_lines
                (workspace_id,receipt_id,purchase_line_id,item_id,accepted_qty,rejected_qty)
                VALUES (1,%s,%s,100,10.0000,0)""",
                (receipt_id, line_id),
            )
            cls.po_id = po_id
            cls.line_id = line_id
        cls.conn.commit()

    @classmethod
    def tearDownClass(cls):
        try:
            cls.conn.close()
        except Exception:
            pass

    def test_unique_supplier_invoice_and_partial_bills(self):
        conn = _connect()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """INSERT INTO erp_p2p_vendor_bills
                    (workspace_id,company_id,purchase_order_id,supplier_id,supplier_invoice_no,invoice_date,payload_hash,status,revision,total_amount,submitted_by,created_at,updated_at)
                    VALUES (1,1,%s,10,'INV-A','2026-01-03','h1','matched',1,40000.00,2,NOW(),NOW())""",
                    (self.po_id,),
                )
                bill1 = cur.lastrowid
                cur.execute(
                    """INSERT INTO erp_p2p_vendor_bill_lines
                    (workspace_id,bill_id,purchase_line_id,item_id,quantity,unit_price,line_total,created_at)
                    VALUES (1,%s,%s,100,4.0000,10000.00,40000.00,NOW())""",
                    (bill1, self.line_id),
                )
                cur.execute(
                    """INSERT INTO erp_p2p_vendor_bills
                    (workspace_id,company_id,purchase_order_id,supplier_id,supplier_invoice_no,invoice_date,payload_hash,status,revision,total_amount,submitted_by,created_at,updated_at)
                    VALUES (1,1,%s,10,'INV-B','2026-01-03','h2','matched',1,60000.00,2,NOW(),NOW())""",
                    (self.po_id,),
                )
                bill2 = cur.lastrowid
                cur.execute(
                    """INSERT INTO erp_p2p_vendor_bill_lines
                    (workspace_id,bill_id,purchase_line_id,item_id,quantity,unit_price,line_total,created_at)
                    VALUES (1,%s,%s,100,6.0000,10000.00,60000.00,NOW())""",
                    (bill2, self.line_id),
                )
                cur.execute(
                    """SELECT COALESCE(SUM(bl.quantity),0) FROM erp_p2p_vendor_bill_lines bl
                    JOIN erp_p2p_vendor_bills b ON b.id=bl.bill_id
                    WHERE b.workspace_id=1 AND b.company_id=1 AND b.purchase_order_id=%s AND b.status='matched'""",
                    (self.po_id,),
                )
                billed = float(cur.fetchone()[0])
                self.assertEqual(billed, 10.0)
                # third bill quantity would overbill — enforce at application; unique invoice still holds
                with self.assertRaises(Exception):
                    cur.execute(
                        """INSERT INTO erp_p2p_vendor_bills
                        (workspace_id,company_id,purchase_order_id,supplier_id,supplier_invoice_no,invoice_date,payload_hash,status,revision,total_amount,submitted_by,created_at,updated_at)
                        VALUES (1,1,%s,10,'INV-A','2026-01-04','h3','pending',1,10000.00,2,NOW(),NOW())""",
                        (self.po_id,),
                    )
            conn.commit()
        finally:
            conn.close()

    def test_cross_company_isolation(self):
        conn = _connect()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """INSERT INTO erp_p2p_vendor_bills
                    (workspace_id,company_id,purchase_order_id,supplier_id,supplier_invoice_no,invoice_date,payload_hash,status,revision,total_amount,submitted_by,created_at,updated_at)
                    VALUES (1,2,%s,10,'INV-C2','2026-01-03','hx','pending',1,1000.00,2,NOW(),NOW())""",
                    (self.po_id,),
                )
                cur.execute(
                    "SELECT COUNT(*) FROM erp_p2p_vendor_bills WHERE workspace_id=1 AND company_id=1 AND supplier_invoice_no='INV-C2'"
                )
                self.assertEqual(cur.fetchone()[0], 0)
            conn.commit()
        finally:
            conn.close()

    def test_concurrent_approve_only_one_wins(self):
        conn = _connect()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    """INSERT INTO erp_p2p_vendor_bills
                    (workspace_id,company_id,purchase_order_id,supplier_id,supplier_invoice_no,invoice_date,payload_hash,status,revision,total_amount,submitted_by,created_at,updated_at)
                    VALUES (1,1,%s,10,'INV-RACE','2026-01-05','hrace','pending',1,10000.00,2,NOW(),NOW())""",
                    (self.po_id,),
                )
                bill_id = cur.lastrowid
            conn.commit()
        finally:
            conn.close()

        results = []
        lock = threading.Lock()

        def worker(tag):
            c = _connect()
            try:
                c.begin()
                with c.cursor() as cur:
                    cur.execute(
                        "SELECT id,status FROM erp_p2p_vendor_bills WHERE id=%s AND workspace_id=1 AND company_id=1 LIMIT 1 FOR UPDATE",
                        (bill_id,),
                    )
                    row = cur.fetchone()
                    time.sleep(0.05)
                    if row and row[1] == "pending":
                        cur.execute(
                            "UPDATE erp_p2p_vendor_bills SET status='matched',reviewed_by=%s,reviewed_at=NOW(),updated_at=NOW() WHERE id=%s AND status='pending'",
                            (tag, bill_id),
                        )
                        ok = cur.rowcount == 1
                    else:
                        ok = False
                c.commit()
                with lock:
                    results.append(ok)
            except Exception:
                c.rollback()
                with lock:
                    results.append(False)
            finally:
                c.close()

        t1 = threading.Thread(target=worker, args=(101,))
        t2 = threading.Thread(target=worker, args=(102,))
        t1.start()
        t2.start()
        t1.join()
        t2.join()
        self.assertEqual(sum(1 for x in results if x), 1)

    def test_no_gl_or_stock_side_effects_in_fixture(self):
        conn = _connect()
        try:
            with conn.cursor() as cur:
                cur.execute("SELECT COUNT(*) FROM acc_vouchers")
                self.assertEqual(cur.fetchone()[0], 0)
                cur.execute("SELECT COUNT(*) FROM acc_stock_movements")
                self.assertEqual(cur.fetchone()[0], 0)
                cur.execute("SELECT COUNT(*) FROM acc_checks")
                self.assertEqual(cur.fetchone()[0], 0)
        finally:
            conn.close()


if __name__ == "__main__":
    unittest.main()
