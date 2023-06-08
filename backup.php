<?php

$config = json_decode(file_get_contents(__DIR__ . '\\backup_config.json'), TRUE);

if (!$config) {
    log_error('Failed to open/decode config file.');
    exit;
}

function copy_file_to_backup($file, $zip, $config) {
    if (filesize($file) > $config['max_filesize_bytes']) {
        log_error($file . ' exceeds the maximum file size.');
    } else {
        $file_name = pathinfo($file, PATHINFO_BASENAME);
        $zip->addFile($file, $file_name);
    }
}

function list_files_rec($path, $ts, $zip, $config) {
    $contents = scandir($path);

    foreach($contents as $ressource) {
        // Ignore current and parent directories.
        if ($ressource === '.' || $ressource === '..') {
            continue;
        }

        $ressource_abs = $path . '\\' . $ressource;
        if (!is_dir($ressource_abs)) {
            $last_modified = filemtime($ressource_abs);
            if (!$last_modified) {
                log_error('Failed to read last modified date of file ' . $ressource_abs . '.');
                continue;
            }
            // If the file is newer than the ts, copy it into a backup folder
            if ($last_modified > $ts) {
                copy_file_to_backup($ressource_abs, $zip, $config);
            }
        } else {
            list_files_rec($ressource_abs, $ts, $zip, $config);
        }
    }
}

function log_error($msg) {
    $timestamp = date('Y-m-d H:i:s');
    $log = fopen(__DIR__ . '\\' . 'log.txt', 'a');
    fwrite($log, $timestamp . ' - ' . $msg . PHP_EOL);
    fclose($log);
}

$last_run_ts = file_get_contents(__DIR__ . '\\last_run.txt');
if (!$last_run_ts) {
    log_error('Failed to read timestamp of last run of backup script.');
    exit;
}

$todays_date = date('Y_m_d');

$zip = new ZipArchive;
$backup_path = $config['backup_base_path'] . '\\'. $todays_date;
$res = $zip->open($backup_path . '.zip', ZipArchive::CREATE);

foreach ($config['paths'] as $path) {
    list_files_rec($path, $last_run_ts, $zip, $config);
}

// If there are no new files to backup, we are done.
if ($zip->numFiles < 1) {
    $zip->close();
    exit;
}

$zip->close();

// Encrypt it.
exec('C:\"Program Files"\AESCrypt\aescrypt.exe -e -p ' . $config['aes_pwd'] . ' ' . $backup_path . '.zip');
// Move to Dropbox
if (file_exists($backup_path . '.zip.aes')) {
    $successfully_copied = copy($backup_path . '.zip.aes', $config['dropbox_path'] . '\\'. $todays_date . '.zip.aes');
    if ($successfully_copied) {
        unlink($backup_path . '.zip.aes');
    } else {
        log_error('Moving to Dropbox failed.');
        exit;
    }
} else {
    log_error('AES encrypt failed.');
    exit;
}

// Update the last run timestamp!
$last_run_tracker = fopen(__DIR__ . '\\last_run.txt', 'w');
fwrite($last_run_tracker, time());
fclose($last_run_tracker);

exit;
