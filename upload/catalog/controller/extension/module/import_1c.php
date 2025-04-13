<?php
class ControllerExtensionModuleImport1C extends Controller {
    // Метод для запуску через CRON
    public function cron() {
        // Перевірка IP для безпеки (опціонально)
        // if (!in_array($this->request->server['REMOTE_ADDR'], ['127.0.0.1', 'your_server_ip'])) {
        //     exit('Access denied');
        // }
        
        $this->load->model('extension/module/import_1c');
        
        // Запуск імпорту цін
        $prices_result = $this->model_extension_module_import_1c->importPrices();
        
        // Запуск імпорту кількості
        $quantities_result = $this->model_extension_module_import_1c->importQuantities();
        
        // Запуск імпорту нових товарів
        $products_result = $this->model_extension_module_import_1c->importNewProducts();
        
        // Запуск імпорту зображень товарів
        $images_result = $this->model_extension_module_import_1c->importProductImages();
        
        // Конвертування файлу користувачів з Windows-1251 в UTF-8
        $this->load->controller('extension/module/convert_encoding/cron');
        
        // Force model reload to avoid caching issues with modified files
        $this->registry->set('model_extension_module_import_1c', null);
        $this->load->model('extension/module/import_1c');
        
        // Запуск імпорту користувачів (після конвертування)
        $users_result = $this->model_extension_module_import_1c->importUsers();
        
        // Export orders to XML
        $orders_result = $this->model_extension_module_import_1c->exportOrders();
        
        // Log unused images
        $unused_images_result = $this->model_extension_module_import_1c->logUnusedImages();
        
        // Clean up manufacturers without products
        $manufacturers_cleanup_result = $this->model_extension_module_import_1c->cleanupManufacturers();
        
        // Clean up categories without products
        $categories_cleanup_result = $this->model_extension_module_import_1c->cleanupCategories();
        
        // Виведення результатів
        $response = [
            'prices_updated' => $prices_result['updated'],
            'quantities_updated' => $quantities_result['updated'],
            'products_created' => $products_result['created'],
            'products_updated' => $products_result['updated'],
            'images_updated' => $images_result['updated'],
            'images_skipped' => $images_result['skipped'],
            'users_created' => $users_result['created'],
            'users_updated' => $users_result['updated'],
            'users_deleted' => $users_result['deleted'],
            'users_skipped' => $users_result['skipped'],
            'manufacturers_processed' => isset($products_result['manufacturers_processed']) ? $products_result['manufacturers_processed'] : 0,
            'manufacturers_created' => isset($products_result['manufacturers_created']) ? $products_result['manufacturers_created'] : 0,
            'manufacturers_removed' => isset($manufacturers_cleanup_result['removed']) ? $manufacturers_cleanup_result['removed'] : 0,
            'categories_processed' => isset($products_result['categories_processed']) ? $products_result['categories_processed'] : 0,
            'categories_created' => isset($products_result['categories_created']) ? $products_result['categories_created'] : 0,
            'categories_removed' => isset($categories_cleanup_result['removed']) ? $categories_cleanup_result['removed'] : 0,
            'attributes_processed' => isset($products_result['attributes_processed']) ? $products_result['attributes_processed'] : 0,
            'attributes_created' => isset($products_result['attributes_created']) ? $products_result['attributes_created'] : 0,
            'attribute_values_added' => isset($products_result['attribute_values_added']) ? $products_result['attribute_values_added'] : 0,
            'orders_exported' => isset($orders_result['exported']) ? $orders_result['exported'] : 0,
            'unused_images_found' => isset($unused_images_result['found']) ? $unused_images_result['found'] : 0,
            'errors' => $prices_result['errors'] + $quantities_result['errors'] + $products_result['errors'] + 
                    $images_result['errors'] + $users_result['errors'] + 
                    (isset($orders_result['errors']) ? $orders_result['errors'] : 0) +
                    (isset($unused_images_result['errors']) ? $unused_images_result['errors'] : 0) +
                    (isset($manufacturers_cleanup_result['errors']) ? $manufacturers_cleanup_result['errors'] : 0) +
                    (isset($categories_cleanup_result['errors']) ? $categories_cleanup_result['errors'] : 0)
        ];
        
        // Include skipped users details if they exist (limit to first 10 for JSON response size)
        if (isset($users_result['skipped_users']) && !empty($users_result['skipped_users'])) {
            $response['skipped_users_count'] = count($users_result['skipped_users']);
            $response['skipped_users_sample'] = array_slice($users_result['skipped_users'], 0, 10);
            $log_dir = defined('DIR_LOGS') ? DIR_LOGS : DIR_SYSTEM . 'storage/logs/';
            $response['skipped_users_log'] = 'See detailed log in ' . $log_dir . 'user_import_' . date('Y-m-d') . '.log';
        }
        
        // Include skipped products information if available
        if (isset($products_result['skipped_products_count'])) {
            $response['skipped_products_count'] = $products_result['skipped_products_count'];
            $log_dir = defined('DIR_LOGS') ? DIR_LOGS : DIR_SYSTEM . 'storage/logs/';
            $response['skipped_products_log'] = 'See detailed log in ' . $log_dir . 'product_import_skipped_' . date('Y-m-d') . '.log';
        }
        
        // Include unused images log file path if available
        if (isset($unused_images_result['found']) && $unused_images_result['found'] > 0) {
            $log_dir = defined('DIR_LOGS') ? DIR_LOGS : DIR_SYSTEM . 'storage/logs/';
            $response['unused_images_log'] = 'See detailed log in ' . $log_dir . 'unused_images_' . date('Y-m-d') . '.log';
        }
        
        // Include manufacturer cleanup details if available
        if (isset($manufacturers_cleanup_result['removed_list']) && !empty($manufacturers_cleanup_result['removed_list'])) {
            $response['manufacturers_removed_list'] = array_slice($manufacturers_cleanup_result['removed_list'], 0, 20); // Show max 20 items
            if (count($manufacturers_cleanup_result['removed_list']) > 20) {
                $response['manufacturers_removed_more'] = count($manufacturers_cleanup_result['removed_list']) - 20;
            }
        }
        
        // Include category cleanup details if available
        if (isset($categories_cleanup_result['removed_list']) && !empty($categories_cleanup_result['removed_list'])) {
            $response['categories_removed_list'] = array_slice($categories_cleanup_result['removed_list'], 0, 20); // Show max 20 items
            if (count($categories_cleanup_result['removed_list']) > 20) {
                $response['categories_removed_more'] = count($categories_cleanup_result['removed_list']) - 20;
            }
        }
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Export Orders to XML via CRON
     * Can be called separately for more frequent order exports
     */
    public function exportOrdersCron() {
        // Optional IP security check
        // if (!in_array($this->request->server['REMOTE_ADDR'], ['127.0.0.1', 'your_server_ip'])) {
        //     exit('Access denied');
        // }
        
        $this->load->model('extension/module/import_1c');
        
        // Export orders to XML
        $orders_result = $this->model_extension_module_import_1c->exportOrders();
        
        // Prepare the response
        $response = [
            'orders_exported' => $orders_result['exported'],
            'errors' => $orders_result['errors']
        ];
        
        // Include detailed results if available
        if (isset($orders_result['results']) && !empty($orders_result['results'])) {
            $response['results'] = $orders_result['results'];
        }
        
        // Include error message if available
        if (isset($orders_result['message'])) {
            $response['message'] = $orders_result['message'];
        }
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Log Unused Images via CRON
     * Creates a log file listing all images on the server that don't have corresponding products
     */
    public function logUnusedImagesCron() {
        // Optional IP security check
        // if (!in_array($this->request->server['REMOTE_ADDR'], ['127.0.0.1', 'your_server_ip'])) {
        //     exit('Access denied');
        // }
        
        $this->load->model('extension/module/import_1c');
        
        // Log unused images
        $log_result = $this->model_extension_module_import_1c->logUnusedImages();
        
        // Prepare the response
        $response = [
            'images_found' => $log_result['found'],
            'errors' => $log_result['errors']
        ];
        
        // Include log file path if available
        if (isset($log_result['log_file'])) {
            $response['log_file'] = $log_result['log_file'];
        }
        
        // Include error message if available
        if (isset($log_result['message'])) {
            $response['message'] = $log_result['message'];
        }
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Clean up manufacturers with no associated products via CRON
     */
    public function cleanupManufacturersCron() {
        // Optional IP security check
        // if (!in_array($this->request->server['REMOTE_ADDR'], ['127.0.0.1', 'your_server_ip'])) {
        //     exit('Access denied');
        // }
        
        $this->load->model('extension/module/import_1c');
        
        // Clean up manufacturers
        $cleanup_result = $this->model_extension_module_import_1c->cleanupManufacturers();
        
        // Prepare the response
        $response = [
            'manufacturers_removed' => $cleanup_result['removed'],
            'errors' => $cleanup_result['errors']
        ];
        
        // Include error message if available
        if (isset($cleanup_result['message'])) {
            $response['message'] = $cleanup_result['message'];
        }
        
        // Include removed manufacturers list if available
        if (isset($cleanup_result['removed_list']) && !empty($cleanup_result['removed_list'])) {
            $response['removed_list'] = $cleanup_result['removed_list'];
        }
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Clean up categories with no associated products via CRON
     */
    public function cleanupCategoriesCron() {
        // Optional IP security check
        // if (!in_array($this->request->server['REMOTE_ADDR'], ['127.0.0.1', 'your_server_ip'])) {
        //     exit('Access denied');
        // }
        
        $this->load->model('extension/module/import_1c');
        
        // Clean up categories
        $cleanup_result = $this->model_extension_module_import_1c->cleanupCategories();
        
        // Prepare the response
        $response = [
            'categories_removed' => $cleanup_result['removed'],
            'errors' => $cleanup_result['errors']
        ];
        
        // Include error message if available
        if (isset($cleanup_result['message'])) {
            $response['message'] = $cleanup_result['message'];
        }
        
        // Include removed categories list if available
        if (isset($cleanup_result['removed_list']) && !empty($cleanup_result['removed_list'])) {
            $response['removed_list'] = $cleanup_result['removed_list'];
        }
        
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

