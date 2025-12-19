<?php
/**
 * Schedule Parser Helper Class
 *
 * Parses manufacturer shipping schedules from Excel/CSV files
 * with flexible column mapping support.
 */

class ScheduleParser {

    private $filePath;
    private $fileType;
    private $headers = [];
    private $data = [];
    private $columnMapping = [];
    private $errors = [];
    private $warnings = [];

    // System fields that can be mapped
    public static $systemFields = [
        'bol_number' => [
            'label' => 'BOL Number',
            'required' => true,
            'type' => 'string',
            'common_names' => ['BOL', 'BOL #', 'BOL Number', 'Bill of Lading', 'B/L']
        ],
        'container_number' => [
            'label' => 'Container Number',
            'required' => false,
            'type' => 'string',
            'common_names' => ['Container', 'Container #', 'Container Number', 'CNTR', 'Container No']
        ],
        'pallet_id' => [
            'label' => 'Pallet ID',
            'required' => true,
            'type' => 'string',
            'common_names' => ['Pallet', 'Pallet #', 'Pallet ID', 'Pallet Number', 'Pallet No', 'Serial', 'Serial #']
        ],
        'wattage' => [
            'label' => 'Wattage',
            'required' => true,
            'type' => 'integer',
            'common_names' => ['Wattage', 'Watts', 'W', 'Power', 'Module Wattage', 'Wp']
        ],
        'quantity' => [
            'label' => 'Quantity',
            'required' => true,
            'type' => 'integer',
            'common_names' => ['Quantity', 'Qty', 'Count', 'Modules', 'Module Count', 'Pieces', 'PCS']
        ],
        'estimated_delivery' => [
            'label' => 'Estimated Delivery Date',
            'required' => false,
            'type' => 'date',
            'common_names' => ['Est Delivery', 'Est. Delivery', 'ETA', 'Expected Delivery', 'Estimated Arrival', 'Est Del']
        ],
        'actual_delivery' => [
            'label' => 'Actual Delivery Date',
            'required' => false,
            'type' => 'date',
            'common_names' => ['Actual Delivery', 'Delivered', 'Delivery Date', 'Actual Del', 'Del Date']
        ],
        'status' => [
            'label' => 'Status',
            'required' => true,
            'type' => 'status',
            'common_names' => ['Status', 'Delivery Status', 'Ship Status', 'State']
        ]
    ];

    // Valid status values for mapping
    public static $validStatuses = [
        'At Manufacturer',
        'On Water',
        'Cleared Customs',
        'In Transit to Warehouse',
        'Delivered to Warehouse',
        'In Warehouse',
        'In Transit to Project',
        'Delivered to Project',
        'Pending',
        'Canceled'
    ];

    /**
     * Constructor
     * @param string $filePath Path to the uploaded file
     */
    public function __construct($filePath) {
        $this->filePath = $filePath;
        $this->fileType = $this->detectFileType($filePath);
    }

    /**
     * Detect file type based on extension
     */
    private function detectFileType($filePath) {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'csv':
                return 'csv';
            case 'xlsx':
                return 'xlsx';
            case 'xls':
                return 'xls';
            default:
                $this->errors[] = "Unsupported file type: $extension";
                return null;
        }
    }

    /**
     * Parse the file and extract headers
     * @return array Headers found in the file
     */
    public function parseHeaders() {
        if (!$this->fileType) {
            return [];
        }

        try {
            if ($this->fileType === 'csv') {
                $this->headers = $this->parseCSVHeaders();
            } else {
                $this->headers = $this->parseExcelHeaders();
            }
        } catch (Exception $e) {
            $this->errors[] = "Error parsing headers: " . $e->getMessage();
            return [];
        }

        return $this->headers;
    }

    /**
     * Parse CSV headers
     */
    private function parseCSVHeaders() {
        $handle = fopen($this->filePath, 'r');
        if ($handle === false) {
            throw new Exception("Could not open CSV file");
        }

        // Try to detect delimiter
        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = $this->detectDelimiter($firstLine);

        $headers = fgetcsv($handle, 0, $delimiter);
        fclose($handle);

        if ($headers === false) {
            throw new Exception("Could not read headers from CSV");
        }

        // Clean headers (trim whitespace, remove BOM)
        $headers = array_map(function($h) {
            $h = trim($h);
            // Remove BOM if present
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            return $h;
        }, $headers);

        return array_filter($headers, function($h) { return $h !== ''; });
    }

    /**
     * Parse Excel headers (requires PhpSpreadsheet)
     */
    private function parseExcelHeaders() {
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            // Try to load via composer autoload
            $autoloadPath = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
        }

        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new Exception("PhpSpreadsheet library not installed. Please run: composer require phpoffice/phpspreadsheet");
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $value = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
            if ($value !== null && $value !== '') {
                $headers[] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * Detect CSV delimiter
     */
    private function detectDelimiter($line) {
        $delimiters = [',', ';', "\t", '|'];
        $counts = [];

        foreach ($delimiters as $d) {
            $counts[$d] = substr_count($line, $d);
        }

        return array_search(max($counts), $counts);
    }

    /**
     * Auto-suggest column mappings based on header names
     * @return array Suggested mappings
     */
    public function suggestMappings() {
        $suggestions = [];

        foreach (self::$systemFields as $fieldKey => $fieldConfig) {
            $suggestions[$fieldKey] = null;

            foreach ($this->headers as $header) {
                $headerLower = strtolower(trim($header));

                foreach ($fieldConfig['common_names'] as $commonName) {
                    if (strtolower($commonName) === $headerLower) {
                        $suggestions[$fieldKey] = $header;
                        break 2;
                    }
                }

                // Fuzzy matching - check if common name is contained in header
                if ($suggestions[$fieldKey] === null) {
                    foreach ($fieldConfig['common_names'] as $commonName) {
                        if (stripos($header, $commonName) !== false) {
                            $suggestions[$fieldKey] = $header;
                            break 2;
                        }
                    }
                }
            }
        }

        return $suggestions;
    }

    /**
     * Set column mapping
     * @param array $mapping [system_field => user_column_name]
     */
    public function setColumnMapping($mapping) {
        $this->columnMapping = $mapping;
    }

    /**
     * Validate the column mapping
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateMapping() {
        $errors = [];

        foreach (self::$systemFields as $fieldKey => $fieldConfig) {
            if ($fieldConfig['required']) {
                if (empty($this->columnMapping[$fieldKey])) {
                    $errors[] = "Required field '{$fieldConfig['label']}' is not mapped";
                } elseif (!in_array($this->columnMapping[$fieldKey], $this->headers)) {
                    $errors[] = "Mapped column '{$this->columnMapping[$fieldKey]}' for '{$fieldConfig['label']}' not found in file headers";
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Parse all data from the file
     * @return array Parsed data rows
     */
    public function parseData() {
        if (!$this->fileType) {
            return [];
        }

        $validation = $this->validateMapping();
        if (!$validation['valid']) {
            $this->errors = array_merge($this->errors, $validation['errors']);
            return [];
        }

        try {
            if ($this->fileType === 'csv') {
                $this->data = $this->parseCSVData();
            } else {
                $this->data = $this->parseExcelData();
            }
        } catch (Exception $e) {
            $this->errors[] = "Error parsing data: " . $e->getMessage();
            return [];
        }

        return $this->data;
    }

    /**
     * Parse CSV data
     */
    private function parseCSVData() {
        $handle = fopen($this->filePath, 'r');
        if ($handle === false) {
            throw new Exception("Could not open CSV file");
        }

        $firstLine = fgets($handle);
        rewind($handle);
        $delimiter = $this->detectDelimiter($firstLine);

        // Skip header row
        $headers = fgetcsv($handle, 0, $delimiter);

        // Clean headers for comparison
        $headers = array_map(function($h) {
            $h = trim($h);
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            return $h;
        }, $headers);

        $data = [];
        $rowNum = 1; // Start at 1 (header is row 0)

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;

            // Skip empty rows
            if (count(array_filter($row)) === 0) {
                continue;
            }

            // Map columns to values
            $rowData = $this->mapRowToFields($headers, $row, $rowNum);

            if ($rowData !== null) {
                $rowData['_row_number'] = $rowNum;
                $data[] = $rowData;
            }
        }

        fclose($handle);
        return $data;
    }

    /**
     * Parse Excel data
     */
    private function parseExcelData() {
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            $autoloadPath = __DIR__ . '/../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
        }

        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new Exception("PhpSpreadsheet library not installed");
        }

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($this->filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        // Get headers from first row
        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $value = $worksheet->getCellByColumnAndRow($col, 1)->getValue();
            $headers[] = $value !== null ? trim($value) : '';
        }

        $data = [];

        // Process data rows (starting from row 2)
        for ($rowNum = 2; $rowNum <= $highestRow; $rowNum++) {
            $row = [];
            $hasData = false;

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $worksheet->getCellByColumnAndRow($col, $rowNum);
                $value = $cell->getValue();

                // Handle date cells
                if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
                    $value = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
                }

                $row[] = $value;
                if ($value !== null && $value !== '') {
                    $hasData = true;
                }
            }

            // Skip empty rows
            if (!$hasData) {
                continue;
            }

            // Map columns to fields
            $rowData = $this->mapRowToFields($headers, $row, $rowNum);

            if ($rowData !== null) {
                $rowData['_row_number'] = $rowNum;
                $data[] = $rowData;
            }
        }

        return $data;
    }

    /**
     * Map a row of data to system fields based on column mapping
     */
    private function mapRowToFields($headers, $row, $rowNum) {
        $mapped = [];
        $hasRequiredData = false;

        // Create header => index lookup
        $headerIndex = [];
        foreach ($headers as $idx => $header) {
            $headerIndex[$header] = $idx;
        }

        foreach ($this->columnMapping as $fieldKey => $columnName) {
            if (empty($columnName) || !isset($headerIndex[$columnName])) {
                $mapped[$fieldKey] = null;
                continue;
            }

            $colIndex = $headerIndex[$columnName];
            $value = isset($row[$colIndex]) ? $row[$colIndex] : null;

            // Clean and convert value based on field type
            $fieldConfig = self::$systemFields[$fieldKey] ?? null;

            if ($fieldConfig) {
                $value = $this->convertValue($value, $fieldConfig['type'], $fieldKey, $rowNum);
            }

            $mapped[$fieldKey] = $value;

            if ($fieldConfig && $fieldConfig['required'] && $value !== null && $value !== '') {
                $hasRequiredData = true;
            }
        }

        // Validate required fields
        $missingRequired = [];
        foreach (self::$systemFields as $fieldKey => $fieldConfig) {
            if ($fieldConfig['required']) {
                if (!isset($mapped[$fieldKey]) || $mapped[$fieldKey] === null || $mapped[$fieldKey] === '') {
                    $missingRequired[] = $fieldConfig['label'];
                }
            }
        }

        if (!empty($missingRequired) && $hasRequiredData) {
            $this->warnings[] = [
                'row' => $rowNum,
                'message' => "Missing required fields: " . implode(', ', $missingRequired)
            ];
        }

        // Skip row if no required data
        if (!$hasRequiredData) {
            return null;
        }

        return $mapped;
    }

    /**
     * Convert value to expected type
     */
    private function convertValue($value, $type, $fieldKey, $rowNum) {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);

        switch ($type) {
            case 'integer':
                // Remove any non-numeric characters except digits
                $cleaned = preg_replace('/[^0-9]/', '', $value);
                if ($cleaned === '') {
                    $this->warnings[] = [
                        'row' => $rowNum,
                        'message' => "Invalid integer value for $fieldKey: $value"
                    ];
                    return null;
                }
                return (int)$cleaned;

            case 'date':
                return $this->parseDate($value, $rowNum, $fieldKey);

            case 'status':
                return $this->normalizeStatus($value, $rowNum);

            case 'string':
            default:
                return $value;
        }
    }

    /**
     * Parse date value in various formats
     */
    private function parseDate($value, $rowNum, $fieldKey) {
        if (empty($value)) {
            return null;
        }

        // If it's already a date object or formatted date
        if ($value instanceof DateTime) {
            return $value->format('Y-m-d');
        }

        // Try various date formats
        $formats = [
            'Y-m-d',
            'm/d/Y',
            'm/d/y',
            'd/m/Y',
            'd/m/y',
            'Y/m/d',
            'm-d-Y',
            'd-m-Y',
            'M d, Y',
            'M d Y',
            'd M Y',
            'F d, Y',
        ];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        // Try strtotime as last resort
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        $this->warnings[] = [
            'row' => $rowNum,
            'message' => "Could not parse date for $fieldKey: $value"
        ];

        return null;
    }

    /**
     * Normalize status value to match system statuses
     */
    private function normalizeStatus($value, $rowNum) {
        $value = trim($value);
        $valueLower = strtolower($value);

        // Direct match
        foreach (self::$validStatuses as $status) {
            if (strtolower($status) === $valueLower) {
                return $status;
            }
        }

        // Common variations
        $statusMap = [
            'at mfg' => 'At Manufacturer',
            'at manufacturer' => 'At Manufacturer',
            'manufacturer' => 'At Manufacturer',
            'on water' => 'On Water',
            'in transit' => 'In Transit to Warehouse',
            'in transit to wh' => 'In Transit to Warehouse',
            'in transit to warehouse' => 'In Transit to Warehouse',
            'transit to warehouse' => 'In Transit to Warehouse',
            'in warehouse' => 'In Warehouse',
            'at warehouse' => 'In Warehouse',
            'warehouse' => 'In Warehouse',
            'delivered to warehouse' => 'Delivered to Warehouse',
            'delivered wh' => 'Delivered to Warehouse',
            'cleared customs' => 'Cleared Customs',
            'customs cleared' => 'Cleared Customs',
            'customs' => 'Cleared Customs',
            'in transit to project' => 'In Transit to Project',
            'in transit to site' => 'In Transit to Project',
            'transit to project' => 'In Transit to Project',
            'delivered to project' => 'Delivered to Project',
            'delivered to site' => 'Delivered to Project',
            'delivered' => 'Delivered to Project',
            'pending' => 'Pending',
            'canceled' => 'Canceled',
            'cancelled' => 'Canceled',
        ];

        if (isset($statusMap[$valueLower])) {
            return $statusMap[$valueLower];
        }

        // Partial match
        foreach ($statusMap as $key => $mapped) {
            if (stripos($valueLower, $key) !== false) {
                return $mapped;
            }
        }

        $this->warnings[] = [
            'row' => $rowNum,
            'message' => "Unknown status value: '$value'. Will use 'Pending' as default."
        ];

        return 'Pending';
    }

    /**
     * Get errors
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Get warnings
     */
    public function getWarnings() {
        return $this->warnings;
    }

    /**
     * Get headers
     */
    public function getHeaders() {
        return $this->headers;
    }

    /**
     * Get parsed data
     */
    public function getData() {
        return $this->data;
    }

    /**
     * Check if PhpSpreadsheet is available
     */
    public static function isExcelSupported() {
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
        }
        return class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
    }

    /**
     * Get summary of parsed data
     */
    public function getSummary() {
        $summary = [
            'total_rows' => count($this->data),
            'unique_bols' => [],
            'unique_pallets' => [],
            'wattages' => [],
            'statuses' => [],
            'total_quantity' => 0
        ];

        foreach ($this->data as $row) {
            if (!empty($row['bol_number'])) {
                $summary['unique_bols'][$row['bol_number']] = true;
            }
            if (!empty($row['pallet_id'])) {
                $summary['unique_pallets'][$row['pallet_id']] = true;
            }
            if (!empty($row['wattage'])) {
                $w = $row['wattage'];
                $summary['wattages'][$w] = ($summary['wattages'][$w] ?? 0) + 1;
            }
            if (!empty($row['status'])) {
                $s = $row['status'];
                $summary['statuses'][$s] = ($summary['statuses'][$s] ?? 0) + 1;
            }
            if (!empty($row['quantity'])) {
                $summary['total_quantity'] += $row['quantity'];
            }
        }

        $summary['unique_bols'] = count($summary['unique_bols']);
        $summary['unique_pallets'] = count($summary['unique_pallets']);

        return $summary;
    }
}
