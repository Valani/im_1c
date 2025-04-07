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
            'categories_processed' => isset($products_result['categories_processed']) ? $products_result['categories_processed'] : 0,
            'categories_created' => isset($products_result['categories_created']) ? $products_result['categories_created'] : 0,
            'orders_exported' => isset($orders_result['exported']) ? $orders_result['exported'] : 0,
            'unused_images_found' => isset($unused_images_result['found']) ? $unused_images_result['found'] : 0,
            'errors' => $prices_result['errors'] + $quantities_result['errors'] + $products_result['errors'] + $images_result['errors'] + $users_result['errors'] + 
                    (isset($orders_result['errors']) ? $orders_result['errors'] : 0) +
                    (isset($unused_images_result['errors']) ? $unused_images_result['errors'] : 0)
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
}

