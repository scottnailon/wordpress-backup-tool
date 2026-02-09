<?php
/**
 * Plugin Name: Simple Backup Tool
 * Plugin URI: https://goodhost.com.au
 * Description: Modern backup plugin with progress bar and download links (nginx-compatible)
 * Version: 2.1.0
 * Author: Good Host
 * Author URI: https://goodhost.com.au
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

class SimpleBackupTool {

    private $backup_dir;

    public function __construct() {
        $this->backup_dir = WP_CONTENT_DIR . '/backups';

        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            file_put_contents($this->backup_dir . '/.htaccess', "Deny from all\n");
            file_put_contents($this->backup_dir . '/index.php', '<?php // Silence is golden');
        }

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function add_admin_menu() {
        add_management_page('Simple Backup', 'Simple Backup', 'manage_options', 'simple-backup-tool', array($this, 'admin_page'));
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_simple-backup-tool') return;

        wp_enqueue_style('sbt-styles', false);
        wp_add_inline_style('sbt-styles', $this->get_css());

        wp_enqueue_script('sbt-script', false, array('jquery'), '2.1.0', true);
        wp_add_inline_script('sbt-script', $this->get_js());
    }

    private function get_css() {
        return '
            .sbt-container { max-width: 1200px; margin: 20px 0; }
            .sbt-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
            @media (max-width: 768px) { .sbt-grid { grid-template-columns: 1fr; } }
            .sbt-card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .sbt-card h2 { margin-top: 0; font-size: 18px; font-weight: 600; color: #1d2327; }
            .sbt-backup-button { width: 100%; height: 60px; font-size: 16px; margin-bottom: 12px; display: flex; align-items: center; justify-content: center; gap: 10px; transition: all 0.2s; border: none; cursor: pointer; }
            .sbt-backup-button:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,.1); }
            .sbt-backup-button:disabled { opacity: 0.6; cursor: not-allowed; }
            .sbt-backup-button .dashicons { font-size: 24px; width: 24px; height: 24px; }
            .sbt-progress-container { display: none; margin: 20px 0; padding: 20px; background: #f0f6fc; border: 1px solid #0969da; border-radius: 4px; }
            .sbt-progress-container.active { display: block; }
            .sbt-progress-bar-wrapper { width: 100%; height: 30px; background: #e1e8ed; border-radius: 15px; overflow: hidden; position: relative; margin: 15px 0; }
            .sbt-progress-bar { height: 100%; background: linear-gradient(90deg, #0969da 0%, #0550ae 100%); width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 12px; }
            .sbt-progress-text { text-align: center; color: #0550ae; font-weight: 500; margin-top: 10px; }
            .sbt-success-box { display: none; padding: 20px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; color: #155724; margin: 15px 0; }
            .sbt-success-box.show { display: block; }
            .sbt-success-box h3 { margin-top: 0; color: #155724; }
            .sbt-download-link { display: inline-block; margin: 8px 8px 8px 0; padding: 12px 20px; background: #2271b1; color: white !important; text-decoration: none; border-radius: 4px; font-weight: 600; transition: all 0.2s; }
            .sbt-download-link:hover { background: #135e96; transform: translateY(-1px); }
            .sbt-download-link .dashicons { margin-right: 5px; }
            .sbt-backups-table { width: 100%; border-collapse: collapse; }
            .sbt-backups-table th, .sbt-backups-table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
            .sbt-backups-table th { background: #f6f7f7; font-weight: 600; color: #1d2327; }
            .sbt-backups-table tr:hover { background: #f6f7f7; }
            .sbt-backups-table code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
            .sbt-stat { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f1; }
            .sbt-stat:last-child { border-bottom: none; }
            .sbt-stat-label { font-weight: 600; color: #1d2327; }
            .sbt-stat-value { color: #50575e; }
            .sbt-empty-state { text-align: center; padding: 40px 20px; color: #646970; }
            .sbt-empty-state .dashicons { font-size: 48px; width: 48px; height: 48px; opacity: 0.3; }
            .sbt-iframe { display: none; }
            .button-database { background: #2271b1 !important; border-color: #2271b1 !important; }
            .button-files { background: #d63638 !important; border-color: #d63638 !important; }
            .button-complete { background: #00a32a !important; border-color: #00a32a !important; }
        ';
    }

    private function get_js() {
        return "
            jQuery(document).ready(function($) {
                let backupInProgress = false;

                $('.sbt-backup-form').on('submit', function(e) {
                    if (backupInProgress) {
                        e.preventDefault();
                        return false;
                    }

                    backupInProgress = true;
                    const form = $(this);
                    const backupType = form.find('input[name=backup_type]').val();

                    // Disable all buttons
                    $('.sbt-backup-button').prop('disabled', true);

                    // Show progress
                    $('.sbt-progress-container').addClass('active');
                    $('.sbt-success-box').removeClass('show');
                    $('.sbt-progress-bar').css('width', '0%').text('0%');
                    $('.sbt-progress-text').text('Initializing backup...');

                    // Animate progress
                    let progress = 0;
                    const progressInterval = setInterval(function() {
                        if (progress < 85) {
                            progress += Math.random() * 10;
                            if (progress > 85) progress = 85;
                            $('.sbt-progress-bar').css('width', progress + '%').text(Math.round(progress) + '%');

                            if (progress < 20) {
                                $('.sbt-progress-text').text('Preparing backup...');
                            } else if (progress < 40) {
                                $('.sbt-progress-text').text('Reading database tables...');
                            } else if (progress < 60) {
                                $('.sbt-progress-text').text('Creating archive...');
                            } else {
                                $('.sbt-progress-text').text('Finalizing...');
                            }
                        } else {
                            clearInterval(progressInterval);
                        }
                    }, 400);

                    // Monitor iframe load
                    $('#sbt-iframe').on('load', function() {
                        clearInterval(progressInterval);

                        try {
                            const iframeDoc = this.contentDocument || this.contentWindow.document;
                            const content = $(iframeDoc).find('body').text();

                            // Check if there's an error
                            if (content.includes('Error:')) {
                                $('.sbt-progress-bar').css('width', '100%').css('background', '#d63638').text('Error');
                                $('.sbt-progress-text').html('<span style=\"color:#d63638\">' + content + '</span>');
                            } else {
                                // Success
                                $('.sbt-progress-bar').css('width', '100%').text('100%');
                                $('.sbt-progress-text').text('Backup completed!');

                                setTimeout(function() {
                                    window.location.reload();
                                }, 1500);
                            }
                        } catch (err) {
                            // Cross-origin or success (redirect happened)
                            $('.sbt-progress-bar').css('width', '100%').text('100%');
                            $('.sbt-progress-text').text('Backup completed!');

                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        }

                        backupInProgress = false;
                        $('.sbt-backup-button').prop('disabled', false);
                    });
                });

                // Delete backup
                $('.sbt-delete-btn').on('click', function(e) {
                    if (!confirm('Are you sure you want to delete this backup?')) {
                        e.preventDefault();
                        return false;
                    }
                });
            });
        ";
    }

    public function handle_actions() {
        if (!current_user_can('manage_options')) return;

        // Handle backup creation
        if (isset($_POST['sbt_create_backup']) && check_admin_referer('sbt_create_backup')) {
            $backup_type = sanitize_text_field($_POST['backup_type']);

            try {
                $files = array();

                switch ($backup_type) {
                    case 'database':
                        $files[] = $this->backup_database();
                        break;
                    case 'files':
                        $files[] = $this->backup_files();
                        break;
                    case 'complete':
                        $files[] = $this->backup_database();
                        $files[] = $this->backup_files();
                        break;
                }

                $params = array(
                    'page' => 'simple-backup-tool',
                    'backup_success' => '1',
                    'files' => implode(',', array_map('basename', $files))
                );

                wp_redirect(add_query_arg($params, admin_url('tools.php')));
                exit;

            } catch (Exception $e) {
                wp_redirect(add_query_arg(array(
                    'page' => 'simple-backup-tool',
                    'backup_error' => urlencode($e->getMessage())
                ), admin_url('tools.php')));
                exit;
            }
        }

        // Handle download
        if (isset($_GET['sbt_download']) && check_admin_referer('sbt_download_' . $_GET['sbt_download'])) {
            $file = sanitize_file_name($_GET['sbt_download']);
            $filepath = $this->backup_dir . '/' . $file;

            if (file_exists($filepath)) {
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize($filepath));
                ob_clean();
                flush();
                readfile($filepath);
                exit;
            }
        }

        // Handle delete
        if (isset($_GET['sbt_delete']) && check_admin_referer('sbt_delete_' . $_GET['sbt_delete'])) {
            $file = sanitize_file_name($_GET['sbt_delete']);
            $filepath = $this->backup_dir . '/' . $file;

            if (file_exists($filepath)) {
                unlink($filepath);
            }

            wp_redirect(add_query_arg('page', 'simple-backup-tool', admin_url('tools.php')));
            exit;
        }
    }

    public function admin_page() {
        $backup_files = array();
        if (isset($_GET['backup_success']) && isset($_GET['files'])) {
            $backup_files = explode(',', sanitize_text_field($_GET['files']));
        }
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-backup" style="font-size: 30px; width: 30px; height: 30px;"></span> Simple Backup Tool</h1>
            <p class="description">Create and manage backups of your WordPress site with visual progress tracking.</p>

            <div class="sbt-container">
                <?php if (isset($_GET['backup_error'])): ?>
                    <div class="notice notice-error is-dismissible">
                        <p><strong>Backup Error:</strong> <?php echo esc_html(urldecode($_GET['backup_error'])); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($backup_files)): ?>
                    <div class="sbt-success-box show">
                        <h3>✓ Backup Completed Successfully!</h3>
                        <p>Your backup has been created. Download it now:</p>
                        <?php foreach ($backup_files as $file):
                            $download_url = wp_nonce_url(
                                add_query_arg(array('page' => 'simple-backup-tool', 'sbt_download' => $file), admin_url('tools.php')),
                                'sbt_download_' . $file
                            );
                            $filepath = $this->backup_dir . '/' . $file;
                            $size = file_exists($filepath) ? size_format(filesize($filepath)) : '';
                        ?>
                            <a href="<?php echo esc_url($download_url); ?>" class="sbt-download-link">
                                <span class="dashicons dashicons-download"></span>
                                <?php echo esc_html($file); ?> (<?php echo esc_html($size); ?>)
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Progress Container -->
                <div class="sbt-progress-container">
                    <h3>Backup in Progress</h3>
                    <div class="sbt-progress-bar-wrapper">
                        <div class="sbt-progress-bar">0%</div>
                    </div>
                    <div class="sbt-progress-text">Initializing...</div>
                </div>

                <div class="sbt-grid">
                    <!-- Create Backup Card -->
                    <div class="sbt-card">
                        <h2>Create New Backup</h2>
                        <p>Choose what to backup:</p>

                        <form method="post" action="" class="sbt-backup-form" target="sbt-iframe">
                            <?php wp_nonce_field('sbt_create_backup'); ?>
                            <input type="hidden" name="sbt_create_backup" value="1">
                            <input type="hidden" name="backup_type" value="database">
                            <button type="submit" class="button button-primary button-hero sbt-backup-button button-database">
                                <span class="dashicons dashicons-database"></span>
                                Database Only
                            </button>
                        </form>

                        <form method="post" action="" class="sbt-backup-form" target="sbt-iframe">
                            <?php wp_nonce_field('sbt_create_backup'); ?>
                            <input type="hidden" name="sbt_create_backup" value="1">
                            <input type="hidden" name="backup_type" value="files">
                            <button type="submit" class="button button-primary button-hero sbt-backup-button button-files">
                                <span class="dashicons dashicons-media-archive"></span>
                                Files Only
                            </button>
                        </form>

                        <form method="post" action="" class="sbt-backup-form" target="sbt-iframe">
                            <?php wp_nonce_field('sbt_create_backup'); ?>
                            <input type="hidden" name="sbt_create_backup" value="1">
                            <input type="hidden" name="backup_type" value="complete">
                            <button type="submit" class="button button-primary button-hero sbt-backup-button button-complete">
                                <span class="dashicons dashicons-download"></span>
                                Complete Backup
                            </button>
                        </form>
                    </div>

                    <!-- Site Info Card -->
                    <div class="sbt-card">
                        <h2>Site Information</h2>
                        <div class="sbt-stat">
                            <span class="sbt-stat-label">Database Size:</span>
                            <span class="sbt-stat-value"><?php echo $this->get_database_size(); ?></span>
                        </div>
                        <div class="sbt-stat">
                            <span class="sbt-stat-label">Files Size:</span>
                            <span class="sbt-stat-value"><?php echo $this->get_files_size(); ?></span>
                        </div>
                        <div class="sbt-stat">
                            <span class="sbt-stat-label">Total Backups:</span>
                            <span class="sbt-stat-value"><?php echo count(glob($this->backup_dir . '/*.{sql,zip}', GLOB_BRACE)); ?></span>
                        </div>
                        <div class="sbt-stat">
                            <span class="sbt-stat-label">Backup Location:</span>
                            <span class="sbt-stat-value"><code>wp-content/backups/</code></span>
                        </div>
                    </div>
                </div>

                <!-- Existing Backups -->
                <div class="sbt-card">
                    <h2>Existing Backups</h2>
                    <?php $this->list_existing_backups(); ?>
                </div>
            </div>

            <iframe id="sbt-iframe" name="sbt-iframe" class="sbt-iframe"></iframe>
        </div>
        <?php
    }

    private function list_existing_backups() {
        $files = glob($this->backup_dir . '/*.{sql,zip}', GLOB_BRACE);

        if (empty($files)) {
            echo '<div class="sbt-empty-state">';
            echo '<span class="dashicons dashicons-archive"></span>';
            echo '<p>No backups found yet. Create your first backup above!</p>';
            echo '</div>';
            return;
        }

        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        echo '<table class="sbt-backups-table">';
        echo '<thead><tr><th>Filename</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead><tbody>';

        foreach ($files as $file) {
            $filename = basename($file);
            $size = size_format(filesize($file));
            $date = date('M j, Y g:i A', filemtime($file));

            $download_url = wp_nonce_url(
                add_query_arg(array('page' => 'simple-backup-tool', 'sbt_download' => $filename), admin_url('tools.php')),
                'sbt_download_' . $filename
            );

            $delete_url = wp_nonce_url(
                add_query_arg(array('page' => 'simple-backup-tool', 'sbt_delete' => $filename), admin_url('tools.php')),
                'sbt_delete_' . $filename
            );

            echo '<tr>';
            echo '<td><code>' . esc_html($filename) . '</code></td>';
            echo '<td>' . esc_html($size) . '</td>';
            echo '<td>' . esc_html($date) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($download_url) . '" class="button button-small button-primary">';
            echo '<span class="dashicons dashicons-download"></span> Download</a> ';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small sbt-delete-btn">';
            echo '<span class="dashicons dashicons-trash"></span> Delete</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function backup_database() {
        global $wpdb;

        $timestamp = date('Y-m-d_H-i-s');
        $filename = 'database_' . $timestamp . '.sql';
        $filepath = $this->backup_dir . '/' . $filename;

        $mysqldump_path = $this->find_mysqldump();

        if ($mysqldump_path) {
            $host_parts = explode(':', DB_HOST);
            $host = $host_parts[0];
            $port = isset($host_parts[1]) ? $host_parts[1] : '3306';

            $command = sprintf(
                '%s --host=%s --port=%s --user=%s --password=%s %s > %s 2>&1',
                escapeshellarg($mysqldump_path),
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg(DB_USER),
                escapeshellarg(DB_PASSWORD),
                escapeshellarg(DB_NAME),
                escapeshellarg($filepath)
            );

            exec($command, $output, $return_var);

            if ($return_var !== 0) {
                throw new Exception('mysqldump failed');
            }
        } else {
            $handle = fopen($filepath, 'w');
            if (!$handle) throw new Exception('Could not create backup file');

            $tables = $wpdb->get_results('SHOW TABLES', ARRAY_N);

            foreach ($tables as $table) {
                $table_name = $table[0];
                $create_table = $wpdb->get_row("SHOW CREATE TABLE `{$table_name}`", ARRAY_N);
                fwrite($handle, "\n\n-- Table: {$table_name}\n");
                fwrite($handle, "DROP TABLE IF EXISTS `{$table_name}`;\n");
                fwrite($handle, $create_table[1] . ";\n\n");

                $rows = $wpdb->get_results("SELECT * FROM `{$table_name}`", ARRAY_A);

                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $values = array_map(function($value) use ($wpdb) {
                            return is_null($value) ? 'NULL' : "'" . $wpdb->_real_escape($value) . "'";
                        }, array_values($row));

                        $columns = implode('`, `', array_keys($row));
                        $values_str = implode(', ', $values);

                        fwrite($handle, "INSERT INTO `{$table_name}` (`{$columns}`) VALUES ({$values_str});\n");
                    }
                }
            }

            fclose($handle);
        }

        return $filepath;
    }

    private function backup_files() {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = 'files_' . $timestamp . '.zip';
        $filepath = $this->backup_dir . '/' . $filename;

        if (!class_exists('ZipArchive')) {
            throw new Exception('ZipArchive not available');
        }

        $zip = new ZipArchive();
        if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception('Could not create zip file');
        }

        $root_path = ABSPATH;
        $exclude_dirs = array('backups', 'cache', 'upgrade');

        $this->add_directory_to_zip($zip, $root_path, $root_path, $exclude_dirs);
        $zip->close();

        return $filepath;
    }

    private function add_directory_to_zip($zip, $dir, $base_dir, $exclude_dirs = array()) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($files as $file) {
            $file_path = $file->getPathname();
            $relative_path = substr($file_path, strlen($base_dir));

            $skip = false;
            foreach ($exclude_dirs as $exclude) {
                if (strpos($relative_path, $exclude) !== false) {
                    $skip = true;
                    break;
                }
            }

            if ($skip || strpos($file_path, $this->backup_dir) === 0) continue;

            if ($file->isDir()) {
                $zip->addEmptyDir($relative_path);
            } else if ($file->isFile()) {
                $zip->addFile($file_path, $relative_path);
            }
        }
    }

    private function get_database_size() {
        global $wpdb;
        $size = $wpdb->get_var("SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'");
        return size_format($size ?: 0);
    }

    private function get_files_size() {
        $size = 0;
        try {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABSPATH, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (Exception $e) {
            return 'N/A';
        }
        return size_format($size);
    }

    private function find_mysqldump() {
        $possible_paths = array(
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
        );

        foreach ($possible_paths as $path) {
            if (@file_exists($path) && @is_executable($path)) {
                return $path;
            }
        }

        return false;
    }
}

new SimpleBackupTool();
