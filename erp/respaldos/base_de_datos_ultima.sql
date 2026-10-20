-- MySQL dump 10.13  Distrib 8.0.46, for Linux (aarch64)
--
-- Host: localhost    Database: erp_inventory
-- ------------------------------------------------------
-- Server version	8.0.46-0ubuntu0.24.04.4

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
-- Table structure for table `configuracion`
--

DROP TABLE IF EXISTS `configuracion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre_empresa` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'assets/img/default-logo.png',
  `email_notificaciones` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `smtp_host` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_port` int DEFAULT NULL,
  `smtp_user` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `smtp_pass` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bg_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion`
--

LOCK TABLES `configuracion` WRITE;
/*!40000 ALTER TABLE `configuracion` DISABLE KEYS */;
INSERT INTO `configuracion` VALUES (1,'PLASTICOS ALISAKA','uploads/logo_1788566887.png','admin@empresa.com',NULL,NULL,NULL,NULL,'uploads/fondo/background.jpg');
/*!40000 ALTER TABLE `configuracion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `configuracion_reportes`
--

DROP TABLE IF EXISTS `configuracion_reportes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion_reportes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `activo` tinyint(1) DEFAULT '0',
  `destinatarios` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `frecuencia` enum('diario','semanal','mensual') COLLATE utf8mb4_unicode_ci DEFAULT 'semanal',
  `periodo_reporte` enum('semana','quincena','mes','ano') COLLATE utf8mb4_unicode_ci DEFAULT 'mes',
  `ultimo_envio` datetime DEFAULT NULL,
  `actualizado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion_reportes`
--

LOCK TABLES `configuracion_reportes` WRITE;
/*!40000 ALTER TABLE `configuracion_reportes` DISABLE KEYS */;
INSERT INTO `configuracion_reportes` VALUES (1,1,'jmhrs8@gmail.com','diario','semana',NULL,'2026-10-06 17:33:57');
/*!40000 ALTER TABLE `configuracion_reportes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cuentas_cobrar`
--

DROP TABLE IF EXISTS `cuentas_cobrar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cuentas_cobrar` (
  `id` int NOT NULL AUTO_INCREMENT,
  `salida_id` int NOT NULL,
  `cliente` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `estatus` enum('pendiente','cobrado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pendiente',
  `fecha_emision` datetime DEFAULT CURRENT_TIMESTAMP,
  `fecha_vencimiento` date DEFAULT NULL,
  `fecha_cobro` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `salida_id` (`salida_id`),
  CONSTRAINT `cuentas_cobrar_ibfk_1` FOREIGN KEY (`salida_id`) REFERENCES `salidas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cuentas_cobrar`
--

LOCK TABLES `cuentas_cobrar` WRITE;
/*!40000 ALTER TABLE `cuentas_cobrar` DISABLE KEYS */;
INSERT INTO `cuentas_cobrar` VALUES (1,7,'WALMART S.A. DE C.V.',0.00,'cobrado','2026-10-05 13:27:39','2026-10-31','2026-10-05 13:27:50'),(2,9,'WALMART S.A. DE C.V.',0.00,'cobrado','2026-10-05 14:54:22','2026-10-08','2026-10-05 19:59:51');
/*!40000 ALTER TABLE `cuentas_cobrar` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cuentas_pagar`
--

DROP TABLE IF EXISTS `cuentas_pagar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cuentas_pagar` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entrada_id` int DEFAULT NULL,
  `proveedor_id` int DEFAULT NULL,
  `concepto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `monto_pagado` decimal(10,2) DEFAULT '0.00',
  `estatus` enum('pendiente','parcial','pagado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pendiente',
  `fecha_emision` datetime DEFAULT CURRENT_TIMESTAMP,
  `fecha_pago` datetime DEFAULT NULL,
  `metodo_pago` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comprobante_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `entrada_id` (`entrada_id`),
  KEY `proveedor_id` (`proveedor_id`),
  CONSTRAINT `cuentas_pagar_ibfk_1` FOREIGN KEY (`entrada_id`) REFERENCES `entradas_inventario` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cuentas_pagar_ibfk_2` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cuentas_pagar`
--

LOCK TABLES `cuentas_pagar` WRITE;
/*!40000 ALTER TABLE `cuentas_pagar` DISABLE KEYS */;
INSERT INTO `cuentas_pagar` VALUES (1,13,1,'Compra a crédito (inicial): 200 Caja(s) [Total: 40000 unids.] de BOTELLA BLANCA TIPO A',11600.00,0.00,'pagado','2026-10-05 13:15:06','2026-10-05 13:15:40','transferencia','uploads/facturas_compras/compra_1791227706_6ac3f73a032cf.pdf','2026-10-17'),(2,14,1,'Compra a crédito de 100 unid. de TAPAS DE BOTELLA AZULES',20000.00,0.00,'pendiente','2026-10-05 14:52:47',NULL,NULL,NULL,'2026-10-05');
/*!40000 ALTER TABLE `cuentas_pagar` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `detalle_salidas`
--

DROP TABLE IF EXISTS `detalle_salidas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `detalle_salidas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `salida_id` int NOT NULL,
  `producto_id` int NOT NULL,
  `cantidad` decimal(10,2) NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `salida_id` (`salida_id`),
  CONSTRAINT `detalle_salidas_ibfk_1` FOREIGN KEY (`salida_id`) REFERENCES `salidas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `detalle_salidas`
--

LOCK TABLES `detalle_salidas` WRITE;
/*!40000 ALTER TABLE `detalle_salidas` DISABLE KEYS */;
INSERT INTO `detalle_salidas` VALUES (5,5,1,150.00,230.00,34500.00),(7,7,2,50.00,150.00,7500.00),(8,8,1,100.00,230.00,23000.00),(9,9,1,120.00,230.00,27600.00),(10,10,3,3000.00,25.00,75000.00),(11,11,2,300.00,150.00,45000.00),(12,12,2,300.00,150.00,45000.00),(13,13,2,200.00,150.00,30000.00),(14,14,2,200.00,150.00,30000.00),(15,15,2,200.00,150.00,30000.00),(16,16,1,500.00,230.00,115000.00),(17,17,2,400.00,150.00,60000.00);
/*!40000 ALTER TABLE `detalle_salidas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `egresos`
--

DROP TABLE IF EXISTS `egresos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `egresos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entrada_id` int DEFAULT NULL,
  `cuenta_pagar_id` int DEFAULT NULL,
  `proveedor_id` int DEFAULT NULL,
  `concepto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `metodo_pago` enum('efectivo','transferencia','tarjeta') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_comprobante` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'sin_comprobante',
  `comprobante_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_pago` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `entrada_id` (`entrada_id`),
  KEY `proveedor_id` (`proveedor_id`),
  CONSTRAINT `egresos_ibfk_1` FOREIGN KEY (`entrada_id`) REFERENCES `entradas_inventario` (`id`) ON DELETE SET NULL,
  CONSTRAINT `egresos_ibfk_2` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `egresos`
--

LOCK TABLES `egresos` WRITE;
/*!40000 ALTER TABLE `egresos` DISABLE KEYS */;
INSERT INTO `egresos` VALUES (1,12,NULL,1,'Compra de 200 unid. de TAPAS DE BOTELLA AZULES',40000.00,'efectivo','factura','uploads/facturas_compras/compra_1791163370_090471c7.pdf','2026-10-04 19:22:50'),(2,13,NULL,1,'Pago CxP (Liquidación): Compra a crédito (inicial): 200 Caja(s) [Total: 40000 unids.] de BOTELLA BLANCA TIPO A',11600.00,'transferencia','comprobante_cxp','uploads/facturas_compras/compra_1791227706_6ac3f73a032cf.pdf','2026-10-05 13:15:40'),(3,15,NULL,1,'Compra inicial: 900 Pieza(s) [Total: 8100000 unids.] de BOTELLAS TIPO B',10440.00,'efectivo','factura',NULL,'2026-10-06 17:50:22');
/*!40000 ALTER TABLE `egresos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `entradas_inventario`
--

DROP TABLE IF EXISTS `entradas_inventario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `entradas_inventario` (
  `id` int NOT NULL AUTO_INCREMENT,
  `producto_id` int NOT NULL,
  `proveedor_id` int DEFAULT NULL,
  `cantidad` int NOT NULL,
  `empaques_recibidos` decimal(10,2) DEFAULT NULL,
  `unidades_por_empaque` decimal(10,2) DEFAULT '1.00',
  `costo_unitario` decimal(10,2) NOT NULL,
  `monto_total` decimal(10,2) NOT NULL,
  `estatus_pago` enum('pagado','pendiente') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pagado',
  `tipo_comprobante` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'sin_comprobante',
  `comprobante_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_pago` datetime DEFAULT NULL,
  `fecha_registro` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `producto_id` (`producto_id`),
  KEY `proveedor_id` (`proveedor_id`),
  CONSTRAINT `entradas_inventario_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `entradas_inventario_ibfk_2` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `entradas_inventario`
--

LOCK TABLES `entradas_inventario` WRITE;
/*!40000 ALTER TABLE `entradas_inventario` DISABLE KEYS */;
INSERT INTO `entradas_inventario` VALUES (3,2,1,100,NULL,1.00,550.00,55000.00,'pagado','ninguno',NULL,'2026-09-04 12:02:03','2026-09-04 11:50:43'),(4,2,3,20,NULL,1.00,550.00,11000.00,'pagado','ninguno',NULL,'2026-09-04 17:37:26','2026-09-04 13:23:36'),(6,1,1,150,NULL,1.00,6.00,900.00,'pagado','factura','uploads/facturas_compras/compra_1788564673_bb4670ef.pdf','2026-09-04 23:31:13','2026-09-04 17:31:13'),(10,1,2,200,NULL,1.00,6.00,1200.00,'pagado','ninguno',NULL,'2026-09-05 00:05:35','2026-09-04 18:05:35'),(12,1,1,200,NULL,1.00,200.00,40000.00,'pagado','factura','uploads/facturas_compras/compra_1791163370_090471c7.pdf','2026-10-05 01:22:50','2026-10-04 19:22:50'),(13,2,1,40000,200.00,200.00,50.00,11600.00,'pagado','factura','uploads/facturas_compras/compra_1791227706_6ac3f73a032cf.pdf','2026-10-05 13:15:40','2026-10-05 13:15:06'),(14,1,1,100,NULL,1.00,200.00,20000.00,'pendiente','factura',NULL,NULL,'2026-10-05 14:52:47'),(15,3,1,8100000,900.00,9000.00,10.00,10440.00,'pagado','factura',NULL,'2026-10-06 23:50:22','2026-10-06 17:50:22');
/*!40000 ALTER TABLE `entradas_inventario` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingresos`
--

DROP TABLE IF EXISTS `ingresos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ingresos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `producto_id` int DEFAULT NULL,
  `usuario_id` int DEFAULT '1',
  `cantidad` decimal(10,2) NOT NULL,
  `costo_unitario` decimal(10,2) NOT NULL,
  `comentario` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `fecha` datetime DEFAULT CURRENT_TIMESTAMP,
  `salida_id` int DEFAULT NULL,
  `concepto` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `monto_subtotal` decimal(10,2) DEFAULT '0.00',
  `monto_iva` decimal(10,2) DEFAULT '0.00',
  `monto_total` decimal(10,2) DEFAULT '0.00',
  `metodo_pago` enum('efectivo','transferencia','tarjeta') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'efectivo',
  `comprobante_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha_ingreso` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_ingresos_producto` (`producto_id`),
  CONSTRAINT `fk_ingresos_producto` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingresos`
--

LOCK TABLES `ingresos` WRITE;
/*!40000 ALTER TABLE `ingresos` DISABLE KEYS */;
INSERT INTO `ingresos` VALUES (1,1,3,150.00,230.00,'','2026-10-04 19:16:09',5,'Venta / Salida #5 - TAPAS DE BOTELLA AZULES (WALMART S.A. DE C.V.)',34500.00,0.00,34500.00,'transferencia','uploads/ventas/salida_1791162969_a46e5898.pdf','2026-10-04 19:16:09'),(2,2,1,1.00,8700.00,'','2026-10-05 13:27:50',7,'Cobro de crédito a WALMART S.A. DE C.V. (Venta #7)',8700.00,0.00,8700.00,'efectivo',NULL,'2026-10-05 13:27:50'),(3,1,3,100.00,230.00,'','2026-10-05 13:29:03',8,'Venta / Salida #8 - TAPAS DE BOTELLA AZULES (la rielera S.A. de C.V.) [Facturado 16% IVA]',23000.00,3680.00,26680.00,'transferencia',NULL,'2026-10-05 13:29:03'),(4,1,1,1.00,32016.00,'','2026-10-05 19:59:51',9,'Cobro de crédito a WALMART S.A. DE C.V. (Venta #9)',32016.00,0.00,32016.00,'efectivo',NULL,'2026-10-05 19:59:51'),(5,3,3,3000.00,25.00,'','2026-10-06 17:51:48',10,'Venta / Salida #10 - BOTELLAS TIPO B (WALMART S.A. DE C.V.) [Facturado 16% IVA]',75000.00,12000.00,87000.00,'efectivo',NULL,'2026-10-06 17:51:48'),(6,2,3,300.00,150.00,'','2026-10-07 10:31:42',11,'Venta / Salida #11 - BOTELLA BLANCA TIPO A (WALMART S.A. DE C.V.) [Facturado 16% IVA]',45000.00,7200.00,52200.00,'transferencia',NULL,'2026-10-07 10:31:42'),(7,2,3,300.00,150.00,'','2026-10-07 10:48:03',12,'Venta / Salida #12 - BOTELLA BLANCA TIPO A (WALMART S.A. DE C.V.) [Facturado 16% IVA]',45000.00,7200.00,52200.00,'transferencia',NULL,'2026-10-07 10:48:03'),(8,2,3,200.00,150.00,'','2026-10-07 10:50:12',13,'Venta / Salida #13 - BOTELLA BLANCA TIPO A (WALMART S.A. DE C.V.) [Facturado 16% IVA]',30000.00,4800.00,34800.00,'efectivo',NULL,'2026-10-07 10:50:12'),(9,2,3,200.00,150.00,'','2026-10-07 10:53:56',14,'Venta / Salida #14 - BOTELLA BLANCA TIPO A (WALMART S.A. DE C.V.) [Facturado 16% IVA]',30000.00,4800.00,34800.00,'efectivo',NULL,'2026-10-07 10:53:56'),(10,2,3,200.00,150.00,'','2026-10-07 10:58:40',15,'Venta / Salida #15 - BOTELLA BLANCA TIPO A (WALMART S.A. DE C.V.) [Facturado 16% IVA]',30000.00,4800.00,34800.00,'efectivo',NULL,'2026-10-07 10:58:40'),(11,1,3,500.00,230.00,'','2026-10-07 11:03:31',16,'Venta / Salida #16 - TAPAS DE BOTELLA AZULES (WALMART S.A. DE C.V.) [Facturado 16% IVA]',115000.00,18400.00,133400.00,'efectivo',NULL,'2026-10-07 11:03:31'),(12,2,7,400.00,150.00,'','2026-10-07 21:03:22',17,'Venta / Salida #17 - BOTELLA BLANCA TIPO A (Walmart s.a de c.v) [Facturado 16% IVA]',60000.00,9600.00,69600.00,'efectivo',NULL,'2026-10-07 21:03:22');
/*!40000 ALTER TABLE `ingresos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `productos`
--

DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `productos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tipo_unidad` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pieza',
  `unidades_por_empaque` decimal(10,2) NOT NULL DEFAULT '1.00',
  `costo_unitario` decimal(10,2) NOT NULL DEFAULT '0.00',
  `precio_venta` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stock_actual` decimal(10,2) NOT NULL DEFAULT '0.00',
  `stock_minimo` decimal(10,2) NOT NULL DEFAULT '5.00',
  `imagen` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'uploads/productos/default.png',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `foto_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `stock` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `productos`
--

LOCK TABLES `productos` WRITE;
/*!40000 ALTER TABLE `productos` DISABLE KEYS */;
INSERT INTO `productos` VALUES (1,'PRODUC-001','TAPAS DE BOTELLA AZULES','Paquete',500.00,200.00,230.00,1000.00,100.00,'uploads/productos/default.png','2026-10-02 23:29:54','',0),(2,'PRODUC-002','BOTELLA BLANCA TIPO A','Caja',200.00,50.00,150.00,38350.00,100.00,'uploads/productos/prod_1791227706_6ac3f73a02900.jpg','2026-10-05 19:15:06','',0),(3,'PRODUC-003','BOTELLAS TIPO B','Pieza',9000.00,10.00,25.00,8097000.00,150.00,'uploads/productos/prod_1791330622_6ac5893e6ce54.jpg','2026-10-06 23:50:22','',0);
/*!40000 ALTER TABLE `productos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `proveedores`
--

DROP TABLE IF EXISTS `proveedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `proveedores` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rfc` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contacto` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `direccion` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `banco` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `numero_cuenta` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rfc_archivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `proveedores`
--

LOCK TABLES `proveedores` WRITE;
/*!40000 ALTER TABLE `proveedores` DISABLE KEYS */;
INSERT INTO `proveedores` VALUES (1,'LAS ESTRELLAS S.A. DE C.V.','AAGF870104AE0','LIC. GORJE MARTINEZ ALBARRAN','5542182369','estrellas@hotmail.com','Sericultura 30','BBVA','65989978442555111','RFC_6ac03f0ea44de_1790983950.pdf','2026-10-02 17:32:30'),(2,'JUAN MANUEL HERNANDEZ LUGO','HELJ740724UM1','JUAN MANUEL HDZ LUGO','5519017322','jmhrs8@gmail.com','Calle 10 Manzana 65 lote 21 colonia jose lopez portillo entre calle 21 y 22','NU','89755644889326544','RFC_6ac03fa9940ae_1790984105.pdf','2026-10-02 17:35:05');
/*!40000 ALTER TABLE `proveedores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `salidas`
--

DROP TABLE IF EXISTS `salidas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `salidas` (
  `id` int NOT NULL AUTO_INCREMENT,
  `usuario_id` int NOT NULL,
  `cliente` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Público General',
  `total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `fecha` datetime DEFAULT CURRENT_TIMESTAMP,
  `estado_cobro` enum('cobrado','credito') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'cobrado',
  `fecha_vencimiento` date DEFAULT NULL,
  `metodo_cobro` enum('efectivo','transferencia','tarjeta') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'efectivo',
  `requiere_factura` tinyint(1) DEFAULT '0',
  `subtotal` decimal(10,2) DEFAULT '0.00',
  `iva` decimal(10,2) DEFAULT '0.00',
  `factura_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tipo_pago` enum('contado','credito') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'contado',
  `metodo_pago` enum('efectivo','transferencia','tarjeta') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'efectivo',
  `con_factura` tinyint(1) DEFAULT '0',
  `monto_total` decimal(10,2) DEFAULT '0.00',
  `fecha_salida` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salidas`
--

LOCK TABLES `salidas` WRITE;
/*!40000 ALTER TABLE `salidas` DISABLE KEYS */;
INSERT INTO `salidas` VALUES (5,3,'WALMART S.A. DE C.V.',34500.00,'2026-10-04 19:16:09','cobrado',NULL,'transferencia',0,34500.00,0.00,'uploads/ventas/salida_1791162969_a46e5898.pdf','contado','transferencia',0,34500.00,'2026-10-04 19:16:09'),(7,3,'WALMART S.A. DE C.V.',8700.00,'2026-10-05 13:27:39','cobrado','2026-10-31','transferencia',1,7500.00,1200.00,NULL,'contado','transferencia',1,8700.00,'2026-10-05 13:27:39'),(8,3,'la rielera S.A. de C.V.',26680.00,'2026-10-05 13:29:03','cobrado',NULL,'transferencia',1,23000.00,3680.00,NULL,'contado','transferencia',1,26680.00,'2026-10-05 13:29:03'),(9,3,'WALMART S.A. DE C.V.',32016.00,'2026-10-05 14:54:00','cobrado',NULL,'transferencia',1,27600.00,4416.00,'uploads/ventas/salida_1791318097_960eacac.pdf','contado','transferencia',1,32016.00,'2026-10-05 14:54:22'),(10,3,'WALMART S.A. DE C.V.',87000.00,'2026-10-06 17:51:48','cobrado',NULL,'efectivo',1,75000.00,12000.00,NULL,'contado','efectivo',1,87000.00,'2026-10-06 17:51:48'),(11,3,'WALMART S.A. DE C.V.',52200.00,'2026-10-07 10:31:42','cobrado',NULL,'transferencia',1,45000.00,7200.00,NULL,'contado','transferencia',1,52200.00,'2026-10-07 10:31:42'),(12,3,'WALMART S.A. DE C.V.',52200.00,'2026-10-07 10:48:03','cobrado',NULL,'transferencia',1,45000.00,7200.00,NULL,'contado','transferencia',1,52200.00,'2026-10-07 10:48:03'),(13,3,'WALMART S.A. DE C.V.',34800.00,'2026-10-07 10:50:12','cobrado',NULL,'efectivo',1,30000.00,4800.00,NULL,'contado','efectivo',1,34800.00,'2026-10-07 10:50:12'),(14,3,'WALMART S.A. DE C.V.',34800.00,'2026-10-07 10:53:56','cobrado',NULL,'efectivo',1,30000.00,4800.00,NULL,'contado','efectivo',1,34800.00,'2026-10-07 10:53:56'),(15,3,'WALMART S.A. DE C.V.',34800.00,'2026-10-07 10:58:40','cobrado',NULL,'efectivo',1,30000.00,4800.00,NULL,'contado','efectivo',1,34800.00,'2026-10-07 10:58:40'),(16,3,'WALMART S.A. DE C.V.',133400.00,'2026-10-07 11:03:31','cobrado',NULL,'efectivo',1,115000.00,18400.00,NULL,'contado','efectivo',1,133400.00,'2026-10-07 11:03:31'),(17,7,'Walmart s.a de c.v',69600.00,'2026-10-07 21:03:22','cobrado',NULL,'efectivo',1,60000.00,9600.00,NULL,'contado','efectivo',1,69600.00,'2026-10-07 21:03:22');
/*!40000 ALTER TABLE `salidas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cajero',
  `creado_en` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'Administrador','admin@alisaka.com','$2y$10$CxIsv56Kkdkz2MFPWAS6IO1YsWNuIhyUSEet0hP.HbZHVO5nTMUzO','admin','2026-09-01 10:41:59'),(3,'JUAN MANUEL HERNÁNDEZ LUGO','jmhrs8@gmail.com','$2y$10$oQ3wxedjlM4oH.KLjV1VqOY4PICGe19QXfr9LKQ7OlkSqbQQh67C6','admin','2026-09-01 13:51:02'),(4,'pancho','paco@gmail.com','$2y$10$zrX.NKQA6MpkcYuTMfPHKObCQ3HQ8qxHLLWJhO9vgJtuHgKpUZJZK','admin','2026-09-04 14:09:15'),(5,'MARTHA','martha@gmail.com','$2y$10$GGLxj9/wX80jUlHhnrOUnOzTzAN8igjgpWkYNb9uyQPkcuSkNUElm','admin','2026-09-04 18:06:33'),(6,'inventario','inventario@alisaka.com','$2y$10$uMwtKTWi3xRF.5kJWHJ1a.zNtWYNkou843/K5XGjwuV7bY8vg6eDi','almacen','2026-10-07 17:48:26'),(7,'caja','caja@alisaka.com','$2y$10$NwoKa4trZmBg6D/SHwt.l.nVEuDWrw7N.AhQgx/iaUPxemQDfHQRG','cajero','2026-10-07 17:49:05');
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'erp_inventory'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-20 10:25:25
