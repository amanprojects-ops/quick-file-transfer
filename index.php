<?php
$upload_dir = __DIR__ . '/uploads/';
$metadata_dir = __DIR__ . '/metadata/';
$max_file_size = 1 * 1024 * 1024 * 1024;  // 1 GB
$blocked_extensions = ['php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'sh', 'bat', 'cmd', 'pl', 'cgi', 'htaccess'];

$message = '';
$message_type = '';
$download_link = '';
$file_details = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['share_id'])) {
    $share_id_to_delete = preg_replace('/[^a-f0-9]/i', '', $_POST['share_id']);
    $meta_file_path = $metadata_dir . $share_id_to_delete . '.json';
    
    if (file_exists($meta_file_path)) {
        $meta_data = json_decode(file_get_contents($meta_file_path), true);
        if ($meta_data && isset($meta_data['file_hash'])) {
            $file_hash_to_delete = $meta_data['file_hash'];
            
            // Delete metadata file first
            unlink($meta_file_path);
            
            // Check if any other metadata files use this hash
            $hash_in_use = false;
            $all_meta_files = scandir($metadata_dir);
            foreach ($all_meta_files as $f) {
                if ($f !== '.' && $f !== '..' && pathinfo($f, PATHINFO_EXTENSION) === 'json') {
                    $other_meta = json_decode(file_get_contents($metadata_dir . $f), true);
                    if ($other_meta && isset($other_meta['file_hash']) && $other_meta['file_hash'] === $file_hash_to_delete) {
                        $hash_in_use = true;
                        break;
                    }
                }
            }
            
            // If hash is not used by any other share_id, delete the physical file
            if (!$hash_in_use) {
                $physical_file_path = $upload_dir . $file_hash_to_delete;
                if (file_exists($physical_file_path)) {
                    unlink($physical_file_path);
                }
            }
            
            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => 'File deleted successfully.']);
                exit;
            }
        }
    }
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'File not found or already deleted.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fileToUpload'])) {
    $file = $_FILES['fileToUpload'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $original_name = basename($file['name']);
        $file_size = $file['size'];
        $tmp_name = $file['tmp_name'];
        $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        if ($file_size > $max_file_size) {
            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'File is too large. Maximum size is 1GB.']);
                exit;
            }
            $message = 'File is too large. Maximum size is 1GB.';
            $message_type = 'error';
        } elseif (in_array($file_ext, $blocked_extensions)) {
            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'File type not allowed for security reasons.']);
                exit;
            }
            $message = 'File type not allowed for security reasons.';
            $message_type = 'error';
        } else {
            // Generate a secure hash for the file content to deduplicate
            $file_hash = hash_file('sha256', $tmp_name);
            $target_file = $upload_dir . $file_hash;

            // Generate a unique ID for the share link
            $share_id = bin2hex(random_bytes(16));
            $metadata_file = $metadata_dir . $share_id . '.json';

            // Move only if the file doesn't already exist (deduplication)
            $upload_success = true;
            if (!file_exists($target_file)) {
                $upload_success = move_uploaded_file($tmp_name, $target_file);
            }

            if ($upload_success) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
                $domainName = $_SERVER['HTTP_HOST'];
                // Get the base URL correctly
                $base_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
                $base_url = rtrim($protocol . $domainName . $base_dir, '/') . '/';

                $download_link = $base_url . 'download.php?id=' . $share_id;

                // Save metadata
                $mime_type = mime_content_type($target_file) ?: 'application/octet-stream';
                $metadata = [
                    'file_hash' => $file_hash,
                    'original_name' => $original_name,
                    'mime_type' => $mime_type,
                    'size' => $file_size,
                    'upload_date' => date('c'),
                    'share_id' => $share_id,
                    'download_link' => $download_link
                ];
                file_put_contents($metadata_file, json_encode($metadata));

                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'success', 'share_id' => $share_id]);
                    exit;
                }

                $message = 'File uploaded securely!';
                $message_type = 'success';
                $file_details = $metadata;
            } else {
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'There was an error saving your file.']);
                    exit;
                }
                $message = 'There was an error saving your file.';
                $message_type = 'error';
            }
        }
    } else {
        if (isset($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Error during upload. Please try again.']);
            exit;
        }
        $message = 'Error during upload. Please try again.';
        $message_type = 'error';
    }
} elseif (isset($_GET['success'])) {
    $share_id = preg_replace('/[^a-f0-9]/i', '', $_GET['success']);
    $metadata_path = $metadata_dir . $share_id . '.json';
    if (file_exists($metadata_path)) {
        $file_details = json_decode(file_get_contents($metadata_path), true);
        if ($file_details) {
            $message = 'File uploaded securely!';
            $message_type = 'success';
            $download_link = $file_details['download_link'];
        }
    }
}

function formatBytes($bytes, $precision = 2)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
}

$uploaded_files = [];
if (is_dir($metadata_dir)) {
    $files = scandir($metadata_dir);
    foreach ($files as $f) {
        if ($f !== '.' && $f !== '..' && pathinfo($f, PATHINFO_EXTENSION) === 'json') {
            $meta = json_decode(file_get_contents($metadata_dir . $f), true);
            if ($meta && isset($meta['original_name'])) {
                // Ensure download_link exists for older files
                if (!isset($meta['download_link'])) {
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
                    $domainName = $_SERVER['HTTP_HOST'];
                    $base_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
                    $base_url = rtrim($protocol . $domainName . $base_dir, '/') . '/';
                    $share_id = basename($f, '.json');
                    $meta['download_link'] = $base_url . 'download.php?id=' . $share_id;
                }
                $uploaded_files[] = $meta;
            }
        }
    }
    // Sort by upload date, newest first
    usort($uploaded_files, function ($a, $b) {
        return strtotime($b['upload_date']) < strtotime($a['upload_date']) ? 1 : -1;
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SecureShare | Encrypted File Transfer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a;
            --surface-color: rgba(30, 41, 59, 0.7);
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: rgba(255, 255, 255, 0.1);
            --success-color: #10b981;
            --error-color: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-image: 
                radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
                radial-gradient(at 50% 0%, hsla(225,39%,30%,0.2) 0, transparent 50%), 
                radial-gradient(at 100% 0%, hsla(339,49%,30%,0.2) 0, transparent 50%);
            padding: 2rem;
        }

        .container {
            width: 100%;
            max-width: 600px;
            z-index: 10;
        }

        .header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(to right, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--text-muted);
            font-size: 1.1rem;
        }

        .card {
            background: var(--surface-color);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .upload-area {
            border: 2px dashed var(--border-color);
            border-radius: 16px;
            padding: 3rem 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            background: rgba(255, 255, 255, 0.02);
        }

        .upload-area:hover, .upload-area.dragover {
            border-color: var(--primary-color);
            background: rgba(59, 130, 246, 0.05);
        }

        .upload-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1rem;
            color: var(--primary-color);
            opacity: 0.8;
            transition: transform 0.3s ease;
        }

        .upload-area:hover .upload-icon {
            transform: translateY(-5px);
        }

        .upload-text {
            font-size: 1.2rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .upload-subtext {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .btn {
            display: inline-block;
            width: 100%;
            padding: 1rem;
            margin-top: 1.5rem;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.4);
        }

        .btn:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.4);
        }

        .btn:disabled {
            background: #475569;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .message {
            margin-top: 1.5rem;
            padding: 1rem;
            border-radius: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: fadeIn 0.5s ease;
        }

        .message.success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .message.error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .result-card {
            margin-top: 2rem;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            animation: slideUp 0.6s ease cubic-bezier(0.16, 1, 0.3, 1);
        }

        .file-info {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            gap: 1rem;
        }

        .file-icon {
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 12px;
        }

        .file-details h3 {
            font-size: 1.1rem;
            margin-bottom: 0.2rem;
            word-break: break-all;
        }

        .file-details p {
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .link-container {
            display: flex;
            gap: 0.5rem;
        }

        .link-input {
            flex: 1;
            padding: 0.8rem 1rem;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-main);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.3s ease;
        }

        .link-input:focus {
            border-color: var(--primary-color);
        }

        .copy-btn {
            padding: 0.8rem 1.5rem;
            background: var(--surface-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-main);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .copy-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        #selected-file-name {
            display: block;
            margin-top: 1rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .files-list {
            margin-top: 2.5rem;
            border-top: 1px solid var(--border-color);
            padding-top: 1.5rem;
        }

        .list-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-main);
        }

        .list-item {
            background: rgba(0, 0, 0, 0.15);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 0.8rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s ease;
        }

        .list-item:hover {
            background: rgba(0, 0, 0, 0.25);
            border-color: rgba(255, 255, 255, 0.2);
        }

        .list-item-details h4 {
            font-size: 1rem;
            margin-bottom: 0.2rem;
            word-break: break-all;
        }

        .list-item-details p {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .list-item-btn {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary-color);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s ease;
            white-space: nowrap;
            margin-left: 1rem;
        }

        .list-item-btn:hover {
            background: var(--primary-color);
            color: white;
        }

        .list-item-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .delete-btn {
            background: rgba(239, 68, 68, 0.1);
            color: var(--error-color);
            border: none;
            cursor: pointer;
            margin-left: 0;
        }

        .delete-btn:hover {
            background: var(--error-color);
            color: white;
        }

        .progress-container {
            margin-top: 1.5rem;
            display: none;
        }

        .progress-bar-bg {
            background: rgba(0, 0, 0, 0.3);
            border-radius: 8px;
            height: 12px;
            width: 100%;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .progress-bar-fill {
            background: var(--primary-color);
            height: 100%;
            width: 0%;
            border-radius: 8px;
            transition: width 0.2s ease, background 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.5);
        }

        .progress-text {
            text-align: right;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>SecureShare</h1>
            <p>Upload, encrypt, and share files safely without databases.</p>
        </div>

        <div class="card">
            <form action="" method="POST" enctype="multipart/form-data" id="upload-form">
                <div class="upload-area" id="drop-zone">
                    <svg class="upload-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    <div class="upload-text">Drag & drop your file here</div>
                    <div class="upload-subtext">or click to browse (Max 1GB)</div>
                    <input type="file" name="fileToUpload" id="fileToUpload" required>
                    <span id="selected-file-name"></span>
                </div>
                
                <button type="submit" class="btn" id="submit-btn" disabled>Upload File</button>

                <div class="progress-container" id="progress-container">
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" id="progress-bar-fill"></div>
                    </div>
                    <div class="progress-text" id="progress-text">0%</div>
                </div>
            </form>

            <?php if ($message): ?>
                <div class="message <?php echo $message_type; ?>">
                    <?php if ($message_type === 'success'): ?>
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    <?php else: ?>
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($download_link && $file_details): ?>
                <div class="result-card">
                    <div class="file-info">
                        <div class="file-icon">
                            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        </div>
                        <div class="file-details">
                            <h3><?php echo htmlspecialchars($file_details['original_name']); ?></h3>
                            <p><?php echo formatBytes($file_details['size']); ?> • Uploaded just now</p>
                        </div>
                    </div>
                    
                    <p style="margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-muted);">Your shareable link:</p>
                    <div class="link-container">
                        <input type="text" class="link-input" id="share-link" value="<?php echo htmlspecialchars($download_link); ?>" readonly>
                        <button class="copy-btn" onclick="copyLink()">Copy</button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($uploaded_files)): ?>
            <div class="files-list">
                <h3 class="list-title">Recently Uploaded Files</h3>
                <?php foreach ($uploaded_files as $uf): ?>
                    <div class="list-item">
                        <div class="list-item-details">
                            <h4><?php echo htmlspecialchars($uf['original_name']); ?></h4>
                            <p><?php echo formatBytes($uf['size']); ?> • <?php echo date('M j, Y H:i', strtotime($uf['upload_date'])); ?></p>
                        </div>
                        <div class="list-item-actions">
                            <a href="<?php echo htmlspecialchars($uf['download_link']); ?>" class="list-item-btn" target="_blank">Download</a>
                            <button class="list-item-btn delete-btn" data-id="<?php echo htmlspecialchars($uf['share_id']); ?>" onclick="deleteFile(this)">Delete</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('fileToUpload');
        const submitBtn = document.getElementById('submit-btn');
        const fileNameDisplay = document.getElementById('selected-file-name');

        // Handle drag and drop visuals
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults (e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
        });

        // Handle file selection
        fileInput.addEventListener('change', function(e) {
            handleFiles(this.files);
        });

        dropZone.addEventListener('drop', function(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files; // Assign files to the input
            handleFiles(files);
        });

        function handleFiles(files) {
            if (files.length > 0) {
                const file = files[0];
                fileNameDisplay.textContent = file.name;
                submitBtn.disabled = false;
                submitBtn.textContent = 'Upload ' + file.name;
            } else {
                fileNameDisplay.textContent = '';
                submitBtn.disabled = true;
                submitBtn.textContent = 'Upload File';
            }
        }

        function copyLink() {
            const linkInput = document.getElementById('share-link');
            linkInput.select();
            linkInput.setSelectionRange(0, 99999); /* For mobile devices */
            
            try {
                document.execCommand('copy');
                const btn = document.querySelector('.copy-btn');
                const originalText = btn.textContent;
                btn.textContent = 'Copied!';
                btn.style.background = 'rgba(16, 185, 129, 0.2)';
                btn.style.color = 'var(--success-color)';
                btn.style.borderColor = 'rgba(16, 185, 129, 0.3)';
                
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.background = '';
                    btn.style.color = '';
                    btn.style.borderColor = '';
                }, 2000);
            } catch (err) {
                console.error('Failed to copy', err);
            }
        }

        // AJAX Upload with Progress Bar
        const uploadForm = document.getElementById('upload-form');
        const progressContainer = document.getElementById('progress-container');
        const progressBarFill = document.getElementById('progress-bar-fill');
        const progressText = document.getElementById('progress-text');
        
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (fileInput.files.length === 0) return;
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';
            progressContainer.style.display = 'block';
            progressBarFill.style.width = '0%';
            progressText.textContent = '0%';
            
            const formData = new FormData(uploadForm);
            formData.append('ajax', '1');
            
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.pathname, true);
            
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percentComplete = Math.round((e.loaded / e.total) * 100);
                    progressBarFill.style.width = percentComplete + '%';
                    progressText.textContent = percentComplete + '%';
                }
            };
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.status === 'success') {
                            progressBarFill.style.width = '100%';
                            progressText.textContent = '100%';
                            progressBarFill.style.background = 'var(--success-color)';
                            progressBarFill.style.boxShadow = '0 0 10px rgba(16, 185, 129, 0.5)';
                            submitBtn.textContent = 'Processing...';
                            setTimeout(() => {
                                window.location.href = window.location.pathname + '?success=' + response.share_id;
                            }, 500);
                        } else {
                            alert(response.message);
                            resetForm();
                        }
                    } catch (err) {
                        alert('Error parsing server response.');
                        resetForm();
                    }
                } else {
                    alert('An error occurred during the upload.');
                    resetForm();
                }
            };
            
            xhr.onerror = function() {
                alert('An error occurred during the upload.');
                resetForm();
            };
            
            xhr.send(formData);
        });
        
        function resetForm() {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Upload ' + fileInput.files[0].name;
            progressContainer.style.display = 'none';
            progressBarFill.style.width = '0%';
            progressBarFill.style.background = 'var(--primary-color)';
            progressBarFill.style.boxShadow = '0 0 10px rgba(59, 130, 246, 0.5)';
        }

        function deleteFile(btn) {
            if (!confirm('Are you sure you want to delete this file? This link will become permanently invalid.')) return;
            
            const shareId = btn.getAttribute('data-id');
            const listItem = btn.closest('.list-item');
            
            btn.textContent = '...';
            btn.disabled = true;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('share_id', shareId);
            formData.append('ajax', '1');
            
            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    listItem.style.opacity = '0';
                    setTimeout(() => {
                        listItem.remove();
                        // Check if list is empty
                        const remaining = document.querySelectorAll('.list-item');
                        if (remaining.length === 0) {
                            const listContainer = document.querySelector('.files-list');
                            if (listContainer) listContainer.remove();
                        }
                    }, 300);
                } else {
                    alert(data.message || 'Error deleting file.');
                    btn.textContent = 'Delete';
                    btn.disabled = false;
                }
            })
            .catch(error => {
                alert('Network error while deleting.');
                btn.textContent = 'Delete';
                btn.disabled = false;
            });
        }
    </script>
</body>
</html>
