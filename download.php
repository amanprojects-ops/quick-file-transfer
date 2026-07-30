<?php
$upload_dir = __DIR__ . '/uploads/';
$metadata_dir = __DIR__ . '/metadata/';

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Basic validation to ensure $id is just an alphanumeric hash
    if (!preg_match('/^[a-f0-9]{32}$/i', $id)) {
        die('Invalid file ID.');
    }
    
    $file_path = $upload_dir . $id;
    $metadata_path = $metadata_dir . $id . '.json';
    
    if (file_exists($metadata_path)) {
        $metadata = json_decode(file_get_contents($metadata_path), true);
        
        if ($metadata && isset($metadata['file_hash']) && isset($metadata['original_name'])) {
            $file_hash = $metadata['file_hash'];
            $file_path = $upload_dir . $file_hash;
            
            if (!file_exists($file_path)) {
                die('File content not found.');
            }

            $original_name = $metadata['original_name'];
            $mime_type = isset($metadata['mime_type']) ? $metadata['mime_type'] : 'application/octet-stream';
            $size = isset($metadata['size']) ? $metadata['size'] : filesize($file_path);
            
            // Clean the output buffer
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Set headers for download
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $mime_type);
            header('Content-Disposition: attachment; filename="' . basename($original_name) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . $size);
            
            // Output file
            readfile($file_path);
            exit;
        } else {
            die('Error reading file metadata or invalid format.');
        }
    } else {
        die('File not found or has been deleted.');
    }
} else {
    die('No file ID provided.');
}
?>
