#!/bin/bash
#
# Centromex WooCommerce Deployment Script
# Deploy to sprites.dev or any Docker-compatible host
#

set -e

echo "=== Centromex WooCommerce Deployment ==="

# Check for required environment
if [ ! -f .env ]; then
    echo "Error: .env file not found. Copy .env.example to .env and configure it."
    exit 1
fi

# Load environment variables
source .env

# Build and start containers
echo "Building Docker images..."
docker-compose build

echo "Starting services..."
docker-compose up -d

# Wait for services to be ready
echo "Waiting for services to start..."
sleep 30

# Check if services are running
echo "Checking service health..."
docker-compose ps

# Get the WordPress container ID
WP_CONTAINER=$(docker-compose ps -q wordpress)

if [ -z "$WP_CONTAINER" ]; then
    echo "Error: WordPress container not found"
    exit 1
fi

echo ""
echo "=== Deployment Complete ==="
echo ""
echo "Your Centromex WooCommerce store is now running!"
echo ""
echo "Frontend URL: ${WORDPRESS_URL:-http://localhost:8080}"
echo "Admin URL: ${WORDPRESS_URL:-http://localhost:8080}/wp-admin"
echo ""
echo "Next steps:"
echo "1. Log in to WordPress admin"
echo "2. Configure Stripe API keys in WooCommerce > Settings > Payments > Stripe"
echo "3. Add your grocery products"
echo "4. Test the checkout flow"
echo ""
echo "For logs: docker-compose logs -f wordpress"
echo "To stop: docker-compose down"
