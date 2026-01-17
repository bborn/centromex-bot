<?php
/**
 * Admin Settings Page for Volunteer Picker
 */

if (!defined('ABSPATH')) {
    exit;
}

$picker_url = home_url('/volunteer-picker/');
$openai_key = get_option('centromex_picker_openai_key', '');
$access_code = get_option('centromex_picker_access_code', '');

// Handle form submission
if (isset($_POST['centromex_picker_save_settings']) && check_admin_referer('centromex_picker_settings')) {
    update_option('centromex_picker_openai_key', sanitize_text_field($_POST['openai_key']));
    update_option('centromex_picker_access_code', sanitize_text_field($_POST['access_code']));

    $openai_key = get_option('centromex_picker_openai_key', '');
    $access_code = get_option('centromex_picker_access_code', '');

    echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
}

// Flush rewrite rules if needed
if (isset($_GET['flush_rules'])) {
    flush_rewrite_rules();
    echo '<div class="notice notice-success is-dismissible"><p>Rewrite rules flushed!</p></div>';
}
?>

<div class="wrap centromex-picker-admin">
    <h1>Volunteer Picker Settings</h1>

    <div class="centromex-picker-cards">
        <!-- App Access Card -->
        <div class="centromex-card">
            <h2>Volunteer Picker App</h2>
            <p>Share this link with volunteers so they can pick orders using their phones:</p>

            <div class="picker-url-box">
                <input type="text" readonly value="<?php echo esc_url($picker_url); ?>" id="pickerUrl" class="regular-text">
                <button type="button" class="button" onclick="copyPickerUrl()">Copy Link</button>
            </div>

            <p class="description">
                Volunteers can open this URL on their phone to start picking orders.
                It works like an app - they can add it to their home screen.
            </p>

            <div class="picker-qr" style="margin-top: 20px;">
                <h3>QR Code</h3>
                <p>Scan this code with a phone camera to open the picker app:</p>
                <div id="qrcode" style="margin-top: 10px;"></div>
            </div>
        </div>

        <!-- Settings Card -->
        <div class="centromex-card">
            <h2>Settings</h2>

            <form method="post" action="">
                <?php wp_nonce_field('centromex_picker_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="access_code">Access Code</label>
                        </th>
                        <td>
                            <input type="text" name="access_code" id="access_code"
                                   value="<?php echo esc_attr($access_code); ?>"
                                   class="regular-text">
                            <p class="description">
                                Optional. If set, volunteers must enter this code to start a session.
                                Leave blank to allow anyone with the link to access.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="openai_key">OpenAI API Key</label>
                        </th>
                        <td>
                            <input type="password" name="openai_key" id="openai_key"
                                   value="<?php echo esc_attr($openai_key); ?>"
                                   class="regular-text">
                            <p class="description">
                                For automatic translation of product names (Spanish/English).
                                Uses GPT-4o-mini for fast, affordable translations.
                            </p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="centromex_picker_save_settings"
                           class="button button-primary" value="Save Settings">
                </p>
            </form>
        </div>

        <!-- Stats Card -->
        <div class="centromex-card">
            <h2>Picking Statistics</h2>

            <?php
            global $wpdb;
            $picks_table = $wpdb->prefix . 'centromex_order_picks';
            $sessions_table = $wpdb->prefix . 'centromex_picker_sessions';

            $total_picks = $wpdb->get_var("SELECT COUNT(*) FROM $picks_table WHERE status = 'picked'");
            $total_sessions = $wpdb->get_var("SELECT COUNT(*) FROM $sessions_table");
            $active_sessions = $wpdb->get_var("SELECT COUNT(*) FROM $sessions_table WHERE completed_at IS NULL AND last_activity > DATE_SUB(NOW(), INTERVAL 1 HOUR)");

            $recent_volunteers = $wpdb->get_results("SELECT volunteer_name, COUNT(*) as picks FROM $sessions_table s
                JOIN $picks_table p ON s.order_id = p.order_id AND s.volunteer_name = p.volunteer_name
                WHERE p.status = 'picked' AND p.picked_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY volunteer_name ORDER BY picks DESC LIMIT 5");
            ?>

            <div class="picker-stats">
                <div class="stat-box">
                    <span class="stat-value"><?php echo intval($total_picks); ?></span>
                    <span class="stat-label">Total Items Picked</span>
                </div>
                <div class="stat-box">
                    <span class="stat-value"><?php echo intval($total_sessions); ?></span>
                    <span class="stat-label">Total Sessions</span>
                </div>
                <div class="stat-box">
                    <span class="stat-value"><?php echo intval($active_sessions); ?></span>
                    <span class="stat-label">Active Now</span>
                </div>
            </div>

            <?php if (!empty($recent_volunteers)): ?>
            <h3 style="margin-top: 20px;">Top Volunteers (Last 7 Days)</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Volunteer</th>
                        <th>Items Picked</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_volunteers as $vol): ?>
                    <tr>
                        <td><?php echo esc_html($vol->volunteer_name); ?></td>
                        <td><?php echo intval($vol->picks); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Help Card -->
        <div class="centromex-card">
            <h2>How It Works</h2>

            <ol class="picker-instructions">
                <li>
                    <strong>Share the Link</strong><br>
                    Send the picker URL to volunteers via text, WhatsApp, or show them the QR code.
                </li>
                <li>
                    <strong>Volunteer Signs In</strong><br>
                    They enter their name (and access code if required) to start a session.
                </li>
                <li>
                    <strong>View Orders</strong><br>
                    The app shows all orders ready to be picked, with item counts and progress.
                </li>
                <li>
                    <strong>Pick Items</strong><br>
                    For each item, volunteers can:
                    <ul>
                        <li>Mark as picked (Listo)</li>
                        <li>Scan the barcode to verify</li>
                        <li>Take a photo for confirmation</li>
                        <li>Mark as substituted with notes</li>
                        <li>Mark as unavailable</li>
                    </ul>
                </li>
                <li>
                    <strong>Complete Order</strong><br>
                    Once all items are picked, the volunteer marks the order complete.
                </li>
            </ol>

            <h3>Features</h3>
            <ul class="picker-features">
                <li>Works on any phone - just a web page, no app install needed</li>
                <li>Bilingual interface (Spanish/English)</li>
                <li>Automatic translation of product names</li>
                <li>Barcode scanning with phone camera</li>
                <li>Photo capture for verification</li>
                <li>Offline-friendly (PWA capable)</li>
            </ul>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
function copyPickerUrl() {
    const input = document.getElementById('pickerUrl');
    input.select();
    document.execCommand('copy');
    alert('Link copied to clipboard!');
}

// Generate QR code
document.addEventListener('DOMContentLoaded', function() {
    const qr = qrcode(0, 'M');
    qr.addData('<?php echo esc_js($picker_url); ?>');
    qr.make();
    document.getElementById('qrcode').innerHTML = qr.createImgTag(5, 10);
});
</script>

<style>
.centromex-picker-admin {
    max-width: 1200px;
}

.centromex-picker-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.centromex-card {
    background: white;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
}

.centromex-card h2 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.picker-url-box {
    display: flex;
    gap: 10px;
    margin: 15px 0;
}

.picker-url-box input {
    flex: 1;
}

.picker-stats {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.stat-box {
    background: #f0f0f1;
    padding: 15px 20px;
    border-radius: 4px;
    text-align: center;
    min-width: 100px;
}

.stat-value {
    display: block;
    font-size: 28px;
    font-weight: 600;
    color: #2e7d32;
}

.stat-label {
    display: block;
    font-size: 12px;
    color: #666;
    margin-top: 5px;
}

.picker-instructions {
    margin: 15px 0;
    padding-left: 20px;
}

.picker-instructions li {
    margin-bottom: 15px;
}

.picker-instructions ul {
    margin-top: 8px;
    margin-left: 20px;
}

.picker-features {
    margin: 15px 0;
    padding-left: 20px;
}

.picker-features li {
    margin-bottom: 8px;
}
</style>
