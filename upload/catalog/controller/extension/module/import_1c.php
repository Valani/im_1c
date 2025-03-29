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
        
        // Запуск імпорту користувачів (після конвертування)
        $users_result = $this->model_extension_module_import_1c->importUsers();
        
        // Виведення результатів
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode([
            'prices_updated' => $prices_result['updated'],
            'quantities_updated' => $quantities_result['updated'],
            'products_created' => $products_result['created'],
            'images_updated' => $images_result['updated'],
            'images_skipped' => $images_result['skipped'],
            'users_created' => $users_result['created'],
            'users_updated' => $users_result['updated'],
            'users_deleted' => $users_result['deleted'],
            'users_skipped' => $users_result['skipped'],
            'errors' => $prices_result['errors'] + $quantities_result['errors'] + $products_result['errors'] + $images_result['errors'] + $users_result['errors']
        ]));
    }
}

