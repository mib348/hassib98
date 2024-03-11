-- MySQL dump 10.13  Distrib 8.1.0, for Win64 (x86_64)
--
-- Host: localhost    Database: laravelshopify
-- ------------------------------------------------------
-- Server version	8.1.0

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
-- Table structure for table `charges`
--

DROP TABLE IF EXISTS `charges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `charges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `charge_id` bigint NOT NULL,
  `test` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `terms` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(8,2) NOT NULL,
  `interval` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capped_amount` decimal(8,2) DEFAULT NULL,
  `trial_days` int DEFAULT NULL,
  `billing_on` timestamp NULL DEFAULT NULL,
  `activated_on` timestamp NULL DEFAULT NULL,
  `trial_ends_on` timestamp NULL DEFAULT NULL,
  `cancelled_on` timestamp NULL DEFAULT NULL,
  `expires_on` timestamp NULL DEFAULT NULL,
  `plan_id` int unsigned DEFAULT NULL,
  `description` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_charge` bigint DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `charges_user_id_foreign` (`user_id`),
  KEY `charges_plan_id_foreign` (`plan_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `charges`
--

LOCK TABLES `charges` WRITE;
/*!40000 ALTER TABLE `charges` DISABLE KEYS */;
/*!40000 ALTER TABLE `charges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
INSERT INTO `failed_jobs` VALUES (3,'44ac60fd-d39d-4d30-82f7-e3996209c4cd','database','default','{\"uuid\":\"44ac60fd-d39d-4d30-82f7-e3996209c4cd\",\"displayName\":\"Osiset\\\\ShopifyApp\\\\Messaging\\\\Jobs\\\\WebhookInstaller\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"data\":{\"commandName\":\"Osiset\\\\ShopifyApp\\\\Messaging\\\\Jobs\\\\WebhookInstaller\",\"command\":\"O:49:\\\"Osiset\\\\ShopifyApp\\\\Messaging\\\\Jobs\\\\WebhookInstaller\\\":3:{s:9:\\\"\\u0000*\\u0000shopId\\\";O:39:\\\"Osiset\\\\ShopifyApp\\\\Objects\\\\Values\\\\ShopId\\\":1:{s:6:\\\"\\u0000*\\u0000int\\\";i:58891796550;}s:17:\\\"\\u0000*\\u0000configWebhooks\\\";a:1:{i:0;a:2:{s:5:\\\"topic\\\";s:13:\\\"ORDERS_CREATE\\\";s:7:\\\"address\\\";s:43:\\\"http:\\/\\/localhost:8000\\/webhook\\/orders-create\\\";}}s:5:\\\"queue\\\";s:7:\\\"default\\\";}\"}}','Symfony\\Component\\HttpKernel\\Exception\\HttpException: Error: Call to a member function apiHelper() on null in C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\kyon147\\laravel-shopify\\src\\Actions\\CreateWebhooks.php:65\nStack trace:\n#0 [internal function]: Osiset\\ShopifyApp\\Actions\\CreateWebhooks->__invoke(Object(Osiset\\ShopifyApp\\Objects\\Values\\ShopId), Array)\n#1 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\kyon147\\laravel-shopify\\src\\Messaging\\Jobs\\WebhookInstaller.php(63): call_user_func(Object(Osiset\\ShopifyApp\\Actions\\CreateWebhooks), Object(Osiset\\ShopifyApp\\Objects\\Values\\ShopId), Array)\n#2 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller->handle(Object(Osiset\\ShopifyApp\\Actions\\CreateWebhooks))\n#3 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(41): Illuminate\\Container\\BoundMethod::Illuminate\\Container\\{closure}()\n#4 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(93): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#5 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#6 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(662): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#7 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Bus\\Dispatcher.php(128): Illuminate\\Container\\Container->call(Array)\n#8 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php(144): Illuminate\\Bus\\Dispatcher->Illuminate\\Bus\\{closure}(Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller))\n#9 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php(119): Illuminate\\Pipeline\\Pipeline->Illuminate\\Pipeline\\{closure}(Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller))\n#10 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Bus\\Dispatcher.php(132): Illuminate\\Pipeline\\Pipeline->then(Object(Closure))\n#11 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(123): Illuminate\\Bus\\Dispatcher->dispatchNow(Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller), false)\n#12 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php(144): Illuminate\\Queue\\CallQueuedHandler->Illuminate\\Queue\\{closure}(Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller))\n#13 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php(119): Illuminate\\Pipeline\\Pipeline->Illuminate\\Pipeline\\{closure}(Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller))\n#14 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(122): Illuminate\\Pipeline\\Pipeline->then(Object(Closure))\n#15 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(70): Illuminate\\Queue\\CallQueuedHandler->dispatchThroughMiddleware(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller))\n#16 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Jobs\\Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Array)\n#17 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(439): Illuminate\\Queue\\Jobs\\Job->fire()\n#18 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(389): Illuminate\\Queue\\Worker->process(\'database\', Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Illuminate\\Queue\\WorkerOptions))\n#19 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(176): Illuminate\\Queue\\Worker->runJob(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), \'database\', Object(Illuminate\\Queue\\WorkerOptions))\n#20 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(137): Illuminate\\Queue\\Worker->daemon(\'database\', \'default\', Object(Illuminate\\Queue\\WorkerOptions))\n#21 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(120): Illuminate\\Queue\\Console\\WorkCommand->runWorker(\'database\', \'default\')\n#22 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#23 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(41): Illuminate\\Container\\BoundMethod::Illuminate\\Container\\{closure}()\n#24 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(93): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#25 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#26 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(662): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#27 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(211): Illuminate\\Container\\Container->call(Array)\n#28 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\symfony\\console\\Command\\Command.php(326): Illuminate\\Console\\Command->execute(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#29 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(180): Symfony\\Component\\Console\\Command\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#30 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\symfony\\console\\Application.php(1096): Illuminate\\Console\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#31 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\symfony\\console\\Application.php(324): Symfony\\Component\\Console\\Application->doRunCommand(Object(Illuminate\\Queue\\Console\\WorkCommand), Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#32 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\symfony\\console\\Application.php(175): Symfony\\Component\\Console\\Application->doRun(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#33 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Console\\Kernel.php(201): Symfony\\Component\\Console\\Application->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#34 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\artisan(35): Illuminate\\Foundation\\Console\\Kernel->handle(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#35 {main} in C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Application.php:1248\nStack trace:\n#0 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\helpers.php(45): Illuminate\\Foundation\\Application->abort(403, Object(Error), Array)\n#1 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\kyon147\\laravel-shopify\\src\\Messaging\\Jobs\\WebhookInstaller.php(70): abort(403, Object(Error))\n#2 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller->handle(Object(Osiset\\ShopifyApp\\Actions\\CreateWebhooks))\n#3 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(41): Illuminate\\Container\\BoundMethod::Illuminate\\Container\\{closure}()\n#4 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(93): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#5 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#6 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(662): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#7 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Bus\\Dispatcher.php(128): Illuminate\\Container\\Container->call(Array)\n#8 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php(144): Illuminate\\Bus\\Dispatcher->Illuminate\\Bus\\{closure}(Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller))\n#9 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php(119): Illuminate\\Pipeline\\Pipeline->Illuminate\\Pipeline\\{closure}(Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller))\n#10 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Bus\\Dispatcher.php(132): Illuminate\\Pipeline\\Pipeline->then(Object(Closure))\n#11 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(123): Illuminate\\Bus\\Dispatcher->dispatchNow(Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller), false)\n#12 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php(144): Illuminate\\Queue\\CallQueuedHandler->Illuminate\\Queue\\{closure}(Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller))\n#13 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Pipeline\\Pipeline.php(119): Illuminate\\Pipeline\\Pipeline->Illuminate\\Pipeline\\{closure}(Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller))\n#14 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(122): Illuminate\\Pipeline\\Pipeline->then(Object(Closure))\n#15 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\CallQueuedHandler.php(70): Illuminate\\Queue\\CallQueuedHandler->dispatchThroughMiddleware(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Osiset\\ShopifyApp\\Messaging\\Jobs\\WebhookInstaller))\n#16 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Jobs\\Job.php(102): Illuminate\\Queue\\CallQueuedHandler->call(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Array)\n#17 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(439): Illuminate\\Queue\\Jobs\\Job->fire()\n#18 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(389): Illuminate\\Queue\\Worker->process(\'database\', Object(Illuminate\\Queue\\Jobs\\DatabaseJob), Object(Illuminate\\Queue\\WorkerOptions))\n#19 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Worker.php(176): Illuminate\\Queue\\Worker->runJob(Object(Illuminate\\Queue\\Jobs\\DatabaseJob), \'database\', Object(Illuminate\\Queue\\WorkerOptions))\n#20 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(137): Illuminate\\Queue\\Worker->daemon(\'database\', \'default\', Object(Illuminate\\Queue\\WorkerOptions))\n#21 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Queue\\Console\\WorkCommand.php(120): Illuminate\\Queue\\Console\\WorkCommand->runWorker(\'database\', \'default\')\n#22 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(36): Illuminate\\Queue\\Console\\WorkCommand->handle()\n#23 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Util.php(41): Illuminate\\Container\\BoundMethod::Illuminate\\Container\\{closure}()\n#24 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(93): Illuminate\\Container\\Util::unwrapIfClosure(Object(Closure))\n#25 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\BoundMethod.php(35): Illuminate\\Container\\BoundMethod::callBoundMethod(Object(Illuminate\\Foundation\\Application), Array, Object(Closure))\n#26 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Container\\Container.php(662): Illuminate\\Container\\BoundMethod::call(Object(Illuminate\\Foundation\\Application), Array, Array, NULL)\n#27 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(211): Illuminate\\Container\\Container->call(Array)\n#28 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\symfony\\console\\Command\\Command.php(326): Illuminate\\Console\\Command->execute(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#29 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Console\\Command.php(180): Symfony\\Component\\Console\\Command\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Illuminate\\Console\\OutputStyle))\n#30 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\symfony\\console\\Application.php(1096): Illuminate\\Console\\Command->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#31 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\symfony\\console\\Application.php(324): Symfony\\Component\\Console\\Application->doRunCommand(Object(Illuminate\\Queue\\Console\\WorkCommand), Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#32 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\symfony\\console\\Application.php(175): Symfony\\Component\\Console\\Application->doRun(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#33 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\vendor\\laravel\\framework\\src\\Illuminate\\Foundation\\Console\\Kernel.php(201): Symfony\\Component\\Console\\Application->run(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#34 C:\\wamp64\\www\\hassib98\\laravelshopifypartnerapp\\artisan(35): Illuminate\\Foundation\\Console\\Kernel->handle(Object(Symfony\\Component\\Console\\Input\\ArgvInput), Object(Symfony\\Component\\Console\\Output\\ConsoleOutput))\n#35 {main}','2024-02-15 12:07:34');
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
INSERT INTO `jobs` VALUES (7,'default','{\"uuid\":\"3f4383e8-d00b-4711-9a57-3f9644a4571a\",\"displayName\":\"Osiset\\\\ShopifyApp\\\\Messaging\\\\Jobs\\\\WebhookInstaller\",\"job\":\"Illuminate\\\\Queue\\\\CallQueuedHandler@call\",\"maxTries\":null,\"maxExceptions\":null,\"failOnTimeout\":false,\"backoff\":null,\"timeout\":null,\"retryUntil\":null,\"data\":{\"commandName\":\"Osiset\\\\ShopifyApp\\\\Messaging\\\\Jobs\\\\WebhookInstaller\",\"command\":\"O:49:\\\"Osiset\\\\ShopifyApp\\\\Messaging\\\\Jobs\\\\WebhookInstaller\\\":2:{s:9:\\\"\\u0000*\\u0000shopId\\\";O:39:\\\"Osiset\\\\ShopifyApp\\\\Objects\\\\Values\\\\ShopId\\\":1:{s:6:\\\"\\u0000*\\u0000int\\\";i:1;}s:17:\\\"\\u0000*\\u0000configWebhooks\\\";a:1:{i:0;a:2:{s:5:\\\"topic\\\";s:13:\\\"ORDERS_CREATE\\\";s:7:\\\"address\\\";s:73:\\\"https:\\/\\/succeed-stock-lesser-ties.trycloudflare.com\\/webhook\\/orders-create\\\";}}}\"}}',0,NULL,1708016961,1708016961);
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2014_10_12_000000_create_users_table',1),(2,'2014_10_12_100000_create_password_reset_tokens_table',1),(3,'2019_08_19_000000_create_failed_jobs_table',1),(4,'2019_12_14_000001_create_personal_access_tokens_table',1),(5,'2020_01_29_010501_create_plans_table',1),(6,'2020_01_29_230905_create_shops_table',1),(7,'2020_01_29_231006_create_charges_table',1),(8,'2020_07_03_211514_add_interval_column_to_charges_table',1),(9,'2020_07_03_211854_add_interval_column_to_plans_table',1),(10,'2021_04_21_103633_add_password_updated_at_to_users_table',1),(11,'2022_06_09_104819_add_theme_support_level_to_users_table',1),(12,'2024_02_14_202033_create_jobs_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plans`
--

DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(8,2) NOT NULL,
  `interval` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capped_amount` decimal(8,2) DEFAULT NULL,
  `terms` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trial_days` int DEFAULT NULL,
  `test` tinyint(1) NOT NULL DEFAULT '0',
  `on_install` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plans`
--

LOCK TABLES `plans` WRITE;
/*!40000 ALTER TABLE `plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `shopify_grandfathered` tinyint(1) NOT NULL DEFAULT '0',
  `shopify_namespace` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shopify_freemium` tinyint(1) NOT NULL DEFAULT '0',
  `plan_id` int unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `password_updated_at` date DEFAULT NULL,
  `theme_support_level` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_plan_id_foreign` (`plan_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'sushi2024.myshopify.com','shop@sushi2024.myshopify.com',NULL,'shpua_23b2895fe9927b884f69780d46ffaadf',NULL,'2024-02-15 11:30:31','2024-02-15 12:09:20',0,NULL,0,NULL,NULL,'2024-02-15',1);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2024-02-15 22:22:12
