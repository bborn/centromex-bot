#!/bin/bash
set -e

echo "=== Centromex WooCommerce Initialization ==="

# Wait for database to be ready
echo "Waiting for database..."
until wp db check --allow-root 2>/dev/null; do
    sleep 2
done

echo "Database is ready!"

# Check if WordPress is already installed
if ! wp core is-installed --allow-root 2>/dev/null; then
    echo "Installing WordPress..."
    wp core install \
        --url="${WORDPRESS_URL:-http://localhost:8080}" \
        --title="Centromex Grocery Store" \
        --admin_user="${WP_ADMIN_USER:-admin}" \
        --admin_password="${WP_ADMIN_PASSWORD:-changeme123}" \
        --admin_email="${WP_ADMIN_EMAIL:-admin@centromex.org}" \
        --locale="es_ES" \
        --allow-root
fi

# Install and activate WooCommerce
echo "Installing WooCommerce..."
wp plugin install woocommerce --activate --allow-root || true

# Install Stripe payment gateway for WooCommerce
echo "Installing Stripe Gateway..."
wp plugin install woocommerce-gateway-stripe --activate --allow-root || true

# Install Spanish language packs
echo "Installing Spanish language..."
wp language core install es_ES --allow-root || true
wp language plugin install woocommerce es_ES --allow-root || true
wp site switch-language es_ES --allow-root || true

# Install WPML or Polylang for multilingual support (free alternative)
echo "Installing Polylang for multilingual support..."
wp plugin install polylang --activate --allow-root || true

# Install mobile-friendly theme (flavor is responsive and simple)
echo "Installing mobile-friendly theme..."
wp theme install flavor --activate --allow-root || true

# Install additional helpful plugins
echo "Installing additional plugins..."
wp plugin install redis-cache --allow-root || true
wp plugin install wp-super-cache --activate --allow-root || true

# Configure WooCommerce settings
echo "Configuring WooCommerce..."
wp option update woocommerce_currency "USD" --allow-root || true
wp option update woocommerce_currency_pos "left" --allow-root || true
wp option update woocommerce_price_thousand_sep "," --allow-root || true
wp option update woocommerce_price_decimal_sep "." --allow-root || true
wp option update woocommerce_default_country "US:MN" --allow-root || true

# Enable guest checkout for easier ordering
wp option update woocommerce_enable_guest_checkout "yes" --allow-root || true
wp option update woocommerce_enable_checkout_login_reminder "yes" --allow-root || true

# Set up basic pages
echo "Creating WooCommerce pages..."
wp wc --user=admin tool run install_pages --allow-root 2>/dev/null || true

# Create product categories for groceries
echo "Creating grocery categories..."
wp wc product_cat create --name="Frutas y Verduras" --slug="frutas-verduras" --user=admin --allow-root 2>/dev/null || true
wp wc product_cat create --name="Carnes y Proteínas" --slug="carnes-proteinas" --user=admin --allow-root 2>/dev/null || true
wp wc product_cat create --name="Lácteos y Huevos" --slug="lacteos-huevos" --user=admin --allow-root 2>/dev/null || true
wp wc product_cat create --name="Granos y Cereales" --slug="granos-cereales" --user=admin --allow-root 2>/dev/null || true
wp wc product_cat create --name="Enlatados" --slug="enlatados" --user=admin --allow-root 2>/dev/null || true
wp wc product_cat create --name="Bebidas" --slug="bebidas" --user=admin --allow-root 2>/dev/null || true
wp wc product_cat create --name="Productos de Limpieza" --slug="limpieza" --user=admin --allow-root 2>/dev/null || true
wp wc product_cat create --name="Productos Mexicanos" --slug="productos-mexicanos" --user=admin --allow-root 2>/dev/null || true

# Activate the custom Centromex plugin
echo "Activating Centromex Grocery plugin..."
wp plugin activate centromex-grocery --allow-root 2>/dev/null || true

echo "=== Initialization Complete ==="
echo "Admin URL: ${WORDPRESS_URL:-http://localhost:8080}/wp-admin"
echo "Admin User: ${WP_ADMIN_USER:-admin}"
echo "Remember to change your password!"
