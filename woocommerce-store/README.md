# Centromex Grocery Store

A WooCommerce-based online grocery store for Centromex families in St. Paul, enabling safe and accessible grocery ordering with Spanish language support.

## Features

- **Spanish Language Support**: Bilingual interface (Spanish/English) for all customer-facing pages
- **Mobile-Friendly Design**: Optimized for ordering from mobile devices
- **Stripe Payment Processing**: Secure payment handling via Stripe
- **Custom Delivery Fields**: Preferred delivery time, special instructions, contact phone
- **Staff Order Management**: Custom admin dashboard for managing orders
- **Pre-configured Categories**: Mexican grocery staples and common products

## Quick Start

### Prerequisites

- Docker and Docker Compose
- A domain name (for production) or localhost (for development)

### Development Setup

1. Clone this repository
2. Copy the environment file:
   ```bash
   cp .env.example .env
   ```

3. Edit `.env` with your configuration

4. Start the services:
   ```bash
   docker-compose up -d
   ```

5. Access the store at http://localhost:8080

### Default Admin Credentials

- URL: http://localhost:8080/wp-admin
- User: admin
- Password: changeme123 (change this immediately!)

## Stripe Configuration

After the store is running:

1. Log in to WordPress admin
2. Go to **WooCommerce > Settings > Payments**
3. Click **Stripe** and enable it
4. Enter your Stripe API keys:
   - For testing: Use `pk_test_...` and `sk_test_...`
   - For production: Use `pk_live_...` and `sk_live_...`
5. Save changes

### Getting Stripe Keys

1. Go to https://dashboard.stripe.com/
2. Create an account or log in
3. Go to Developers > API Keys
4. Copy your Publishable key and Secret key

## Adding Products

### Via Admin Panel

1. Go to **Products > Add New**
2. Enter product name (Spanish is recommended for the primary audience)
3. Set the price
4. Choose a category (Frutas y Verduras, Carnes, Lácteos, etc.)
5. Add a product image
6. Click Publish

### Bulk Import

Use WooCommerce's built-in CSV import:

1. Go to **Products > All Products**
2. Click **Import**
3. Upload the sample CSV from `data/sample-products.csv`
4. Map the columns and import

## Pre-configured Categories

- **Frutas y Verduras** - Fruits and Vegetables
- **Carnes y Proteínas** - Meats and Proteins
- **Lácteos y Huevos** - Dairy and Eggs
- **Granos y Cereales** - Grains and Cereals
- **Enlatados** - Canned Goods
- **Bebidas** - Beverages
- **Productos de Limpieza** - Cleaning Products
- **Productos Mexicanos** - Mexican Products

## Order Management

Staff can manage orders via:

1. **Custom Dashboard**: Admin menu > Centromex Orders
   - View pending orders
   - See delivery instructions
   - Quick access to order details

2. **WooCommerce Orders**: WooCommerce > Orders
   - Full order management
   - Status updates
   - Customer communication

## Deployment to sprites.dev

1. SSH into your sprites.dev server

2. Clone/upload this directory to the server

3. Set up the environment:
   ```bash
   cp .env.example .env
   nano .env  # Configure with production values
   ```

4. Update `WORDPRESS_URL` in `.env` to your domain

5. Deploy:
   ```bash
   ./deploy.sh
   ```

6. Set up SSL/HTTPS (recommended):
   - Use a reverse proxy like nginx or Traefik
   - Configure Let's Encrypt for free SSL

## File Structure

```
woocommerce-store/
├── docker-compose.yml      # Docker services configuration
├── Dockerfile.wordpress    # Custom WordPress image
├── deploy.sh               # Deployment script
├── .env.example            # Environment template
├── config/
│   ├── uploads.ini         # PHP upload configuration
│   └── mysql.cnf           # MySQL configuration
├── scripts/
│   ├── docker-entrypoint.sh    # Container entrypoint
│   └── init-wordpress.sh       # WordPress initialization
├── wp-content/
│   ├── plugins/
│   │   └── centromex-grocery/  # Custom plugin
│   │       ├── centromex-grocery.php
│   │       ├── assets/css/style.css
│   │       ├── templates/admin-orders.php
│   │       └── languages/
│   └── themes/                 # Theme customizations
└── data/
    └── sample-products.csv     # Sample product import
```

## Customization

### Changing Colors

Edit `wp-content/plugins/centromex-grocery/assets/css/style.css`:

```css
:root {
    --centromex-primary: #2c5f2d;    /* Main green color */
    --centromex-secondary: #97bc62;  /* Light green accent */
}
```

### Adding More Languages

The store uses WordPress's built-in language system. The Polylang plugin is pre-installed for additional multilingual features.

## Troubleshooting

### Container won't start
```bash
docker-compose logs wordpress
docker-compose logs db
```

### Database connection errors
Ensure the MySQL container is running and healthy:
```bash
docker-compose ps
docker-compose logs db
```

### Plugin not appearing
Check WordPress can access the plugin directory:
```bash
docker-compose exec wordpress ls -la /var/www/html/wp-content/plugins/
```

## Security Notes

- Change the default admin password immediately after setup
- Use strong, unique passwords for database and admin accounts
- Enable SSL/HTTPS for production
- Keep WordPress and plugins updated
- Consider adding security plugins like Wordfence

## Support

For issues with this store setup:
- Open an issue in the repository
- Contact the Centromex volunteer coordinators

## License

This project is open source and available under the MIT License.
