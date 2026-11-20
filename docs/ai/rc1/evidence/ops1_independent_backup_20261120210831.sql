-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: erpsmart_rc1_tr1_test
-- ------------------------------------------------------
-- Server version	8.0.46

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `acc_accounts`
--

DROP TABLE IF EXISTS `acc_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_accounts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'asset',
  `nature` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'debit',
  `allow_posting` tinyint NOT NULL DEFAULT '1',
  `active` tinyint NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_accounts`
--

LOCK TABLES `acc_accounts` WRITE;
/*!40000 ALTER TABLE `acc_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_cash_accounts`
--

DROP TABLE IF EXISTS `acc_cash_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_cash_accounts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `account_kind` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bank',
  `code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bank_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `iban` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gl_account_id` bigint DEFAULT NULL,
  `opening_balance` decimal(20,2) NOT NULL DEFAULT '0.00',
  `active` tinyint NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_cash_accounts`
--

LOCK TABLES `acc_cash_accounts` WRITE;
/*!40000 ALTER TABLE `acc_cash_accounts` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_cash_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_cash_transactions`
--

DROP TABLE IF EXISTS `acc_cash_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_cash_transactions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `transaction_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_date` date NOT NULL,
  `direction` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cash_account_id` bigint NOT NULL,
  `party_id` bigint DEFAULT NULL,
  `amount` decimal(20,2) NOT NULL DEFAULT '0.00',
  `method` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bank',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_cash_transactions`
--

LOCK TABLES `acc_cash_transactions` WRITE;
/*!40000 ALTER TABLE `acc_cash_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_cash_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_fiscal_years`
--

DROP TABLE IF EXISTS `acc_fiscal_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_fiscal_years` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `title` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `is_active` tinyint NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_fiscal_years`
--

LOCK TABLES `acc_fiscal_years` WRITE;
/*!40000 ALTER TABLE `acc_fiscal_years` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_fiscal_years` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_inventory_receipt_lines`
--

DROP TABLE IF EXISTS `acc_inventory_receipt_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_inventory_receipt_lines` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `receipt_id` bigint NOT NULL,
  `line_no` int NOT NULL,
  `purchase_line_id` bigint NOT NULL,
  `item_id` bigint NOT NULL,
  `expected_qty` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `received_qty` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `accepted_qty` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `rejected_qty` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `unit_cost` decimal(20,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(20,2) NOT NULL DEFAULT '0.00',
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_inventory_receipt_lines`
--

LOCK TABLES `acc_inventory_receipt_lines` WRITE;
/*!40000 ALTER TABLE `acc_inventory_receipt_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_inventory_receipt_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_inventory_receipts`
--

DROP TABLE IF EXISTS `acc_inventory_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_inventory_receipts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `receipt_no` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_date` date NOT NULL,
  `warehouse_id` bigint NOT NULL,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_doc_id` bigint NOT NULL,
  `supplier_id` bigint DEFAULT NULL,
  `status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_inventory_receipts`
--

LOCK TABLES `acc_inventory_receipts` WRITE;
/*!40000 ALTER TABLE `acc_inventory_receipts` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_inventory_receipts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_items`
--

DROP TABLE IF EXISTS `acc_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_items` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'goods',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_items`
--

LOCK TABLES `acc_items` WRITE;
/*!40000 ALTER TABLE `acc_items` DISABLE KEYS */;
INSERT INTO `acc_items` VALUES (1,10,1,'SKU1','Item1','goods');
/*!40000 ALTER TABLE `acc_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_parties`
--

DROP TABLE IF EXISTS `acc_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_parties` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_parties`
--

LOCK TABLES `acc_parties` WRITE;
/*!40000 ALTER TABLE `acc_parties` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_parties` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_period_closes`
--

DROP TABLE IF EXISTS `acc_period_closes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_period_closes` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `fiscal_year_id` bigint NOT NULL,
  `period_key` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `close_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `closed_by` int DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acc_period_close` (`workspace_id`,`company_id`,`fiscal_year_id`,`period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_period_closes`
--

LOCK TABLES `acc_period_closes` WRITE;
/*!40000 ALTER TABLE `acc_period_closes` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_period_closes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_purchase_docs`
--

DROP TABLE IF EXISTS `acc_purchase_docs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_purchase_docs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `doc_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_no` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_invoice_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_date` date NOT NULL,
  `party_id` bigint NOT NULL,
  `cost_center_id` bigint DEFAULT NULL,
  `project_id` bigint DEFAULT NULL,
  `warehouse_id` bigint DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `workflow_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `taxpayer_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_before_discount` decimal(20,2) NOT NULL DEFAULT '0.00',
  `discount_total` decimal(20,2) NOT NULL DEFAULT '0.00',
  `tax_total` decimal(20,2) NOT NULL DEFAULT '0.00',
  `net_total` decimal(20,2) NOT NULL DEFAULT '0.00',
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_purchase_docs`
--

LOCK TABLES `acc_purchase_docs` WRITE;
/*!40000 ALTER TABLE `acc_purchase_docs` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_purchase_docs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_purchase_lines`
--

DROP TABLE IF EXISTS `acc_purchase_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_purchase_lines` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `purchase_doc_id` bigint NOT NULL,
  `line_no` int NOT NULL,
  `item_id` bigint NOT NULL,
  `unit_id` bigint DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(20,4) NOT NULL,
  `unit_price` decimal(20,2) NOT NULL,
  `discount_amount` decimal(20,2) NOT NULL DEFAULT '0.00',
  `discount_percent` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `commission_percent` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `warehouse_id` bigint DEFAULT NULL,
  `cost_center_id` bigint DEFAULT NULL,
  `project_id` bigint DEFAULT NULL,
  `line_total` decimal(20,2) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_purchase_lines`
--

LOCK TABLES `acc_purchase_lines` WRITE;
/*!40000 ALTER TABLE `acc_purchase_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_purchase_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_stock_movements`
--

DROP TABLE IF EXISTS `acc_stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_stock_movements` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `movement_date` date NOT NULL,
  `movement_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `warehouse_id` bigint NOT NULL,
  `item_id` bigint NOT NULL,
  `quantity` decimal(20,4) NOT NULL,
  `unit_cost` decimal(20,2) NOT NULL DEFAULT '0.00',
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint NOT NULL,
  `source_line_id` bigint DEFAULT NULL,
  `reference_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_stock_movements`
--

LOCK TABLES `acc_stock_movements` WRITE;
/*!40000 ALTER TABLE `acc_stock_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_voucher_lines`
--

DROP TABLE IF EXISTS `acc_voucher_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_voucher_lines` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `voucher_id` bigint NOT NULL,
  `line_no` int NOT NULL,
  `account_id` bigint NOT NULL,
  `party_id` bigint DEFAULT NULL,
  `cost_center_id` bigint DEFAULT NULL,
  `project_id` bigint DEFAULT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `debit` decimal(20,2) NOT NULL DEFAULT '0.00',
  `credit` decimal(20,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_voucher_lines`
--

LOCK TABLES `acc_voucher_lines` WRITE;
/*!40000 ALTER TABLE `acc_voucher_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_voucher_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_vouchers`
--

DROP TABLE IF EXISTS `acc_vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_vouchers` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `fiscal_year_id` bigint DEFAULT NULL,
  `voucher_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `voucher_date` date NOT NULL,
  `voucher_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_type` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint DEFAULT NULL,
  `auto_generated` tinyint(1) NOT NULL DEFAULT '0',
  `total_debit` decimal(20,2) NOT NULL DEFAULT '0.00',
  `total_credit` decimal(20,2) NOT NULL DEFAULT '0.00',
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_acc_voucher_no` (`workspace_id`,`company_id`,`voucher_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_vouchers`
--

LOCK TABLES `acc_vouchers` WRITE;
/*!40000 ALTER TABLE `acc_vouchers` DISABLE KEYS */;
/*!40000 ALTER TABLE `acc_vouchers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `acc_warehouses`
--

DROP TABLE IF EXISTS `acc_warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `acc_warehouses` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `acc_warehouses`
--

LOCK TABLES `acc_warehouses` WRITE;
/*!40000 ALTER TABLE `acc_warehouses` DISABLE KEYS */;
INSERT INTO `acc_warehouses` VALUES (1,10,1,'WH1','Warehouse 1');
/*!40000 ALTER TABLE `acc_warehouses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` int NOT NULL,
  `workspace_id` int NOT NULL,
  `active` tinyint NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES (1,10,1),(2,10,1);
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_ap_allocations`
--

DROP TABLE IF EXISTS `erp_ap_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_ap_allocations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `payment_id` bigint NOT NULL,
  `invoice_doc_id` bigint NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `idempotency_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int NOT NULL,
  `reversed_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_alloc_idem` (`workspace_id`,`company_id`,`idempotency_key`),
  KEY `idx_ap_alloc_payment` (`workspace_id`,`payment_id`,`status`),
  KEY `idx_ap_alloc_invoice` (`workspace_id`,`invoice_doc_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_ap_allocations`
--

LOCK TABLES `erp_ap_allocations` WRITE;
/*!40000 ALTER TABLE `erp_ap_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_ap_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_ap_company_maps`
--

DROP TABLE IF EXISTS `erp_ap_company_maps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_ap_company_maps` (
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `payable_account_id` bigint NOT NULL,
  `purchase_account_id` bigint NOT NULL,
  `clearing_account_id` bigint DEFAULT NULL,
  `input_vat_account_id` bigint DEFAULT NULL,
  `ppv_account_id` bigint DEFAULT NULL,
  `valuation_model` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invoice_based',
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`workspace_id`,`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_ap_company_maps`
--

LOCK TABLES `erp_ap_company_maps` WRITE;
/*!40000 ALTER TABLE `erp_ap_company_maps` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_ap_company_maps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_ap_payments`
--

DROP TABLE IF EXISTS `erp_ap_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_ap_payments` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `party_id` bigint NOT NULL,
  `cash_account_id` bigint NOT NULL,
  `payment_method` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bank_transfer',
  `payment_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `voucher_id` bigint DEFAULT NULL,
  `cash_tx_id` bigint DEFAULT NULL,
  `reverse_cash_tx_id` bigint DEFAULT NULL,
  `cash_gl_account_id` bigint DEFAULT NULL,
  `ap_gl_account_id` bigint DEFAULT NULL,
  `idempotency_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IRR',
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int NOT NULL,
  `approved_by` int DEFAULT NULL,
  `posted_by` int DEFAULT NULL,
  `reversed_by` int DEFAULT NULL,
  `reversal_voucher_id` bigint DEFAULT NULL,
  `reversal_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_payment_no` (`workspace_id`,`company_id`,`payment_no`),
  UNIQUE KEY `uq_ap_payment_idem` (`workspace_id`,`company_id`,`idempotency_key`),
  KEY `idx_ap_payment_party` (`workspace_id`,`company_id`,`party_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_ap_payments`
--

LOCK TABLES `erp_ap_payments` WRITE;
/*!40000 ALTER TABLE `erp_ap_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_ap_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_ap_postings`
--

DROP TABLE IF EXISTS `erp_ap_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_ap_postings` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `invoice_doc_id` bigint NOT NULL,
  `voucher_id` bigint NOT NULL,
  `idempotency_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `net_total` decimal(20,2) NOT NULL,
  `payable_account_id` bigint DEFAULT NULL,
  `purchase_account_id` bigint DEFAULT NULL,
  `input_vat_account_id` bigint DEFAULT NULL,
  `currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IRR',
  `valuation_model` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invoice_based',
  `posted_by` int NOT NULL,
  `posted_at` datetime NOT NULL,
  `reversed_by` int DEFAULT NULL,
  `reversal_voucher_id` bigint DEFAULT NULL,
  `reversal_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_invoice` (`workspace_id`,`company_id`,`invoice_doc_id`),
  UNIQUE KEY `uq_ap_idem` (`workspace_id`,`company_id`,`idempotency_key`),
  UNIQUE KEY `uq_ap_voucher` (`workspace_id`,`voucher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_ap_postings`
--

LOCK TABLES `erp_ap_postings` WRITE;
/*!40000 ALTER TABLE `erp_ap_postings` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_ap_postings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_ap_workflow`
--

DROP TABLE IF EXISTS `erp_ap_workflow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_ap_workflow` (
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `invoice_doc_id` bigint NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `request_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `validated_by` int DEFAULT NULL,
  `validated_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`workspace_id`,`company_id`,`invoice_doc_id`),
  KEY `idx_ap_wf_status` (`workspace_id`,`company_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_ap_workflow`
--

LOCK TABLES `erp_ap_workflow` WRITE;
/*!40000 ALTER TABLE `erp_ap_workflow` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_ap_workflow` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_ar_allocations`
--

DROP TABLE IF EXISTS `erp_ar_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_ar_allocations` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `receipt_id` bigint NOT NULL,
  `invoice_doc_id` bigint NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `idempotency_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` int NOT NULL,
  `reversed_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ar_alloc_idem` (`workspace_id`,`company_id`,`idempotency_key`),
  KEY `idx_ar_alloc_receipt` (`workspace_id`,`receipt_id`,`status`),
  KEY `idx_ar_alloc_invoice` (`workspace_id`,`invoice_doc_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_ar_allocations`
--

LOCK TABLES `erp_ar_allocations` WRITE;
/*!40000 ALTER TABLE `erp_ar_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_ar_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_ar_cash_gl_map_audit`
--

DROP TABLE IF EXISTS `erp_ar_cash_gl_map_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_ar_cash_gl_map_audit` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `cash_account_id` bigint NOT NULL,
  `old_gl_account_id` bigint DEFAULT NULL,
  `new_gl_account_id` bigint DEFAULT NULL,
  `changed_by` int NOT NULL,
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ar_cash_gl_audit` (`workspace_id`,`company_id`,`cash_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_ar_cash_gl_map_audit`
--

LOCK TABLES `erp_ar_cash_gl_map_audit` WRITE;
/*!40000 ALTER TABLE `erp_ar_cash_gl_map_audit` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_ar_cash_gl_map_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_ar_company_maps`
--

DROP TABLE IF EXISTS `erp_ar_company_maps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_ar_company_maps` (
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `receivable_account_id` bigint NOT NULL,
  `revenue_account_id` bigint NOT NULL,
  `tax_account_id` bigint DEFAULT NULL,
  `cash_gl_account_id` bigint DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`workspace_id`,`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_ar_company_maps`
--

LOCK TABLES `erp_ar_company_maps` WRITE;
/*!40000 ALTER TABLE `erp_ar_company_maps` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_ar_company_maps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_ar_postings`
--

DROP TABLE IF EXISTS `erp_ar_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_ar_postings` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `invoice_doc_id` bigint NOT NULL,
  `voucher_id` bigint NOT NULL,
  `idempotency_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `net_total` decimal(20,2) NOT NULL,
  `receivable_account_id` bigint DEFAULT NULL,
  `revenue_account_id` bigint DEFAULT NULL,
  `tax_account_id` bigint DEFAULT NULL,
  `currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IRR',
  `posted_by` int NOT NULL,
  `posted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ar_invoice` (`workspace_id`,`company_id`,`invoice_doc_id`),
  UNIQUE KEY `uq_ar_idem` (`workspace_id`,`company_id`,`idempotency_key`),
  UNIQUE KEY `uq_ar_voucher` (`workspace_id`,`voucher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_ar_postings`
--

LOCK TABLES `erp_ar_postings` WRITE;
/*!40000 ALTER TABLE `erp_ar_postings` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_ar_postings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_ar_receipts`
--

DROP TABLE IF EXISTS `erp_ar_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_ar_receipts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `party_id` bigint NOT NULL,
  `cash_account_id` bigint NOT NULL,
  `payment_method` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bank_transfer',
  `receipt_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `receipt_date` date NOT NULL,
  `amount` decimal(20,2) NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `voucher_id` bigint DEFAULT NULL,
  `cash_tx_id` bigint DEFAULT NULL,
  `reverse_cash_tx_id` bigint DEFAULT NULL,
  `cash_gl_account_id` bigint DEFAULT NULL,
  `ar_gl_account_id` bigint DEFAULT NULL,
  `idempotency_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IRR',
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int NOT NULL,
  `approved_by` int DEFAULT NULL,
  `posted_by` int DEFAULT NULL,
  `reversed_by` int DEFAULT NULL,
  `reversal_voucher_id` bigint DEFAULT NULL,
  `reversal_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ar_receipt_no` (`workspace_id`,`company_id`,`receipt_no`),
  UNIQUE KEY `uq_ar_receipt_idem` (`workspace_id`,`company_id`,`idempotency_key`),
  KEY `idx_ar_receipt_party` (`workspace_id`,`company_id`,`party_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_ar_receipts`
--

LOCK TABLES `erp_ar_receipts` WRITE;
/*!40000 ALTER TABLE `erp_ar_receipts` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_ar_receipts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_p2p_bill_revisions`
--

DROP TABLE IF EXISTS `erp_p2p_bill_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_p2p_bill_revisions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `bill_id` bigint NOT NULL,
  `revision` int NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_amount` decimal(20,2) NOT NULL,
  `lines_json` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `review_note` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `amended_by` int NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_p2p_bill_rev` (`workspace_id`,`bill_id`,`revision`),
  KEY `idx_p2p_bill_rev_bill` (`workspace_id`,`company_id`,`bill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_p2p_bill_revisions`
--

LOCK TABLES `erp_p2p_bill_revisions` WRITE;
/*!40000 ALTER TABLE `erp_p2p_bill_revisions` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_p2p_bill_revisions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_p2p_company_policy`
--

DROP TABLE IF EXISTS `erp_p2p_company_policy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_p2p_company_policy` (
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `enforce_goods_match` tinyint(1) NOT NULL DEFAULT '1',
  `allow_service_manual` tinyint(1) NOT NULL DEFAULT '1',
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`workspace_id`,`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_p2p_company_policy`
--

LOCK TABLES `erp_p2p_company_policy` WRITE;
/*!40000 ALTER TABLE `erp_p2p_company_policy` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_p2p_company_policy` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_p2p_policy_exceptions`
--

DROP TABLE IF EXISTS `erp_p2p_policy_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_p2p_policy_exceptions` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `source_path` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_user_id` int NOT NULL,
  `created_doc_id` bigint DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_p2p_exc` (`workspace_id`,`company_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_p2p_policy_exceptions`
--

LOCK TABLES `erp_p2p_policy_exceptions` WRITE;
/*!40000 ALTER TABLE `erp_p2p_policy_exceptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_p2p_policy_exceptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_p2p_vendor_bill_lines`
--

DROP TABLE IF EXISTS `erp_p2p_vendor_bill_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_p2p_vendor_bill_lines` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `bill_id` bigint NOT NULL,
  `purchase_line_id` bigint NOT NULL,
  `item_id` bigint NOT NULL,
  `quantity` decimal(20,4) NOT NULL,
  `unit_price` decimal(20,2) NOT NULL,
  `line_total` decimal(20,2) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_p2p_bill_line` (`workspace_id`,`bill_id`,`purchase_line_id`),
  KEY `idx_p2p_po_line` (`workspace_id`,`purchase_line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_p2p_vendor_bill_lines`
--

LOCK TABLES `erp_p2p_vendor_bill_lines` WRITE;
/*!40000 ALTER TABLE `erp_p2p_vendor_bill_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_p2p_vendor_bill_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `erp_p2p_vendor_bills`
--

DROP TABLE IF EXISTS `erp_p2p_vendor_bills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `erp_p2p_vendor_bills` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `workspace_id` int NOT NULL,
  `company_id` int NOT NULL,
  `purchase_order_id` bigint NOT NULL,
  `supplier_id` bigint NOT NULL,
  `supplier_invoice_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_date` date NOT NULL,
  `payload_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `revision` int NOT NULL DEFAULT '1',
  `canonical_doc_id` bigint DEFAULT NULL,
  `total_amount` decimal(20,2) NOT NULL DEFAULT '0.00',
  `submitted_by` int NOT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_p2p_supplier_invoice` (`workspace_id`,`company_id`,`supplier_id`,`supplier_invoice_no`),
  UNIQUE KEY `uq_p2p_canonical` (`workspace_id`,`canonical_doc_id`),
  KEY `idx_p2p_orders` (`workspace_id`,`company_id`,`purchase_order_id`,`status`),
  KEY `idx_p2p_queue` (`workspace_id`,`company_id`,`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `erp_p2p_vendor_bills`
--

LOCK TABLES `erp_p2p_vendor_bills` WRITE;
/*!40000 ALTER TABLE `erp_p2p_vendor_bills` DISABLE KEYS */;
/*!40000 ALTER TABLE `erp_p2p_vendor_bills` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `workspace_modules`
--

DROP TABLE IF EXISTS `workspace_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workspace_modules` (
  `workspace_id` int NOT NULL,
  `module_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`workspace_id`,`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `workspace_modules`
--

LOCK TABLES `workspace_modules` WRITE;
/*!40000 ALTER TABLE `workspace_modules` DISABLE KEYS */;
INSERT INTO `workspace_modules` VALUES (10,'p2p',1);
/*!40000 ALTER TABLE `workspace_modules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'erpsmart_rc1_tr1_test'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-20 17:38:31
