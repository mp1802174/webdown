<?php

// PhpWechatAggregator/trigger_login.php
// CLI script to trigger the Python-based QR code login process.

require __DIR__ . '/vendor/autoload.php'; // If you have other PHP dependencies

// --- Configuration ---
$appConfigPath = __DIR__ . '/config/app.php';
if (!file_exists($appConfigPath)) {
    die("Error: Application configuration file (config/app.php) not found.\n");
}
$appConfig = require $appConfigPath;

// Path to the Python interpreter (try `python` or `python3`, or full path)
$pythonInterpreter = 'python'; // Adjust if necessary (e.g., 'python' or '/usr/bin/python3')

// Path to the Python login helper script
$pythonLoginScript = __DIR__ . '/python_login_helper.py';

if (!file_exists($pythonLoginScript)) {
    die("Error: Python login helper script ({$pythonLoginScript}) not found.\n");
}

// --- Inform User ---
echo "---------------------------------------------------------------------\n";
echo "Attempting to initiate WeChat QR Code Login via Python helper...\n";
echo "A browser window should open. Please scan the QR code when it appears.\n";
echo "This script will wait for the Python script to complete.
";
echo "Ensure you have Python installed and DrissionPage library (pip install DrissionPage).
";
echo "Python script path: {$pythonLoginScript}\n";
echo "Python interpreter: {$pythonInterpreter}\n";
echo "---------------------------------------------------------------------\n\n";

// --- Execute Python Script ---
// We use proc_open for better control over streams (stdout, stderr)
$descriptorspec = [
   0 => ["pipe", "r"],  // stdin (not used here)
   1 => ["pipe", "w"],  // stdout (Python script's JSON output)
   2 => ["pipe", "w"]   // stderr (Python script's error messages)
];

$command = escapeshellcmd($pythonInterpreter) . ' ' . escapeshellarg($pythonLoginScript);
$process = proc_open($command, $descriptorspec, $pipes, __DIR__);

$stdout = '';
$stderr = '';

if (is_resource($process)) {
    echo "Python script started. Waiting for completion (this may take a while)...\n";
    // Important: Read stderr first or use non-blocking reads to avoid deadlocks
    // if Python script outputs a lot to stderr before exiting or before stdout.

    // Basic blocking read for simplicity in CLI context
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $return_value = proc_close($process);

    echo "\n--- Python Script Output (stderr) ---\n";
    if (!empty($stderr)) {
        echo $stderr . "\n";
    } else {
        echo "(No error output from Python script)\n";
    }
    echo "-------------------------------------\n";

    echo "Python script finished with exit code: {$return_value}\n";

    // --- Process Result ---
    if ($return_value === 0 && !empty($stdout)) {
        $result = json_decode($stdout, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($result['success'])) {
            if ($result['success'] === true && isset($result['token']) && isset($result['cookie'])) {
                echo "\nLogin successful via Python helper!\n";
                echo "New Token (first 20 chars): " . substr($result['token'], 0, 20) . "...\n";
                echo "New Cookie (first 20 chars): " . substr($result['cookie'], 0, 20) . "...\n";

                // Update PHP application's configuration (config/app.php)
                // WARNING: Modifying PHP config files programmatically can be risky.
                // Consider storing credentials in a separate JSON file or .env for easier/safer updates.
                $appConfig['wechat_credentials']['token'] = $result['token'];
                $appConfig['wechat_credentials']['cookie'] = $result['cookie'];

                // Re-write the app.php file
                $configContent = "<?php\n\n// PhpWechatAggregator/config/app.php\n\nreturn " . var_export($appConfig, true) . ";\n";
                if (file_put_contents($appConfigPath, $configContent) !== false) {
                    echo "Successfully updated credentials in {$appConfigPath}\n";
                    echo "The main 'cron.php' script should now be able to use these new credentials.\n";
                } else {
                    echo "ERROR: Failed to write updated credentials to {$appConfigPath}. Please update manually.\n";
                    echo "Token: " . $result['token'] . "\n";
                    echo "Cookie: " . $result['cookie'] . "\n";
                }
            } else {
                echo "\nPython script reported login failure or missing credentials:\n";
                echo "Error: " . ($result['error'] ?? 'Unknown error') . "\n";
                if (isset($result['details'])) {
                    echo "Details: \n" . $result['details'] . "\n";
                }
            }
        } else {
            echo "\nERROR: Failed to decode JSON response from Python script or malformed response.\n";
            echo "Raw stdout:\n{$stdout}\n";
        }
    } else {
        echo "\nERROR: Python script execution failed (exit code {$return_value}) or produced no stdout.\n";
        if (empty($stdout) && !empty($stderr)) {
            echo "Consider the stderr output above for clues.\n";
        }
    }
} else {
    die("Error: Failed to run the Python login script (proc_open failed).\n");
}

echo "\nLogin process finished.\n";

?> 