<?php
class ModelExtensionModuleImport1C extends Model {
    // Constants
    const DEFAULT_FILE_PATH = '/home/cr548725/feniks-lviv.com.ua/transfer/products_test.xml';
    const DEFAULT_PER_PAGE = 10000;
    const DEFAULT_CATEGORY_ID = 511;
    const DEFAULT_STOCK_STATUS_ID = 7;
    const DEFAULT_LANGUAGE_ID = 3;
    const WHOLESALE_CUSTOMER_GROUP_ID = 2;
    const IMAGES_SOURCE_DIR = '/home/cr548725/feniks-lviv.com.ua/transfer/';
    const IMAGES_TARGET_DIR = '/home/cr548725/feniks-lviv.com.ua/www/image/catalog/products/';
    
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
                
                if (!empty($ex_products)) {
                    foreach ($ex_products as $ex_product) {
                        $product_ex_id = $ex_product['product_id'];
                        
                        // Update regular price
                        $retail_price = floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->price)));
                        $this->db->query("UPDATE " . DB_PREFIX . "product SET price = " . floatval($retail_price) . " WHERE product_id = " . (int)$product_ex_id);
                        
                        // Update wholesale price (as special price)
                        $wholesale_price = floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->opt_price)));
                        $this->db->query("DELETE FROM " . DB_PREFIX . "product_special WHERE product_id = " . (int)$product_ex_id);
                        
                        if ($wholesale_price > 0 && $wholesale_price != $retail_price) {
                            $this->db->query("INSERT INTO " . DB_PREFIX . "product_special SET 
                                product_id = " . (int)$product_ex_id . ", 
                                customer_group_id = " . self::WHOLESALE_CUSTOMER_GROUP_ID . ", 
                                price = " . floatval($wholesale_price) . ",
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
                        $this->db->query("UPDATE " . DB_PREFIX . "product SET quantity = " . (int)$quantity . " WHERE product_id = " . (int)$product_ex_id);
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
                    continue;
                }
                
                $quantity = intval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->quantity)));
                $retail_price = floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->price)));
                $wholesale_price = floatval(str_replace([' ', ' ', ','], ['', '', '.'], strval($product->opt_price)));
                
                // Check if product already exists
                $ex_products = $this->db->query("SELECT product_id FROM " . DB_PREFIX . "product WHERE sku = '" . $this->db->escape($mpn) . "'");
                $ex_products = $ex_products->rows;
                
                if (empty($ex_products)) {
                    // Створення нового товару
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product SET 
                        model = '" . $this->db->escape($mpn) . "', 
                        sku = '" . $this->db->escape($mpn) . "', 
                        upc = '', 
                        quantity = " . (int)$quantity . ", 
                        stock_status_id = " . self::DEFAULT_STOCK_STATUS_ID . ", 
                        price = " . floatval($retail_price) . ", 
                        status = 1, 
                        date_added = '" . date('Y-m-d H:i:s') . "', 
                        date_modified = '" . date('Y-m-d H:i:s') . "'");
                    
                    $product_ex_id = $this->db->getLastId();
                    
                    // Додаткові записи
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_store SET product_id = " . (int)$product_ex_id . ", store_id = 0");
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_layout SET product_id = " . (int)$product_ex_id . ", store_id = 0, layout_id = 0");
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_category SET product_id = " . (int)$product_ex_id . ", category_id = " . self::DEFAULT_CATEGORY_ID);
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_description SET product_id = " . (int)$product_ex_id . ", language_id = " . self::DEFAULT_LANGUAGE_ID . ", `name` = '" . $this->db->escape($name) . "', `meta_h1` = '" . $this->db->escape($name) . "'");
                    
                    // SEO URL використовуючи покращену транслітерацію (лише ім'я)
                    $slug = $this->generateSeoUrl($name);
                    $this->db->query("INSERT INTO " . DB_PREFIX . "seo_url SET store_id = 0, language_id = " . self::DEFAULT_LANGUAGE_ID . ", `query` = 'product_id=" . $product_ex_id . "', `keyword` = '" . $this->db->escape($slug) . "'");
                    
                    // Add wholesale price if different
                    if ($wholesale_price > 0 && $wholesale_price != $retail_price) {
                        $this->db->query("INSERT INTO " . DB_PREFIX . "product_special SET 
                            product_id = " . (int)$product_ex_id . ", 
                            customer_group_id = " . self::WHOLESALE_CUSTOMER_GROUP_ID . ", 
                            price = " . floatval($wholesale_price) . ",
                            priority = 1,
                            date_start = '0000-00-00',
                            date_end = '0000-00-00'");
                    }
                    
                    $created++;
                } else {
                    // Update existing product with new data (name and SEO)
                    foreach ($ex_products as $ex_product) {
                        $product_ex_id = $ex_product['product_id'];
                        
                        // Update product name
                        $this->db->query("UPDATE " . DB_PREFIX . "product_description SET 
                            `name` = '" . $this->db->escape($name) . "',
                            `meta_h1` = '" . $this->db->escape($name) . "'
                            WHERE product_id = " . (int)$product_ex_id . " 
                            AND language_id = " . self::DEFAULT_LANGUAGE_ID);
                        
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
            
            return ['created' => $created, 'updated' => $updated, 'errors' => $errors];
            
        } catch (Exception $e) {
            return ['created' => $created, 'updated' => $updated, 'errors' => $errors + 1, 'message' => $e->getMessage()];
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
}