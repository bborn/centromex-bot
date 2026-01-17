<?php
/**
 * Driver Portal Template
 *
 * PRIVACY: This page NEVER displays customer addresses.
 * Drivers receive addresses only when picking up at Centromex.
 */

if (!defined('ABSPATH')) {
    exit;
}

$zones = Centromex_Delivery_Zones::get_all();
$driver_id = Centromex_Driver_Portal::get_current_driver_id();
$driver = $driver_id ? Centromex_Driver_Manager::get($driver_id) : null;
?>

<div class="centromex-driver-portal">

    <!-- Login/Register Section (shown if not logged in) -->
    <div id="auth-section" class="portal-section" style="<?php echo $driver ? 'display:none' : ''; ?>">
        <div class="portal-header">
            <h2><?php _e('Driver Portal', 'centromex-delivery'); ?></h2>
            <p><?php _e('Sign in or register to view and claim deliveries.', 'centromex-delivery'); ?></p>
        </div>

        <div class="auth-tabs">
            <button class="tab-btn active" data-tab="login"><?php _e('Sign In', 'centromex-delivery'); ?></button>
            <button class="tab-btn" data-tab="register"><?php _e('Register', 'centromex-delivery'); ?></button>
        </div>

        <div class="tab-content" id="login-tab">
            <form id="driver-login-form">
                <div class="form-group">
                    <label for="login-phone"><?php _e('Phone Number', 'centromex-delivery'); ?></label>
                    <input type="tel" id="login-phone" name="phone" required placeholder="555-123-4567">
                </div>
                <button type="submit" class="btn btn-primary"><?php _e('Sign In', 'centromex-delivery'); ?></button>
            </form>
        </div>

        <div class="tab-content" id="register-tab" style="display:none">
            <form id="driver-register-form">
                <div class="form-group">
                    <label for="register-name"><?php _e('Full Name', 'centromex-delivery'); ?></label>
                    <input type="text" id="register-name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="register-phone"><?php _e('Phone Number', 'centromex-delivery'); ?></label>
                    <input type="tel" id="register-phone" name="phone" required placeholder="555-123-4567">
                </div>
                <div class="form-group">
                    <label for="register-email"><?php _e('Email (optional)', 'centromex-delivery'); ?></label>
                    <input type="email" id="register-email" name="email">
                </div>
                <button type="submit" class="btn btn-primary"><?php _e('Register', 'centromex-delivery'); ?></button>
            </form>
            <p class="form-note"><?php _e('After registering, a coordinator will approve your account.', 'centromex-delivery'); ?></p>
        </div>
    </div>

    <!-- Driver Dashboard (shown when logged in) -->
    <div id="dashboard-section" class="portal-section" style="<?php echo $driver ? '' : 'display:none'; ?>">
        <div class="portal-header">
            <h2><?php _e('Welcome', 'centromex-delivery'); ?>, <span id="driver-name"><?php echo $driver ? esc_html($driver->name) : ''; ?></span>!</h2>
            <button id="logout-btn" class="btn btn-small"><?php _e('Sign Out', 'centromex-delivery'); ?></button>
        </div>

        <!-- My Orders -->
        <div class="my-orders-section">
            <h3><?php _e('My Claimed Orders', 'centromex-delivery'); ?></h3>
            <div id="my-orders-list" class="orders-list">
                <p class="loading"><?php _e('Loading...', 'centromex-delivery'); ?></p>
            </div>
        </div>

        <!-- Available Orders -->
        <div class="available-orders-section">
            <h3><?php _e('Available Deliveries', 'centromex-delivery'); ?></h3>

            <div class="zone-filter">
                <label for="zone-filter"><?php _e('Filter by zone:', 'centromex-delivery'); ?></label>
                <select id="zone-filter">
                    <option value=""><?php _e('All zones', 'centromex-delivery'); ?></option>
                    <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo esc_attr($zone->name); ?>"><?php echo esc_html($zone->name); ?></option>
                    <?php endforeach; ?>
                </select>
                <button id="refresh-btn" class="btn btn-small"><?php _e('Refresh', 'centromex-delivery'); ?></button>
            </div>

            <div id="available-orders-list" class="orders-list">
                <p class="loading"><?php _e('Loading...', 'centromex-delivery'); ?></p>
            </div>
        </div>

        <!-- Privacy Notice -->
        <div class="privacy-notice">
            <p><strong><?php _e('Privacy Notice:', 'centromex-delivery'); ?></strong> <?php _e('Customer addresses are not shown here. Pick up orders at Centromex to receive the delivery address.', 'centromex-delivery'); ?></p>
        </div>
    </div>

    <!-- Templates for JS rendering -->
    <template id="order-card-template">
        <div class="order-card">
            <div class="order-header">
                <span class="order-number"></span>
                <span class="zone-badge"></span>
            </div>
            <div class="order-details">
                <span class="bag-count"></span>
                <span class="ready-time"></span>
            </div>
            <div class="order-actions"></div>
        </div>
    </template>

    <template id="my-order-card-template">
        <div class="order-card my-order">
            <div class="order-header">
                <span class="order-number"></span>
                <span class="status-badge"></span>
            </div>
            <div class="order-details">
                <span class="zone-badge"></span>
                <span class="bag-count"></span>
            </div>
            <div class="order-actions">
                <button class="btn btn-small btn-pickup" data-action="picked_up"><?php _e('Picked Up', 'centromex-delivery'); ?></button>
                <button class="btn btn-small btn-delivered" data-action="delivered"><?php _e('Delivered', 'centromex-delivery'); ?></button>
                <button class="btn btn-small btn-cancel" data-action="cancelled"><?php _e('Cancel', 'centromex-delivery'); ?></button>
            </div>
        </div>
    </template>
</div>
