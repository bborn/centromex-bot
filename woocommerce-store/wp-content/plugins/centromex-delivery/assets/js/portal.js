/**
 * Centromex Delivery - Driver Portal JavaScript
 */

(function($) {
    'use strict';

    var Portal = {
        driverId: null,
        driverName: null,

        init: function() {
            this.bindEvents();
            this.checkSession();
        },

        bindEvents: function() {
            var self = this;

            // Tab switching
            $('.tab-btn').on('click', function() {
                var tab = $(this).data('tab');
                $('.tab-btn').removeClass('active');
                $(this).addClass('active');
                $('.tab-content').hide();
                $('#' + tab + '-tab').show();
            });

            // Login form
            $('#driver-login-form').on('submit', function(e) {
                e.preventDefault();
                self.login($(this));
            });

            // Register form
            $('#driver-register-form').on('submit', function(e) {
                e.preventDefault();
                self.register($(this));
            });

            // Logout
            $('#logout-btn').on('click', function() {
                self.logout();
            });

            // Zone filter
            $('#zone-filter').on('change', function() {
                self.loadOrders();
            });

            // Refresh
            $('#refresh-btn').on('click', function() {
                self.loadOrders();
            });

            // Claim order
            $(document).on('click', '.btn-claim', function() {
                var orderId = $(this).closest('.order-card').data('order-id');
                self.claimOrder(orderId, $(this));
            });

            // Update status
            $(document).on('click', '.order-actions .btn[data-action]', function() {
                var orderId = $(this).closest('.order-card').data('order-id');
                var action = $(this).data('action');
                self.updateStatus(orderId, action, $(this));
            });
        },

        checkSession: function() {
            // Check if driver_id cookie exists
            var driverId = this.getCookie('centromex_driver_id');
            if (driverId) {
                this.driverId = parseInt(driverId);
                this.showDashboard();
                this.loadOrders();
            }
        },

        login: function($form) {
            var self = this;
            var phone = $form.find('[name="phone"]').val();

            $.ajax({
                url: centromexPortal.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'centromex_driver_login',
                    nonce: centromexPortal.nonce,
                    phone: phone
                },
                success: function(response) {
                    if (response.success) {
                        self.driverId = response.data.driver_id;
                        self.driverName = response.data.name;
                        self.setCookie('centromex_driver_id', self.driverId, 30);
                        $('#driver-name').text(self.driverName);
                        self.showDashboard();
                        self.loadOrders();
                    } else {
                        self.showMessage(response.data.message, 'error', $form);
                    }
                },
                error: function() {
                    self.showMessage('Error connecting to server', 'error', $form);
                }
            });
        },

        register: function($form) {
            var self = this;
            var data = {
                action: 'centromex_driver_register',
                nonce: centromexPortal.nonce,
                name: $form.find('[name="name"]').val(),
                phone: $form.find('[name="phone"]').val(),
                email: $form.find('[name="email"]').val()
            };

            $.ajax({
                url: centromexPortal.ajaxUrl,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (response.success) {
                        self.showMessage(response.data.message, 'success', $form);
                        $form[0].reset();
                    } else {
                        self.showMessage(response.data.message, 'error', $form);
                    }
                },
                error: function() {
                    self.showMessage('Error connecting to server', 'error', $form);
                }
            });
        },

        logout: function() {
            this.driverId = null;
            this.driverName = null;
            this.deleteCookie('centromex_driver_id');
            this.showAuth();
        },

        showDashboard: function() {
            $('#auth-section').hide();
            $('#dashboard-section').show();
        },

        showAuth: function() {
            $('#dashboard-section').hide();
            $('#auth-section').show();
        },

        loadOrders: function() {
            var self = this;
            var zone = $('#zone-filter').val();

            $.ajax({
                url: centromexPortal.ajaxUrl,
                type: 'GET',
                data: {
                    action: 'centromex_get_available_orders',
                    nonce: centromexPortal.nonce,
                    zone: zone,
                    driver_id: this.driverId
                },
                success: function(response) {
                    if (response.success) {
                        self.renderMyOrders(response.data.my_orders);
                        self.renderAvailableOrders(response.data.available);
                    }
                }
            });
        },

        renderMyOrders: function(orders) {
            var $list = $('#my-orders-list');
            $list.empty();

            if (!orders || orders.length === 0) {
                $list.html('<p class="no-orders">No claimed orders</p>');
                return;
            }

            orders.forEach(function(order) {
                var statusLabel = order.status === 'picked_up' ? 'Out for Delivery' : 'Claimed';
                var html = '<div class="order-card my-order ' + order.status + '" data-order-id="' + order.id + '">' +
                    '<div class="order-header">' +
                        '<span class="order-number">#' + order.order_number + '</span>' +
                        '<span class="status-badge ' + order.status + '">' + statusLabel + '</span>' +
                    '</div>' +
                    '<div class="order-details">' +
                        '<span class="zone-badge">' + order.zone + '</span>' +
                        '<span class="bag-count">' + order.bag_count + ' bags</span>' +
                    '</div>' +
                    '<div class="order-actions">';

                if (order.status === 'claimed') {
                    html += '<button class="btn btn-small btn-pickup" data-action="picked_up">Picked Up</button>';
                }
                html += '<button class="btn btn-small btn-delivered" data-action="delivered">Delivered</button>';
                html += '<button class="btn btn-small btn-cancel" data-action="cancelled">Cancel</button>';
                html += '</div></div>';

                $list.append(html);
            });
        },

        renderAvailableOrders: function(orders) {
            var $list = $('#available-orders-list');
            $list.empty();

            if (!orders || orders.length === 0) {
                $list.html('<p class="no-orders">No deliveries available right now</p>');
                return;
            }

            orders.forEach(function(order) {
                var html = '<div class="order-card" data-order-id="' + order.id + '">' +
                    '<div class="order-header">' +
                        '<span class="order-number">#' + order.order_number + '</span>' +
                        '<span class="zone-badge">' + order.zone + '</span>' +
                    '</div>' +
                    '<div class="order-details">' +
                        '<span class="bag-count">' + order.bag_count + ' bags</span>' +
                        '<span class="ready-time">' + (order.ready_ago || 'Ready now') + '</span>' +
                    '</div>' +
                    '<div class="order-actions">' +
                        '<button class="btn btn-claim">Claim This Delivery</button>' +
                    '</div>' +
                '</div>';

                $list.append(html);
            });
        },

        claimOrder: function(orderId, $btn) {
            var self = this;
            $btn.prop('disabled', true).text('Claiming...');

            $.ajax({
                url: centromexPortal.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'centromex_claim_order',
                    nonce: centromexPortal.nonce,
                    order_id: orderId,
                    driver_id: this.driverId
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage(response.data.message, 'success', $('#dashboard-section'));
                        self.loadOrders();
                    } else {
                        self.showMessage(response.data.message, 'error', $('#dashboard-section'));
                        $btn.prop('disabled', false).text('Claim This Delivery');
                    }
                },
                error: function() {
                    self.showMessage('Error connecting to server', 'error', $('#dashboard-section'));
                    $btn.prop('disabled', false).text('Claim This Delivery');
                }
            });
        },

        updateStatus: function(orderId, status, $btn) {
            var self = this;
            var originalText = $btn.text();
            $btn.prop('disabled', true).text('Updating...');

            $.ajax({
                url: centromexPortal.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'centromex_update_delivery_status',
                    nonce: centromexPortal.nonce,
                    order_id: orderId,
                    driver_id: this.driverId,
                    status: status
                },
                success: function(response) {
                    if (response.success) {
                        self.showMessage(response.data.message, 'success', $('#dashboard-section'));
                        self.loadOrders();
                    } else {
                        self.showMessage(response.data.message, 'error', $('#dashboard-section'));
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    self.showMessage('Error connecting to server', 'error', $('#dashboard-section'));
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        },

        showMessage: function(message, type, $container) {
            var $msg = $('<div class="message ' + type + '">' + message + '</div>');
            $container.find('.message').remove();
            $container.prepend($msg);

            setTimeout(function() {
                $msg.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        getCookie: function(name) {
            var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? match[2] : null;
        },

        setCookie: function(name, value, days) {
            var expires = '';
            if (days) {
                var date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = '; expires=' + date.toUTCString();
            }
            document.cookie = name + '=' + value + expires + '; path=/';
        },

        deleteCookie: function(name) {
            document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        }
    };

    $(document).ready(function() {
        Portal.init();
    });

})(jQuery);
