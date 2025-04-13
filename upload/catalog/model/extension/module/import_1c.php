<?php
class ModelExtensionModuleImport1C extends Model {
    // Constants
    const DEFAULT_FILE_PATH = '/home/cr548725/feniks-lviv.com.ua/transfer/products.xml';
    const DEFAULT_PER_PAGE = 10000;
    const DEFAULT_CATEGORY_ID = 511;
    const DEFAULT_STOCK_STATUS_ID = 7;
    const DEFAULT_LANGUAGE_ID = 3;
    // Customer group IDs
    const DEFAULT_CUSTOMER_GROUP_ID = 1;   // Default (opt_price)
    const COMMERCIAL_2_GROUP_ID = 2;       // Commercial 2 (price_2)
    const COMMERCIAL_7_GROUP_ID = 3;       // Commercial 7 (price_7)
    const COMMERCIAL_5_GROUP_ID = 4;       // Commercial 5 (price_5)
    const IMAGES_SOURCE_DIR = '/home/cr548725/feniks-lviv.com.ua/transfer/';
    const IMAGES_TARGET_DIR = '/home/cr548725/feniks-lviv.com.ua/www/image/catalog/products/';
    const USERS_FILE_PATH = '/home/cr548725/feniks-lviv.com.ua/transfer/users_utf.xml';
    const ORDERS_EXPORT_DIR = '/home/cr548725/feniks-lviv.com.ua/transfer/orders';
    const MANUFACTURER_SORT_ORDER = 0;
    const MANUFACTURER_NOINDEX = 1;
    const CATEGORY_SORT_ORDER = 0;
    const CATEGORY_STATUS = 1;
    const CATEGORY_NOINDEX = 1;
    const CATEGORY_COLUMN = 1;
    const MAX_CATEGORY_LEVELS = 10; // Support for up to 10 levels of categories
    
    // Validate product data
    private function validateProductData($mpn, $name) {
        // Check for invalid MPNs
        if (empty($mpn) || !preg_match('/^[a-zA-Z0-9\-\.]+$/', $mpn)) {
            return false;
        }
        
        // Check if name is not empty
        if (empty($name)) {
            return false;
        }
        
        return true;
    }
    
    // Sanitize product name
    private function sanitizeProductName($name) {
        // Replace multiple spaces with a single space
        $name = preg_replace('/\s+/', ' ', $name);
        
        // Remove any special characters that might cause issues
        $name = preg_replace('/[^\p{L}\p{N}\s\-\.\,\"\']/u', '', $name);
        
        // Replace quotes with HTML entities to ensure proper display and avoid SQL issues
        $name = str_replace(
            ['"', "'", '«', '»'], 
            ['&quot;', '&#39;', '&laquo;', '&raquo;'], 
            $name
        );
        
        // Trim whitespace
        $name = trim($name);
        
        return $name;
    }

    // Імпорт цін з 1С
    public function importPrices() {
        $updated = 0;
        $errors = 0;
        
        $price_file = $this->config->get('module_import_1c_price_file');
        if (!$price_file) {
            $price_file = self::DEFAULT_FILE_PATH;
        }
        
        $per_page = (int)$this->config->get('module_import_1c_per_page');
        if ($per_page <= 0) {
            $per_page = self::DEFAULT_PER_PAGE;
        }
        
        try {
            // Перевірка наявності файлу
            if (!file_exists($price_file)) {
                throw new Exception('Файл не знайдено: ' . $price_file);
            }

            // Load XML file with encoding handling
            $xml_content = file_get_contents($price_file);
            $feed = simplexml_load_string($xml_content);
            
            if (!$feed) {
                throw new Exception('Помилка при розборі XML файлу');
            }
            
            $total = count($feed->product);
            
            $page = 1;
            $start = ($page - 1) * $per_page;
            $end = min($start + $per_page, $total);
            
            for ($i = $start; $i < $end; $i++) {
                if (!isset($feed->product[$i])) {
                    continue;
                }
                
                $product = $feed->product[$i];
                
                // Validate MPN and skip invalid products
                $mpn = trim(strval($product->mpn));
                if (!$this->validateProductData($mpn, strval($product->name))) {
                    $errors++;
                    continue;
                }
                
                // Find products by SKU (was UPC in old script)
                $ex_products = $this->db->query("SELECT product_id, stock_status_id FROM " . DB_PREFIX . "product WHERE sku = '" . $this->db->escape($mpn) . "'");
                $ex_products = $ex_products->rows;
                
                // Get manufacturer name from XML if it exists
                $manufacturer_name = isset($product->brand) ? trim(strval($product->brand)) : '';
                $manufacturer_id = 0;
                
                if (!empty($manufacturer_name)) {
                    $manufacturer_id = $this->getOrCreateManufacturer($manufacturer_name);
                }
                
                if (!empty($ex_products)) {
                    foreach ($ex_products as $ex_product) {
                        $product_ex_id = $ex_product['product_id'];
                        
                        // Update default price in product table
                        $default_price = floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->opt_price)));
                        
                        if (!empty($manufacturer_name)) {
                            $this->db->query("UPDATE " . DB_PREFIX . "product SET 
                                price = " . floatval($default_price) . ",
                                manufacturer_id = " . (int)$manufacturer_id . " 
                                WHERE product_id = " . (int)$product_ex_id);
                        } else {
                            $this->db->query("UPDATE " . DB_PREFIX . "product SET 
                                price = " . floatval($default_price) . " 
                                WHERE product_id = " . (int)$product_ex_id);
                        }
                        
                        // Delete existing special prices for this product
                        $this->db->query("DELETE FROM " . DB_PREFIX . "product_special WHERE product_id = " . (int)$product_ex_id);
                        
                        // Handle price_2 (Commercial 2 group)
                        $price_2 = isset($product->price_2) ? floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->price_2))) : 0;
                        if ($price_2 > 0 && $price_2 != $default_price) {
                            $this->db->query("INSERT INTO " . DB_PREFIX . "product_special SET 
                                product_id = " . (int)$product_ex_id . ", 
                                customer_group_id = " . self::COMMERCIAL_2_GROUP_ID . ", 
                                price = " . floatval($price_2) . ",
                                priority = 1,
                                date_start = '0000-00-00',
                                date_end = '0000-00-00'");
                        }
                        
                        // Handle price_5 (Commercial 5 group)
                        $price_5 = isset($product->price_5) ? floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->price_5))) : 0;
                        if ($price_5 > 0 && $price_5 != $default_price) {
                            $this->db->query("INSERT INTO " . DB_PREFIX . "product_special SET 
                                product_id = " . (int)$product_ex_id . ", 
                                customer_group_id = " . self::COMMERCIAL_5_GROUP_ID . ", 
                                price = " . floatval($price_5) . ",
                                priority = 1,
                                date_start = '0000-00-00',
                                date_end = '0000-00-00'");
                        }
                        
                        // Handle price_7 (Commercial 7 group)
                        $price_7 = isset($product->price_7) ? floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->price_7))) : 0;
                        if ($price_7 > 0 && $price_7 != $default_price) {
                            $this->db->query("INSERT INTO " . DB_PREFIX . "product_special SET 
                                product_id = " . (int)$product_ex_id . ", 
                                customer_group_id = " . self::COMMERCIAL_7_GROUP_ID . ", 
                                price = " . floatval($price_7) . ",
                                priority = 1,
                                date_start = '0000-00-00',
                                date_end = '0000-00-00'");
                        }
                        
                        // Update product name if needed
                        $product_name = $this->sanitizeProductName(strval($product->name));
                        $this->db->query("UPDATE " . DB_PREFIX . "product_description SET 
                            `name` = '" . $this->db->escape($product_name) . "',
                            `meta_h1` = '" . $this->db->escape($product_name) . "' 
                            WHERE product_id = " . (int)$product_ex_id . " 
                            AND language_id = " . self::DEFAULT_LANGUAGE_ID);
                        
                        // Update SEO URL
                        $slug = $this->generateSeoUrl($product_name);
                        $this->db->query("UPDATE " . DB_PREFIX . "seo_url SET 
                            `keyword` = '" . $this->db->escape($slug) . "' 
                            WHERE `query` = 'product_id=" . (int)$product_ex_id . "' 
                            AND language_id = " . self::DEFAULT_LANGUAGE_ID);
                        
                        $updated++;
                    }
                }
            }
            
            return ['updated' => $updated, 'errors' => $errors];
            
        } catch (Exception $e) {
            return ['updated' => $updated, 'errors' => $errors + 1, 'message' => $e->getMessage()];
        }
    }

    // Імпорт кількості з 1С
    public function importQuantities() {
        $updated = 0;
        $errors = 0;
        $ids_exists = [];
        
        $quantity_file = $this->config->get('module_import_1c_quantity_file');
        if (!$quantity_file) {
            $quantity_file = self::DEFAULT_FILE_PATH;
        }
        
        $per_page = (int)$this->config->get('module_import_1c_per_page');
        if ($per_page <= 0) {
            $per_page = self::DEFAULT_PER_PAGE;
        }
        
        try {
            // Перевірка наявності файлу
            if (!file_exists($quantity_file)) {
                throw new Exception('Файл не знайдено: ' . $quantity_file);
            }

            // Load XML file with encoding handling
            $xml_content = file_get_contents($quantity_file);
            $feed = simplexml_load_string($xml_content);
            
            if (!$feed) {
                throw new Exception('Помилка при розборі XML файлу');
            }
            
            $total = count($feed->product);

            $page = 1;
            $start = ($page - 1) * $per_page;
            $end = min($start + $per_page, $total);
            
            for ($i = $start; $i < $end; $i++) {
                if (!isset($feed->product[$i])) {
                    continue;
                }
                
                $product = $feed->product[$i];
                
                // Validate MPN and skip invalid products
                $mpn = trim(strval($product->mpn));
                if (!$this->validateProductData($mpn, strval($product->name))) {
                    $errors++;
                    continue;
                }
                
                // Get manufacturer name from XML if it exists
                $manufacturer_name = isset($product->brand) ? trim(strval($product->brand)) : '';
                $manufacturer_id = 0;
                
                if (!empty($manufacturer_name)) {
                    $manufacturer_id = $this->getOrCreateManufacturer($manufacturer_name);
                }
                
                // Find products by SKU (was UPC in old script)
                $ex_products = $this->db->query("SELECT product_id FROM " . DB_PREFIX . "product WHERE sku = '" . $this->db->escape($mpn) . "'");
                $ex_products = $ex_products->rows;
                
                $quantity = intval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->quantity)));
                
                if (!empty($ex_products)) {
                    foreach ($ex_products as $ex_product) {
                        $product_ex_id = $ex_product['product_id'];
                        if (!in_array($product_ex_id, $ids_exists)) {
                            $ids_exists[] = $product_ex_id;
                        }
                        
                        // Update quantity and manufacturer if applicable
                        if (!empty($manufacturer_name)) {
                            $this->db->query("UPDATE " . DB_PREFIX . "product SET 
                                quantity = " . (int)$quantity . ",
                                manufacturer_id = " . (int)$manufacturer_id . " 
                                WHERE product_id = " . (int)$product_ex_id);
                        } else {
                            $this->db->query("UPDATE " . DB_PREFIX . "product SET 
                                quantity = " . (int)$quantity . " 
                                WHERE product_id = " . (int)$product_ex_id);
                        }
                        
                        $updated++;
                    }
                }
            }
            
            // Встановлення кількості 0 для товарів, яких немає в файлі
            if (!empty($ids_exists)) {
                $this->db->query("UPDATE " . DB_PREFIX . "product SET quantity = 0 WHERE product_id NOT IN (" . implode(',', $ids_exists) . ")");
            }
            
            return ['updated' => $updated, 'errors' => $errors];
            
        } catch (Exception $e) {
            return ['updated' => $updated, 'errors' => $errors + 1, 'message' => $e->getMessage()];
        }
    }

    // Імпорт нових товарів з 1С
    public function importNewProducts() {
        $created = 0;
        $updated = 0;
        $errors = 0;
        $skipped_products = [];
        
        $products_file = $this->config->get('module_import_1c_quantity_file');
        if (!$products_file) {
            $products_file = self::DEFAULT_FILE_PATH;
        }
        
        $per_page = (int)$this->config->get('module_import_1c_per_page');
        if ($per_page <= 0) {
            $per_page = self::DEFAULT_PER_PAGE;
        }
        
        try {
            // Перевірка наявності файлу
            if (!file_exists($products_file)) {
                throw new Exception('Файл не знайдено: ' . $products_file);
            }

            // Load XML file with encoding handling
            $xml_content = file_get_contents($products_file);
            $feed = simplexml_load_string($xml_content);
            
            if (!$feed) {
                throw new Exception('Помилка при розборі XML файлу');
            }
            
            $total = count($feed->product);

            $page = 1;
            $start = ($page - 1) * $per_page;
            $end = min($start + $per_page, $total);
            
            for ($i = $start; $i < $end; $i++) {
                if (!isset($feed->product[$i])) {
                    continue;
                }
                
                $product = $feed->product[$i];
                
                // Get and validate essential data
                $mpn = trim(strval($product->mpn));
                $name = $this->sanitizeProductName(strval($product->name));
                
                if (!$this->validateProductData($mpn, $name)) {
                    $errors++;
                    // Log skipped product
                    $skipped_products[] = [
                        'mpn' => $mpn,
                        'name' => strval($product->name),
                        'reason' => 'Failed validation: Invalid MPN or empty name'
                    ];
                    continue;
                }
                
                // Get manufacturer name from XML if it exists
                $manufacturer_name = isset($product->brand) ? trim(strval($product->brand)) : '';
                
                // Process category hierarchy from XML
                $category_id = $this->processCategoryHierarchy($product);
                
                $quantity = intval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->quantity)));
                // Get all price values
                $default_price = floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->opt_price)));
                $price_2 = isset($product->price_2) ? floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->price_2))) : 0;
                $price_5 = isset($product->price_5) ? floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->price_5))) : 0;
                $price_7 = isset($product->price_7) ? floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->price_7))) : 0;
                
                // Additional validation for prices
                if ($default_price <= 0) {
                    $errors++;
                    $skipped_products[] = [
                        'mpn' => $mpn,
                        'name' => $name,
                        'reason' => 'Invalid default price: ' . strval($product->opt_price)
                    ];
                    continue;
                }
                
                // Check if product already exists
                $ex_products = $this->db->query("SELECT product_id FROM " . DB_PREFIX . "product WHERE sku = '" . $this->db->escape($mpn) . "'");
                $ex_products = $ex_products->rows;
                
                // Process manufacturer (if brand exists in XML)
                $manufacturer_id = 0;
                if (!empty($manufacturer_name)) {
                    $manufacturer_id = $this->getOrCreateManufacturer($manufacturer_name);
                }
                
                if (empty($ex_products)) {
                    // Create new product (using default price in the main product table)
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product SET 
                        model = '" . $this->db->escape($mpn) . "', 
                        sku = '" . $this->db->escape($mpn) . "', 
                        upc = '', 
                        quantity = " . (int)$quantity . ", 
                        manufacturer_id = " . (int)$manufacturer_id . ",
                        stock_status_id = " . self::DEFAULT_STOCK_STATUS_ID . ", 
                        price = " . floatval($default_price) . ", 
                        status = 1, 
                        date_added = '" . date('Y-m-d H:i:s') . "', 
                        date_modified = '" . date('Y-m-d H:i:s') . "'");
                    
                    $product_ex_id = $this->db->getLastId();
                    
                    // Additional records
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_store SET product_id = " . (int)$product_ex_id . ", store_id = 0");
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_layout SET product_id = " . (int)$product_ex_id . ", store_id = 0, layout_id = 0");
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_category SET product_id = " . (int)$product_ex_id . ", category_id = " . (int)$category_id);
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_description SET product_id = " . (int)$product_ex_id . ", language_id = " . self::DEFAULT_LANGUAGE_ID . ", `name` = '" . $this->db->escape($name) . "', `meta_h1` = '" . $this->db->escape($name) . "'");
                    
                    // SEO URL 
                    $slug = $this->generateSeoUrl($name);
                    $this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET store_id = 0, language_id = " . self::DEFAULT_LANGUAGE_ID . ", `query` = 'product_id=" . $product_ex_id . "', `keyword` = '" . $this->db->escape($slug) . "'");
                    
                    // Add special prices for different customer groups
                    // Handle price_2 (Commercial 2 group)
                    if ($price_2 > 0 && $price_2 != $default_price) {
                        $this->db->query("INSERT INTO " . DB_PREFIX . "product_special SET 
                            product_id = " . (int)$product_ex_id . ", 
                            customer_group_id = " . self::COMMERCIAL_2_GROUP_ID . ", 
                            price = " . floatval($price_2) . ",
                            priority = 1,
                            date_start = '0000-00-00',
                            date_end = '0000-00-00'");
                    }
                    
                    // Handle price_5 (Commercial 5 group)
                    if ($price_5 > 0 && $price_5 != $default_price) {
                        $this->db->query("INSERT INTO " . DB_PREFIX . "product_special SET 
                            product_id = " . (int)$product_ex_id . ", 
                            customer_group_id = " . self::COMMERCIAL_5_GROUP_ID . ", 
                            price = " . floatval($price_5) . ",
                            priority = 1,
                            date_start = '0000-00-00',
                            date_end = '0000-00-00'");
                    }
                    
                    // Handle price_7 (Commercial 7 group)
                    if ($price_7 > 0 && $price_7 != $default_price) {
                        $this->db->query("INSERT INTO " . DB_PREFIX . "product_special SET 
                            product_id = " . (int)$product_ex_id . ", 
                            customer_group_id = " . self::COMMERCIAL_7_GROUP_ID . ", 
                            price = " . floatval($price_7) . ",
                            priority = 1,
                            date_start = '0000-00-00',
                            date_end = '0000-00-00'");
                    }
                    
                    $created++;
                } else {
                    // Update existing product with new data (name, manufacturer, and SEO)
                    foreach ($ex_products as $ex_product) {
                        $product_ex_id = $ex_product['product_id'];
                        
                        // Update product name
                        $this->db->query("UPDATE " . DB_PREFIX . "product_description SET 
                            `name` = '" . $this->db->escape($name) . "',
                            `meta_h1` = '" . $this->db->escape($name) . "'
                            WHERE product_id = " . (int)$product_ex_id . " 
                            AND language_id = " . self::DEFAULT_LANGUAGE_ID);
                        
                        // Update product manufacturer if brand exists in XML
                        if (!empty($manufacturer_name)) {
                            $this->db->query("UPDATE " . DB_PREFIX . "product SET 
                                manufacturer_id = " . (int)$manufacturer_id . "
                                WHERE product_id = " . (int)$product_ex_id);
                        }
                        
                        // Update product category
                        $this->db->query("DELETE FROM " . DB_PREFIX . "product_to_category WHERE product_id = " . (int)$product_ex_id);
                        $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_category SET 
                            product_id = " . (int)$product_ex_id . ", 
                            category_id = " . (int)$category_id);
                        
                        // Update SEO URL
                        $slug = $this->generateSeoUrl($name);
                        
                        $seo_exists = $this->db->query("SELECT keyword FROM " . DB_PREFIX . "seo_url WHERE `query` = 'product_id=" . (int)$product_ex_id . "' AND language_id = " . self::DEFAULT_LANGUAGE_ID);
                        
                        if ($seo_exists->num_rows) {
                            $this->db->query("UPDATE " . DB_PREFIX . "seo_url SET 
                                `keyword` = '" . $this->db->escape($slug) . "' 
                                WHERE `query` = 'product_id=" . (int)$product_ex_id . "' 
                                AND language_id = " . self::DEFAULT_LANGUAGE_ID);
                        } else {
                            $this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET 
                                store_id = 0, 
                                language_id = " . self::DEFAULT_LANGUAGE_ID . ", 
                                `query` = 'product_id=" . $product_ex_id . "', 
                                `keyword` = '" . $this->db->escape($slug) . "'");
                        }
                        
                        $updated++;
                    }
                }
            }
            
            // Write skipped products to log file
            if (!empty($skipped_products)) {
                $this->logSkippedProducts($skipped_products);
            }
            
            // Get manufacturer statistics
            $manufacturer_stats = $this->getManufacturerStats();
            
            // Get category statistics
            $category_stats = $this->getCategoryStats();
            
            return [
                'created' => $created, 
                'updated' => $updated, 
                'errors' => $errors,
                'skipped_products_count' => count($skipped_products),
                'manufacturers_processed' => $manufacturer_stats['manufacturers_processed'],
                'manufacturers_created' => $manufacturer_stats['manufacturers_created'],
                'categories_processed' => $category_stats['categories_processed'],
                'categories_created' => $category_stats['categories_created']
            ];
            
        } catch (Exception $e) {
            // Write skipped products to log file even if there was an exception
            if (!empty($skipped_products)) {
                $this->logSkippedProducts($skipped_products);
            }
            
            // Get manufacturer statistics
            $manufacturer_stats = $this->getManufacturerStats();
            
            // Get category statistics
            $category_stats = $this->getCategoryStats();
            
            return [
                'created' => $created, 
                'updated' => $updated, 
                'errors' => $errors + 1, 
                'message' => $e->getMessage(),
                'skipped_products_count' => count($skipped_products),
                'manufacturers_processed' => $manufacturer_stats['manufacturers_processed'],
                'manufacturers_created' => $manufacturer_stats['manufacturers_created'],
                'categories_processed' => $category_stats['categories_processed'],
                'categories_created' => $category_stats['categories_created']
            ];
        }
    }
    
    /**
     * Log skipped products to a file
     *
     * @param array $skipped_products Array of skipped products
     * @return void
     */
    private function logSkippedProducts($skipped_products) {
        if (empty($skipped_products)) {
            return;
        }
        
        // Log file path - use system/storage/logs if DIR_LOGS constant is not available
        $log_dir = defined('DIR_LOGS') ? DIR_LOGS : DIR_SYSTEM . 'storage/logs/';
        
        // Ensure log directory exists
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        
        $log_file = $log_dir . 'product_import_skipped_' . date('Y-m-d') . '.log';
        
        // Log message
        $log_message = date('Y-m-d H:i:s') . " Products skipped during import:\n" . 
                      json_encode($skipped_products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
        
        // Write to log using file_put_contents with error suppression
        @file_put_contents($log_file, $log_message, FILE_APPEND);
        
        // Fallback to error_log if file_put_contents fails
        if (!file_exists($log_file)) {
            error_log("Product import details: " . count($skipped_products) . " skipped products. File write failed.");
        }
    }

    // Покращена функція транслітерації для SEO-URL
    public function generateSeoUrl($text) {
        // Масив відповідності українських символів до латинських (покращений для SEO)
        $cyr = [
            'Є','Ї','І','Ґ','є','ї','і','ґ','ж','ч','щ','ш','ю','а','б','в','г','д','е','з','и','й','к','л','м','н','о','п','р','с','т','у','ф','х','ц','ь','я',
            'Ж','Ч','Щ','Ш','Ю','А','Б','В','Г','Д','Е','З','И','Й','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Х','Ц','Ь','Я'
        ];
        
        $lat = [
            'Ye','Yi','I','G','ye','yi','i','g','zh','ch','shch','sh','yu','a','b','v','h','d','e','z','y','i','k','l','m','n','o','p','r','s','t','u','f','kh','ts','','ia',
            'Zh','Ch','Shch','Sh','Yu','A','B','V','H','D','E','Z','Y','I','K','L','M','N','O','P','R','S','T','U','F','Kh','Ts','','Ya'
        ];
        
        // Переведення в нижній регістр та заміна кириличних символів
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace($cyr, $lat, $text);
        
        // Замінюємо всі спеціальні символи на дефіс
        $text = preg_replace('/[^a-z0-9]/', '-', $text);
        
        // Видаляємо повторювані дефіси
        $text = preg_replace('/-+/', '-', $text);
        
        // Видаляємо дефіси на початку і в кінці рядка
        $text = trim($text, '-');
        
        // Обмежуємо довжину slug до 64 символів (оптимально для SEO)
        $text = mb_substr($text, 0, 64, 'UTF-8');
        
        // Видаляємо дефіс в кінці, якщо він залишився після обрізання
        $text = rtrim($text, '-');
        
        // Check for uniqueness of the slug and add sequential numbering if needed
        $base_slug = $text;
        $counter = 1;
        
        while (true) {
            $exists = $this->db->query("SELECT COUNT(*) as total FROM " . DB_PREFIX . "seo_url WHERE `keyword` = '" . $this->db->escape($text) . "'");
            
            if ($exists->row['total'] == 0) {
                // Slug is unique, we can use it
                break;
            }
            
            // Add sequential number to the slug
            $text = $base_slug . '-' . $counter;
            $counter++;
        }
        
        return $text;
    }
    
    // Import product images from transfer directory
    public function importProductImages() {
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $results = [];
        
        try {
            // Create target directory if it doesn't exist
            if (!is_dir(self::IMAGES_TARGET_DIR)) {
                mkdir(self::IMAGES_TARGET_DIR, 0755, true);
            }
            
            // Get all image files from the source directory and subdirectories
            $image_files = $this->findImageFiles(self::IMAGES_SOURCE_DIR);
            
            foreach ($image_files as $image_file) {
                // Get the filename without extension to use as SKU
                $file_info = pathinfo($image_file);
                $file_basename = $file_info['filename'];
                $sku = $file_basename;
                
                // Find the product with this SKU
                $product_query = $this->db->query("SELECT product_id, image FROM " . DB_PREFIX . "product WHERE sku = '" . $this->db->escape($sku) . "'");
                
                if ($product_query->num_rows) {
                    $product_id = $product_query->row['product_id'];
                    $current_image = $product_query->row['image'];
                    
                    // Define target image path
                    $new_image_name = 'catalog/products/' . $product_id . '.' . $file_info['extension'];
                    $target_file_path = DIR_IMAGE . $new_image_name;
                    
                    // Check if the image already exists
                    $update_needed = true;
                    
                    if (!empty($current_image) && file_exists(DIR_IMAGE . $current_image)) {
                        // Check if the file is the same
                        if (md5_file($image_file) === md5_file(DIR_IMAGE . $current_image)) {
                            $update_needed = false;
                            $skipped++;
                        }
                    }
                    
                    if ($update_needed) {
                        // Copy image file to target directory with new name
                        if (copy($image_file, $target_file_path)) {
                            // Update the image record in the database
                            $this->db->query("UPDATE " . DB_PREFIX . "product SET 
                                image = '" . $this->db->escape($new_image_name) . "' 
                                WHERE product_id = " . (int)$product_id);
                            
                            $updated++;
                        } else {
                            $errors++;
                            $results[] = "Failed to copy image: " . $image_file;
                        }
                    }
                }
            }
            
            return [
                'updated' => $updated, 
                'skipped' => $skipped, 
                'errors' => $errors,
                'results' => $results
            ];
            
        } catch (Exception $e) {
            return [
                'updated' => $updated, 
                'skipped' => $skipped, 
                'errors' => $errors + 1, 
                'message' => $e->getMessage(),
                'results' => $results
            ];
        }
    }
    
    // Recursive function to find all image files in a directory and its subdirectories
    private function findImageFiles($dir) {
        $image_files = [];
        $allowed_extensions = ['jpg', 'jpeg', 'png'];
        
        // Get all files in the current directory
        $files = scandir($dir);
        
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                // If this is a directory, recursively scan it
                $image_files = array_merge($image_files, $this->findImageFiles($path));
            } else {
                // Check if this is an image file
                $file_info = pathinfo($path);
                if (isset($file_info['extension']) && in_array(strtolower($file_info['extension']), $allowed_extensions)) {
                    $image_files[] = $path;
                }
            }
        }
        
        return $image_files;
    }
    
    // Debug function to log queries
    private function debugLog($message) {
        $log_dir = defined('DIR_LOGS') ? DIR_LOGS : DIR_SYSTEM . 'storage/logs/';
        if (!is_dir($log_dir)) {
            @mkdir($log_dir, 0755, true);
        }
        $log_file = $log_dir . 'sql_debug_' . date('Y-m-d') . '.log';
        @file_put_contents($log_file, date('Y-m-d H:i:s') . " " . $message . "\n\n", FILE_APPEND);
    }
    
    // Import users from XML file
    public function importUsers() {
        $created = 0;
        $updated = 0;
        $deleted = 0;
        $skipped = 0;
        $errors = 0;
        $valid_user_ids = [];
        $valid_user_emails = [];
        $skipped_users_log = [];
        
        try {
            // Debug version information
            $this->debugLog("Starting user import - Version 2025-03-29-17:45");
            
            // Check if file exists
            if (!file_exists(self::USERS_FILE_PATH)) {
                throw new Exception('Users file not found: ' . self::USERS_FILE_PATH);
            }
            
            // Read file content (now using the pre-converted UTF-8 file)
            $xml_content = file_get_contents(self::USERS_FILE_PATH);
            
            // Load XML with LIBXML_NOENT flag to handle entities
            $xml = simplexml_load_string($xml_content, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_NOCDATA);
            if (!$xml) {
                throw new Exception('Error parsing users XML file');
            }
            
            // Start transaction for database integrity
            $this->db->query("START TRANSACTION");
            
            // Process each user in XML
            foreach ($xml->user as $user) {
                $user_id = trim(strval($user->id));
                $email = trim(strval($user->email));
                $name = trim(strval($user->name));
                
                // Skip users with invalid email
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    $skipped_users_log[] = [
                        'id' => $user_id, 
                        'name' => $name, 
                        'email' => $email, 
                        'reason' => 'Invalid email format'
                    ];
                    continue;
                }
                
                // Add user ID to list of valid IDs
                $valid_user_ids[] = $user_id;
                
                // Add email to list of valid emails (for SQL query)
                $valid_user_emails[] = $this->db->escape($email);
                
                // Process user name (split into first name and last name)
                $full_name = trim(strval($user->name));
                $name_parts = explode(' ', $full_name, 2);
                $firstname = $name_parts[0];
                $lastname = isset($name_parts[1]) ? $name_parts[1] : '';
                
                // Log name processing
                if (empty($firstname)) {
                    $skipped_users_log[] = [
                        'id' => $user_id, 
                        'name' => $full_name, 
                        'email' => $email, 
                        'reason' => 'Empty first name after processing'
                    ];
                }
                
                // Validate and process phone number
                $phone = trim(strval($user->phone));
                if (!preg_match('/^[0-9]{10}$/', $phone)) {
                    // Log invalid phone number
                    $skipped_users_log[] = [
                        'id' => $user_id, 
                        'name' => $full_name, 
                        'email' => $email, 
                        'phone' => $phone,
                        'reason' => 'Invalid phone format (not 10 digits)'
                    ];
                    $phone = ''; // Reset invalid phone
                }
                
                // Determine customer group based on price_type
                $price_type = intval(strval($user->price_type));
                
                // Map price_type to customer_group_id according to the requirements
                switch ($price_type) {
                    case 2:
                        $customer_group_id = self::COMMERCIAL_2_GROUP_ID; // Commercial 2 (group_id=2)
                        break;
                    case 5:
                        $customer_group_id = self::COMMERCIAL_5_GROUP_ID; // Commercial 5 (group_id=4)
                        break;
                    case 7:
                        $customer_group_id = self::COMMERCIAL_7_GROUP_ID; // Commercial 7 (group_id=3)
                        break;
                    default:
                        $customer_group_id = self::DEFAULT_CUSTOMER_GROUP_ID; // Default (group_id=1)
                        break;
                }
                
                // Get password from XML
                $password = trim(strval($user->password));
                
                // Prepare password for OpenCart (use OpenCart's password hashing method)
                $salt = substr(md5(mt_rand()), 0, 9);
                $password_hash = sha1($salt . sha1($salt . sha1($password)));
                
                // Check if user exists in database
                $existing_user = $this->db->query("SELECT customer_id FROM " . DB_PREFIX . "customer WHERE email = '" . $this->db->escape($email) . "'");
                
                if ($existing_user->num_rows == 0) {
                    // Create new user
                    $this->db->query("INSERT INTO " . DB_PREFIX . "customer SET 
                        customer_group_id = " . (int)$customer_group_id . ",
                        store_id = 0,
                        language_id = " . self::DEFAULT_LANGUAGE_ID . ",
                        firstname = '" . $this->db->escape($firstname) . "',
                        lastname = '" . $this->db->escape($lastname) . "',
                        email = '" . $this->db->escape($email) . "',
                        telephone = '" . $this->db->escape($phone) . "',
                        password = '" . $this->db->escape($password_hash) . "',
                        salt = '" . $this->db->escape($salt) . "',
                        status = 1,
                        ip = '" . $this->db->escape($this->request->server['REMOTE_ADDR']) . "',
                        date_added = NOW()");
                    
                    $created++;
                } else {
                    // Update existing user
                    $customer_id = $existing_user->row['customer_id'];
                    
                    // Debug: Log the SQL query that will be executed
                    $update_query = "UPDATE " . DB_PREFIX . "customer SET 
                        customer_group_id = " . (int)$customer_group_id . ",
                        firstname = '" . $this->db->escape($firstname) . "',
                        lastname = '" . $this->db->escape($lastname) . "',
                        telephone = '" . $this->db->escape($phone) . "',
                        password = '" . $this->db->escape($password_hash) . "',
                        salt = '" . $this->db->escape($salt) . "'
                        WHERE customer_id = " . (int)$customer_id;
                    
                    $this->debugLog("Executing SQL query: " . $update_query);
                    
                    // Execute update query without date_modified column
                    $this->db->query($update_query);
                    
                    $updated++;
                }
            }
            
            // Get all customers from database and remove those not in XML
            if (!empty($valid_user_emails)) {
                // Get all customers except the ones with emails in the valid list
                $all_customers_query = $this->db->query("SELECT c.customer_id, c.email FROM " . DB_PREFIX . "customer c
                    WHERE c.email NOT IN ('" . implode("','", $valid_user_emails) . "')");
                
                foreach ($all_customers_query->rows as $customer) {
                    // Log the customers being deleted
                    $skipped_users_log[] = [
                        'email' => $customer['email'],
                        'customer_id' => $customer['customer_id'],
                        'reason' => 'User deleted - not found in XML import file'
                    ];
                    
                    // Delete customer and all related data
                    $this->db->query("DELETE FROM " . DB_PREFIX . "customer WHERE customer_id = " . (int)$customer['customer_id']);
                    $this->db->query("DELETE FROM " . DB_PREFIX . "customer_activity WHERE customer_id = " . (int)$customer['customer_id']);
                    $this->db->query("DELETE FROM " . DB_PREFIX . "customer_affiliate WHERE customer_id = " . (int)$customer['customer_id']);
                    $this->db->query("DELETE FROM " . DB_PREFIX . "customer_approval WHERE customer_id = " . (int)$customer['customer_id']);
                    $this->db->query("DELETE FROM " . DB_PREFIX . "customer_history WHERE customer_id = " . (int)$customer['customer_id']);
                    $this->db->query("DELETE FROM " . DB_PREFIX . "customer_ip WHERE customer_id = " . (int)$customer['customer_id']);
                    $this->db->query("DELETE FROM " . DB_PREFIX . "customer_reward WHERE customer_id = " . (int)$customer['customer_id']);
                    $this->db->query("DELETE FROM " . DB_PREFIX . "customer_transaction WHERE customer_id = " . (int)$customer['customer_id']);
                    $this->db->query("DELETE FROM " . DB_PREFIX . "customer_wishlist WHERE customer_id = " . (int)$customer['customer_id']);
                    $this->db->query("DELETE FROM " . DB_PREFIX . "address WHERE customer_id = " . (int)$customer['customer_id']);
                    
                    $deleted++;
                }
            }
            
            // Commit transaction
            $this->db->query("COMMIT");
            
            // Write skipped users log to file for debugging
            if (!empty($skipped_users_log)) {
                // Log file path - use system/storage/logs if DIR_LOGS constant is not available
                $log_dir = defined('DIR_LOGS') ? DIR_LOGS : DIR_SYSTEM . 'storage/logs/';
                
                // Ensure log directory exists
                if (!is_dir($log_dir)) {
                    @mkdir($log_dir, 0755, true);
                }
                
                $log_file = $log_dir . 'user_import_' . date('Y-m-d') . '.log';
                
                // Log message
                $log_message = date('Y-m-d H:i:s') . " User import details:\n" . 
                               json_encode($skipped_users_log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
                
                // Write to log using file_put_contents with error suppression
                @file_put_contents($log_file, $log_message, FILE_APPEND);
                
                // Fallback to error_log if file_put_contents fails
                if (!file_exists($log_file)) {
                    error_log("User import details: " . count($skipped_users_log) . " skipped users. File write failed.");
                }
            }
            
            return [
                'created' => $created,
                'updated' => $updated,
                'deleted' => $deleted,
                'skipped' => $skipped,
                'errors' => $errors,
                'skipped_users' => $skipped_users_log
            ];
            
        } catch (Exception $e) {
            // Rollback transaction in case of error
            $this->db->query("ROLLBACK");
            
            // Write skipped users log to file for debugging
            if (!empty($skipped_users_log)) {
                // Log file path - use system/storage/logs if DIR_LOGS constant is not available
                $log_dir = defined('DIR_LOGS') ? DIR_LOGS : DIR_SYSTEM . 'storage/logs/';
                
                // Ensure log directory exists
                if (!is_dir($log_dir)) {
                    @mkdir($log_dir, 0755, true);
                }
                
                $log_file = $log_dir . 'user_import_' . date('Y-m-d') . '.log';
                
                // Log message with error information
                $log_message = date('Y-m-d H:i:s') . " User import error: " . $e->getMessage() . "\n" .
                               json_encode($skipped_users_log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
                
                // Write to log using file_put_contents with error suppression
                @file_put_contents($log_file, $log_message, FILE_APPEND);
                
                // Fallback to error_log if file_put_contents fails
                if (!file_exists($log_file)) {
                    error_log("User import error: " . $e->getMessage() . ". " . count($skipped_users_log) . " skipped users. File write failed.");
                }
            }
            
            return [
                'created' => $created,
                'updated' => $updated,
                'deleted' => $deleted,
                'skipped' => $skipped,
                'errors' => $errors + 1,
                'message' => $e->getMessage(),
                'skipped_users' => $skipped_users_log
            ];
        }
    }
    
    /**
     * Export orders to XML
     * Creates XML files for orders with status not equal to 5 (processed)
     * and updates the order status to 5 after exporting
     * 
     * @return array Results of the export operation
     */
    /**
     * Process category hierarchy from XML data
     * Extracts categories from XML tags (category_1 to category_10)
     * Creates categories if they don't exist and returns the deepest category ID
     *
     * @param object $product The product XML object
     * @return int The category ID to associate with the product
     */
    private function processCategoryHierarchy($product) {
        static $categories_processed = 0;
        static $categories_created = 0;
        static $category_cache = [];
        
        // Start with default category
        $category_id = self::DEFAULT_CATEGORY_ID;
        $parent_id = 0;
        $last_valid_category_id = self::DEFAULT_CATEGORY_ID;
        
        for ($level = 1; $level <= self::MAX_CATEGORY_LEVELS; $level++) {
            $category_tag = "category_{$level}";
            
            // Skip if category tag doesn't exist or is empty
            if (!isset($product->$category_tag) || empty(trim(strval($product->$category_tag)))) {
                continue;
            }
            
            $category_name = trim(strval($product->$category_tag));
            
            // Skip empty categories
            if (empty($category_name)) {
                continue;
            }
            
            // Cache key combines parent ID and category name to ensure proper hierarchy
            $cache_key = $parent_id . '_' . $category_name;
            
            if (isset($category_cache[$cache_key])) {
                // Use cached category ID
                $category_id = $category_cache[$cache_key];
            } else {
                // Get or create category
                $categories_processed++;
                $category_id = $this->getOrCreateCategory($category_name, $parent_id, $level);
                $category_cache[$cache_key] = $category_id;
                
                if ($category_id > 0) {
                    $categories_created++;
                }
            }
            
            // Update parent for next level
            $parent_id = $category_id;
            $last_valid_category_id = $category_id;
        }
        
        return $last_valid_category_id;
    }
    
    /**
     * Get or create a category
     * Checks if the category exists in the database and returns its ID
     * If it doesn't exist, creates a new category with proper hierarchy
     *
     * @param string $category_name The name of the category
     * @param int $parent_id The parent category ID (0 for root)
     * @param int $level The level in the hierarchy (1-based)
     * @return int The category ID
     */
    private function getOrCreateCategory($category_name, $parent_id, $level) {
        // Check if category exists under this parent
        $category_query = $this->db->query("SELECT c.category_id FROM " . DB_PREFIX . "category c 
            JOIN " . DB_PREFIX . "category_description cd ON c.category_id = cd.category_id 
            WHERE cd.name = '" . $this->db->escape($category_name) . "' 
            AND c.parent_id = " . (int)$parent_id . " 
            AND cd.language_id = " . self::DEFAULT_LANGUAGE_ID);
        
        if ($category_query->num_rows > 0) {
            // Category exists, return the ID
            return (int)$category_query->row['category_id'];
        }
        
        // Category doesn't exist, create it
        $this->debugLog("Creating new category: " . $category_name . " under parent ID " . $parent_id . " at level " . $level);
        
        // Set top flag (1 for root categories, 0 for children)
        $top = ($parent_id == 0) ? 1 : 0;
        
        // Insert into oc_category table
        $this->db->query("INSERT INTO " . DB_PREFIX . "category SET 
            parent_id = " . (int)$parent_id . ", 
            `top` = " . (int)$top . ", 
            `column` = " . self::CATEGORY_COLUMN . ", 
            sort_order = " . self::CATEGORY_SORT_ORDER . ", 
            status = " . self::CATEGORY_STATUS . ", 
            noindex = " . self::CATEGORY_NOINDEX . ", 
            date_added = NOW(), 
            date_modified = NOW()");
        
        $category_id = $this->db->getLastId();
        
        // Insert into oc_category_description table
        $this->db->query("INSERT INTO " . DB_PREFIX . "category_description SET 
            category_id = " . (int)$category_id . ", 
            language_id = " . self::DEFAULT_LANGUAGE_ID . ", 
            name = '" . $this->db->escape($category_name) . "', 
            meta_h1 = '" . $this->db->escape($category_name) . "',
            description = '',
            meta_title = '',
            meta_description = '',
            meta_keyword = ''");
        
        // Create SEO URL for category
        $slug = $this->generateSeoUrl($category_name);
        $this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET 
            store_id = 0, 
            language_id = " . self::DEFAULT_LANGUAGE_ID . ", 
            `query` = 'category_id=" . $category_id . "', 
            `keyword` = '" . $this->db->escape($slug) . "'");
        
        // Insert into oc_category_to_layout table
        $this->db->query("INSERT INTO " . DB_PREFIX . "category_to_layout SET 
            category_id = " . (int)$category_id . ", 
            store_id = 0, 
            layout_id = 0");
        
        // Insert into oc_category_to_store table
        $this->db->query("INSERT INTO " . DB_PREFIX . "category_to_store SET 
            category_id = " . (int)$category_id . ", 
            store_id = 0");
        
        // Handle category path records
        $this->updateCategoryPath($category_id, $parent_id, $level);
        
        return (int)$category_id;
    }
    
    /**
     * Update the category path records for a category
     * OpenCart uses oc_category_path to optimize nested queries
     *
     * @param int $category_id The category ID
     * @param int $parent_id The parent category ID
     * @param int $level The level in the hierarchy (1-based)
     */
    private function updateCategoryPath($category_id, $parent_id, $level) {
        // Clear existing path entries
        $this->db->query("DELETE FROM " . DB_PREFIX . "category_path WHERE category_id = " . (int)$category_id);
        
        // If this is a root category (level 1)
        if ($level == 1) {
            // Just add self-reference path
            $this->db->query("INSERT INTO " . DB_PREFIX . "category_path SET 
                category_id = " . (int)$category_id . ", 
                path_id = " . (int)$category_id . ", 
                level = 0");
        } else {
            // For non-root categories, need to include paths from parent
            $parent_paths_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "category_path 
                WHERE category_id = " . (int)$parent_id . " 
                ORDER BY level ASC");
            
            // Add parent paths
            foreach ($parent_paths_query->rows as $path) {
                $this->db->query("INSERT INTO " . DB_PREFIX . "category_path SET 
                    category_id = " . (int)$category_id . ", 
                    path_id = " . (int)$path['path_id'] . ", 
                    level = " . ((int)$path['level'] + 1));
            }
            
            // Add self-reference path
            $this->db->query("INSERT INTO " . DB_PREFIX . "category_path SET 
                category_id = " . (int)$category_id . ", 
                path_id = " . (int)$category_id . ", 
                level = " . ($level - 1));
        }
    }
    
    /**
     * Get statistics about categories processed
     *
     * @return array Statistics about categories
     */
    public function getCategoryStats() {
        static $categories_processed = 0;
        static $categories_created = 0;
        
        return [
            'categories_processed' => $categories_processed,
            'categories_created' => $categories_created
        ];
    }
    
    /**
     * Get or create a manufacturer
     * Checks if the manufacturer exists in the database and returns its ID
     * If it doesn't exist, creates a new manufacturer
     *
     * @param string $manufacturer_name The name of the manufacturer
     * @return int The manufacturer ID
     */
    private function getOrCreateManufacturer($manufacturer_name) {
        static $manufacturers_processed = 0;
        static $manufacturers_created = 0;
        static $cache = [];
        
        $manufacturer_name = trim($manufacturer_name);
        
        // If manufacturer name is empty, return 0 (no manufacturer)
        if (empty($manufacturer_name)) {
            return 0;
        }
        
        // Check in local cache first for performance
        if (isset($cache[$manufacturer_name])) {
            return $cache[$manufacturer_name];
        }
        
        $manufacturers_processed++;
        
        // Check if manufacturer already exists
        $manufacturer_query = $this->db->query("SELECT manufacturer_id FROM " . DB_PREFIX . "manufacturer 
            WHERE name = '" . $this->db->escape($manufacturer_name) . "'");
        
        if ($manufacturer_query->num_rows > 0) {
            // Manufacturer exists, return the ID
            $manufacturer_id = (int)$manufacturer_query->row['manufacturer_id'];
            $cache[$manufacturer_name] = $manufacturer_id;
            return $manufacturer_id;
        } else {
            // Manufacturer doesn't exist, create new one
            $manufacturers_created++;
            
            // Log the new manufacturer creation
            $this->debugLog("Creating new manufacturer: " . $manufacturer_name);
            
            // Insert into oc_manufacturer table
            $this->db->query("INSERT INTO " . DB_PREFIX . "manufacturer SET 
                name = '" . $this->db->escape($manufacturer_name) . "', 
                image = '', 
                sort_order = " . self::MANUFACTURER_SORT_ORDER . ", 
                noindex = " . self::MANUFACTURER_NOINDEX);
            
            $manufacturer_id = $this->db->getLastId();
            
            // Insert into oc_manufacturer_description table
            $this->db->query("INSERT INTO " . DB_PREFIX . "manufacturer_description SET 
                manufacturer_id = " . (int)$manufacturer_id . ", 
                language_id = " . self::DEFAULT_LANGUAGE_ID . ", 
                meta_h1 = '" . $this->db->escape($manufacturer_name) . "',
                description = '',
                meta_title = '',
                meta_description = '',
                meta_keyword = ''");
            
            // Insert into oc_manufacturer_to_layout table
            $this->db->query("INSERT INTO " . DB_PREFIX . "manufacturer_to_layout SET 
                manufacturer_id = " . (int)$manufacturer_id . ", 
                store_id = 0, 
                layout_id = 0");
            
            // Insert into oc_manufacturer_to_store table
            $this->db->query("INSERT INTO " . DB_PREFIX . "manufacturer_to_store SET 
                manufacturer_id = " . (int)$manufacturer_id . ", 
                store_id = 0");
                
            // Create SEO URL for manufacturer
            $slug = $this->generateSeoUrl($manufacturer_name);
            $this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET 
                store_id = 0, 
                language_id = " . self::DEFAULT_LANGUAGE_ID . ", 
                `query` = 'manufacturer_id=" . $manufacturer_id . "', 
                `keyword` = '" . $this->db->escape($slug) . "'");
            
            $cache[$manufacturer_name] = $manufacturer_id;
            return (int)$manufacturer_id;
        }
    }
    
    /**
     * Get statistics about manufacturers processed
     *
     * @return array Statistics about manufacturers
     */
    public function getManufacturerStats() {
        static $manufacturers_processed = 0;
        static $manufacturers_created = 0;
        
        return [
            'manufacturers_processed' => $manufacturers_processed,
            'manufacturers_created' => $manufacturers_created
        ];
    }

    /**
     * Log images that exist on the server but have not been added to products
     * 
     * @return array Results of the scan
     */
    public function logUnusedImages() {
        $found = 0;
        $errors = 0;
        
        try {
            // Get all image files from the source directory and subdirectories
            $image_files = $this->findImageFiles(self::IMAGES_SOURCE_DIR);
            $unused_images = [];
            
            foreach ($image_files as $image_file) {
                // Get the filename without extension to use as SKU
                $file_info = pathinfo($image_file);
                $file_basename = $file_info['filename'];
                $sku = $file_basename;
                
                // Find the product with this SKU
                $product_query = $this->db->query("SELECT product_id FROM " . DB_PREFIX . "product WHERE sku = '" . $this->db->escape($sku) . "'");
                
                // If no product with this SKU exists, add to unused images list
                if ($product_query->num_rows == 0) {
                    $unused_images[] = $image_file;  // Just store the full path to the image
                    $found++;
                }
            }
            
            // Create log of unused images
            if (!empty($unused_images)) {
                // Log file path - use system/storage/logs if DIR_LOGS constant is not available
                $log_dir = defined('DIR_LOGS') ? DIR_LOGS : DIR_SYSTEM . 'storage/logs/';
                
                // Ensure log directory exists
                if (!is_dir($log_dir)) {
                    @mkdir($log_dir, 0755, true);
                }
                
                $log_file = $log_dir . 'unused_images_' . date('Y-m-d') . '.log';
                
                // Create a detailed log message with each image on a new line
                $log_message = date('Y-m-d H:i:s') . " - Found " . count($unused_images) . " unused images:\n\n";
                
                // Add each image path on a new line for better readability
                foreach ($unused_images as $image_path) {
                    $log_message .= $image_path . "\n";
                }
                
                $log_message .= "\n"; // Add extra newline at the end
                
                // Write to log using file_put_contents with error suppression
                @file_put_contents($log_file, $log_message, FILE_APPEND);
                
                // Fallback to error_log if file_put_contents fails
                if (!file_exists($log_file)) {
                    error_log("Unused images scan details: " . count($unused_images) . " images found. File write failed.");
                }
            }
            
            return [
                'found' => $found,
                'errors' => $errors,
                'log_file' => isset($log_dir) ? $log_dir . 'unused_images_' . date('Y-m-d') . '.log' : ''
            ];
            
        } catch (Exception $e) {
            return [
                'found' => $found,
                'errors' => $errors + 1,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function exportOrders() {
        $exported = 0;
        $errors = 0;
        $results = [];
        
        try {
            // Create orders export directory if it doesn't exist
            if (!is_dir(self::ORDERS_EXPORT_DIR)) {
                if (!mkdir(self::ORDERS_EXPORT_DIR, 0755, true)) {
                    throw new Exception('Failed to create orders export directory: ' . self::ORDERS_EXPORT_DIR);
                }
            }
            
            // Get orders with status not equal to 5 (processed/exported)
            $orders_query = $this->db->query("SELECT o.* FROM " . DB_PREFIX . "order o WHERE o.order_status_id != 5");
            
            if ($orders_query->num_rows === 0) {
                return ['exported' => 0, 'errors' => 0, 'message' => 'No orders to export'];
            }
            
            foreach ($orders_query->rows as $order) {
                try {
                    // Get order products
                    $products_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product 
                        WHERE order_id = " . (int)$order['order_id']);
                    
                    if ($products_query->num_rows === 0) {
                        throw new Exception("No products found for order #" . $order['order_id']);
                    }
                    
                    // Get order totals
                    $totals_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_total 
                        WHERE order_id = " . (int)$order['order_id'] . " 
                        ORDER BY sort_order ASC");
                    
                    // Create XML document
                    $dom = new DOMDocument('1.0', 'UTF-8');
                    $dom->formatOutput = true;
                    
                    // Root element
                    $orders = $dom->createElement('orders');
                    $dom->appendChild($orders);
                    
                    // Order element
                    $order_elem = $dom->createElement('order');
                    $orders->appendChild($order_elem);
                    
                    // Order ID
                    $id_elem = $dom->createElement('id', $order['order_id']);
                    $order_elem->appendChild($id_elem);
                    
                    // Customer information
                    $customer = $dom->createElement('customer');
                    $order_elem->appendChild($customer);
                    
                    // Full name (firstname + lastname)
                    $name = trim($order['firstname'] . ' ' . $order['lastname']);
                    $name_elem = $dom->createElement('name');
                    $name_elem->appendChild($dom->createTextNode($name));
                    $customer->appendChild($name_elem);
                    
                    // Phone
                    $phone_elem = $dom->createElement('phone');
                    $phone_elem->appendChild($dom->createTextNode($order['telephone']));
                    $customer->appendChild($phone_elem);
                    
                    // Email
                    $email_elem = $dom->createElement('email');
                    $email_elem->appendChild($dom->createTextNode($order['email']));
                    $customer->appendChild($email_elem);
                    
                    // Shipping address
                    $address = '';
                    
                    // Build address from shipping info
                    if (!empty($order['shipping_zone'])) {
                        $address .= $order['shipping_zone'];
                    }
                    
                    if (!empty($order['shipping_city'])) {
                        if (!empty($address)) $address .= ', ';
                        $address .= $order['shipping_city'];
                    }
                    
                    if (!empty($order['shipping_address_1'])) {
                        if (!empty($address)) $address .= ', ';
                        $address .= $order['shipping_address_1'];
                    }
                    
                    if (!empty($order['shipping_address_2'])) {
                        if (!empty($address)) $address .= ', ';
                        $address .= $order['shipping_address_2'];
                    }
                    
                    // If shipping address is empty, use payment address
                    if (empty($address)) {
                        if (!empty($order['payment_zone'])) {
                            $address .= $order['payment_zone'];
                        }
                        
                        if (!empty($order['payment_city'])) {
                            if (!empty($address)) $address .= ', ';
                            $address .= $order['payment_city'];
                        }
                        
                        if (!empty($order['payment_address_1'])) {
                            if (!empty($address)) $address .= ', ';
                            $address .= $order['payment_address_1'];
                        }
                        
                        if (!empty($order['payment_address_2'])) {
                            if (!empty($address)) $address .= ', ';
                            $address .= $order['payment_address_2'];
                        }
                    }
                    
                    // Add shipping method if available
                    if (!empty($order['shipping_method'])) {
                        if (!empty($address)) $address .= ': ';
                        $address .= $order['shipping_method'];
                    }
                    
                    $address_elem = $dom->createElement('address');
                    $address_elem->appendChild($dom->createTextNode($address));
                    $customer->appendChild($address_elem);
                    
                    // Order date (extract date part from date_added)
                    $date = date('Y-m-d', strtotime($order['date_added']));
                    $date_elem = $dom->createElement('date', $date);
                    $order_elem->appendChild($date_elem);
                    
                    // Products
                    $products_elem = $dom->createElement('products');
                    $order_elem->appendChild($products_elem);
                    
                    foreach ($products_query->rows as $product) {
                        $product_elem = $dom->createElement('product');
                        $products_elem->appendChild($product_elem);
                        
                        // Use model field as MPN (modify if your store uses a different field)
                        $mpn_elem = $dom->createElement('mpn');
                        $mpn_elem->appendChild($dom->createTextNode($product['model']));
                        $product_elem->appendChild($mpn_elem);
                        
                        // Quantity
                        $quantity_elem = $dom->createElement('quantity', (int)$product['quantity']);
                        $product_elem->appendChild($quantity_elem);
                        
                        // Price (per unit)
                        $price_elem = $dom->createElement('price', number_format((float)$product['price'], 2, '.', ''));
                        $product_elem->appendChild($price_elem);
                    }
                    
                    // Create file path with order_id as filename
                    $file_path = self::ORDERS_EXPORT_DIR . '/' . $order['order_id'] . '.xml';
                    
                    // Save XML to file
                    if ($dom->save($file_path)) {
                        // Update order status to 5 (processed/exported)
                        $this->db->query("UPDATE " . DB_PREFIX . "order SET 
                            order_status_id = 5, 
                            date_modified = NOW() 
                            WHERE order_id = " . (int)$order['order_id']);
                        
                        $exported++;
                        $results[] = "Order #" . $order['order_id'] . " exported to " . $file_path;
                    } else {
                        throw new Exception("Failed to save XML file for order #" . $order['order_id']);
                    }
                    
                } catch (Exception $e) {
                    $errors++;
                    $results[] = "Error exporting order #" . $order['order_id'] . ": " . $e->getMessage();
                    
                    // Log the error using the debug log function
                    $this->debugLog("Order export error for order #" . $order['order_id'] . ": " . $e->getMessage());
                }
            }
            
            // If no orders were exported, update the message
            if ($exported === 0 && empty($results)) {
                $results[] = "No orders were exported.";
            }
            
            return [
                'exported' => $exported,
                'errors' => $errors,
                'results' => $results
            ];
            
        } catch (Exception $e) {
            // Log the error using the debug log function
            $this->debugLog("Order export general error: " . $e->getMessage());
            
            return [
                'exported' => $exported,
                'errors' => $errors + 1,
                'message' => $e->getMessage(),
                'results' => $results
            ];
        }
    }
}