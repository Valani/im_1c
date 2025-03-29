<?php
/**
 * XML File Encoding Converter
 * 
 * This script converts Windows-1251 encoded XML files to UTF-8
 * Can be run via cron job
 */

class ControllerExtensionModuleConvertEncoding extends Controller {
    // Constants
    const SOURCE_FILE = '/home/cr548725/feniks-lviv.com.ua/transfer/users.xml';
    const TARGET_FILE = '/home/cr548725/feniks-lviv.com.ua/transfer/users_utf.xml';
    const LOG_FILE = '/home/cr548725/feniks-lviv.com.ua/transfer/encoding_conversion.log';
    
    /**
     * Log message to file
     */
    private function logMessage($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}" . PHP_EOL;
        file_put_contents(self::LOG_FILE, $log_message, FILE_APPEND);
    }
    
    /**
     * Main method to run via cron
     */
    public function cron() {
        try {
            // Initialize response data
            $response = [
                'success' => false,
                'message' => '',
                'details' => []
            ];
            
            // Check if source file exists
            if (!file_exists(self::SOURCE_FILE)) {
                throw new Exception("Source file not found: " . self::SOURCE_FILE);
            }
            
            // Read source file
            $this->logMessage("Reading source file: " . self::SOURCE_FILE);
            $xml_content = file_get_contents(self::SOURCE_FILE);
            if ($xml_content === false) {
                throw new Exception("Failed to read source file");
            }
            
            $response['details'][] = "Source file size: " . strlen($xml_content) . " bytes";
            
            // Check current encoding
            $is_utf8 = mb_check_encoding($xml_content, 'UTF-8');
            if ($is_utf8) {
                $this->logMessage("File is already in UTF-8 encoding");
                $response['details'][] = "File is already in UTF-8 encoding";
            } else {
                $this->logMessage("Converting encoding from Windows-1251 to UTF-8");
                $response['details'][] = "Converting encoding from Windows-1251 to UTF-8";
                
                // Convert encoding using mb_convert_encoding (primary method)
                $converted_content = mb_convert_encoding($xml_content, 'UTF-8', 'Windows-1251');
                
                // Validate conversion
                if ($converted_content === false || !mb_check_encoding($converted_content, 'UTF-8')) {
                    $this->logMessage("Primary conversion method failed, trying iconv");
                    $response['details'][] = "Primary conversion method failed, trying iconv";
                    
                    // Try alternative conversion method with iconv
                    $converted_content = iconv('Windows-1251', 'UTF-8//TRANSLIT', $xml_content);
                    
                    if ($converted_content === false) {
                        throw new Exception("Failed to convert file encoding");
                    }
                }
                
                // Ensure XML declaration is UTF-8
                if (preg_match('/<\?xml[^>]+encoding=["\'][^"\']+["\']/i', $converted_content)) {
                    // Replace existing encoding declaration
                    $converted_content = preg_replace(
                        '/<\?xml[^>]+encoding=["\'][^"\']+["\']/i',
                        '<?xml version="1.0" encoding="UTF-8"',
                        $converted_content
                    );
                } else if (strpos($converted_content, '<?xml') === false) {
                    // Add XML declaration if missing
                    $converted_content = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL . $converted_content;
                }
                
                $xml_content = $converted_content;
            }
            
            // Validate converted content as well-formed XML
            $this->logMessage("Validating XML structure");
            $response['details'][] = "Validating XML structure";
            
            // Suppress XML parsing errors to catch them programmatically
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xml_content);
            
            if ($xml === false) {
                $xml_errors = libxml_get_errors();
                libxml_clear_errors();
                
                $error_messages = [];
                foreach ($xml_errors as $error) {
                    $error_messages[] = "Line {$error->line}: {$error->message}";
                }
                
                $this->logMessage("XML validation failed: " . implode(', ', $error_messages));
                $response['details'][] = "XML validation failed";
                $response['details'] = array_merge($response['details'], $error_messages);
                
                // Try to fix common XML issues
                $this->logMessage("Attempting to fix XML structure issues");
                $response['details'][] = "Attempting to fix XML structure issues";
                
                // Replace invalid characters
                $xml_content = preg_replace('/[^\x{0009}\x{000a}\x{000d}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}]+/u', '', $xml_content);
                
                // Check if XML is now valid
                $xml = simplexml_load_string($xml_content);
                if ($xml === false) {
                    throw new Exception("Failed to fix XML structure issues");
                }
                
                $this->logMessage("XML structure fixed successfully");
                $response['details'][] = "XML structure fixed successfully";
            }
            
            // Write to target file
            $this->logMessage("Writing converted file to: " . self::TARGET_FILE);
            $response['details'][] = "Writing converted file to: " . self::TARGET_FILE;
            
            $result = file_put_contents(self::TARGET_FILE, $xml_content);
            if ($result === false) {
                throw new Exception("Failed to write target file");
            }
            
            $response['details'][] = "Target file size: " . $result . " bytes";
            
            // Success!
            $response['success'] = true;
            $response['message'] = "Successfully converted XML file encoding";
            $this->logMessage("Successfully converted XML file encoding");
            
        } catch (Exception $e) {
            $error_message = "Error: " . $e->getMessage();
            $this->logMessage($error_message);
            $response['success'] = false;
            $response['message'] = $error_message;
        }
        
        // Output response as JSON
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($response));
    }
}