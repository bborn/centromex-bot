require 'ruby_llm'
require 'ruby_llm/schema'
require 'csv'
require 'dotenv/load'
require 'json'
require 'net/http'
require 'cgi'
require 'openssl'

# Configure Gemini
RubyLLM.configure do |config|
  config.gemini_api_key = ENV['GEMINI_API_KEY'] || "AIzaSyAlIwV9vTQB7UqfWk5duDgP9mXFH18NAwE"
  config.default_model = "gemini-2.5-flash"
end

IMAGE_DIR = "products"
INPUT_CSV = "products.csv"
OUTPUT_CSV = "products_with_prices.csv"

# Schema for price estimation
class PriceEstimation < RubyLLM::Schema
  number :estimated_price_usd, description: "Estimated US retail price based on product type, size, brand"
  string :size, description: "Product size if visible (e.g., '16 oz', '450ml')"
end

# Search UPCitemdb API by product name
def search_upcitemdb(brand, product_name)
  api_key = ENV['UPCITEMDB_API_KEY'] || "fd1e6386029fde4d3c44fb45f5814a4c"
  base_url = api_key ? "https://api.upcitemdb.com/prod/v1/search" : "https://api.upcitemdb.com/prod/trial/search"

  query = CGI.escape("#{brand} #{product_name}".strip)
  url = "#{base_url}?s=#{query}"

  uri = URI(url)
  http = Net::HTTP.new(uri.host, uri.port)
  http.use_ssl = true
  http.verify_mode = OpenSSL::SSL::VERIFY_NONE
  http.ssl_version = :TLSv1_2
  http.open_timeout = 10
  http.read_timeout = 10

  request = Net::HTTP::Get.new(uri.request_uri)
  request['Content-Type'] = 'application/json'
  request['Accept'] = 'application/json'

  if api_key
    request['user_key'] = api_key
    request['key_type'] = '3scale'
  end

  response = http.request(request)
  return nil if response.code == '429'  # Rate limited
  return nil unless response.code == '200'

  data = JSON.parse(response.body)
  items = data["items"]
  return nil if items.nil? || items.empty?

  item = items.find { |i| i["brand"]&.downcase&.include?(brand.downcase) } || items.first

  # Get price from offers or recorded prices
  price = nil
  offers = item["offers"] || []
  if offers.any?
    prices = offers.map { |o| o["price"].to_f }.reject { |p| p <= 0 }
    price = prices.any? ? (prices.sum / prices.length).round(2) : nil
  end

  if price.nil? || price <= 0
    low = item["lowest_recorded_price"].to_f
    high = item["highest_recorded_price"].to_f
    if low > 0 && high > 0
      price = ((low + high) / 2).round(2)
    elsif low > 0
      price = low
    elsif high > 0
      price = high
    end
  end

  { price: price, upc: item["ean"] || item["upc"], brand: item["brand"], name: item["title"] }
rescue => e
  puts "API error: #{e.message}"
  nil
end

# Estimate price from image using Gemini
def estimate_price_from_image(image_path, product_name)
  chat = RubyLLM.chat.with_schema(PriceEstimation)

  prompt = <<~PROMPT
    Look at this product: #{product_name}

    Estimate a reasonable US retail price based on:
    - Product type (juice, yogurt, cream, cheese, meat)
    - Size/weight if visible
    - Brand positioning (premium vs value)

    PRICING GUIDELINES (USD):
    - Small juice/nectar (8-12 oz): $1.00 - $2.00
    - Large juice (32+ oz): $3.00 - $5.00
    - Yogurt drinks: $1.50 - $3.00
    - Cream/Crema (small): $3.00 - $5.00
    - Cream/Crema (large): $5.00 - $8.00
    - Cheese: $4.00 - $8.00
    - Meat/Chorizo: $5.00 - $10.00
  PROMPT

  response = chat.ask(prompt, with: image_path)
  content = response.content

  if content.is_a?(Hash)
    { price: content["estimated_price_usd"] || content[:estimated_price_usd] || 0 }
  else
    { price: 0 }
  end
rescue => e
  puts "Gemini error: #{e.message}"
  { price: 0 }
end

# Main processing
puts "Reading #{INPUT_CSV}..."
rows = CSV.read(INPUT_CSV, headers: true)
puts "Found #{rows.length} products"

api_mode = "paid (2000/day)"
puts "UPCitemdb API: #{api_mode}"
puts ""

updated_rows = []
api_found = 0
gemini_used = 0

rows.each_with_index do |row, idx|
  sku = row["SKU"]
  name = row["Name"]
  current_price = row["Regular price"].to_s.strip
  current_upc = row["UPC"].to_s.strip

  # Extract brand from name (first word typically)
  brand = name.split.first || ""

  print "[#{idx + 1}/#{rows.length}] #{name[0..40]}... "

  # Skip if already has price
  if !current_price.empty? && current_price.to_f > 0
    puts "has price ($#{current_price})"
    updated_rows << row.to_h
    next
  end

  image_path = File.join(IMAGE_DIR, "#{sku}.jpg")
  price = nil
  upc = current_upc

  # Try UPCitemdb API first
  result = search_upcitemdb(brand, name)
  if result && result[:price] && result[:price] > 0
    price = result[:price]
    upc = result[:upc] if result[:upc] && upc.empty?
    puts "UPC: $#{price}"
    api_found += 1
  elsif File.exist?(image_path)
    # Fall back to Gemini estimate
    estimate = estimate_price_from_image(image_path, name)
    price = estimate[:price]
    puts "Gemini: $#{'%.2f' % price}"
    gemini_used += 1
  else
    puts "no image, no price"
  end

  new_row = row.to_h
  new_row["Regular price"] = price && price > 0 ? sprintf("%.2f", price) : ""
  new_row["UPC"] = upc unless upc.empty?
  updated_rows << new_row

  sleep 0.3  # Small delay between requests
end

# Write output
puts "\nWriting #{OUTPUT_CSV}..."
CSV.open(OUTPUT_CSV, "wb") do |csv|
  csv << rows.headers
  updated_rows.each { |row| csv << rows.headers.map { |h| row[h] } }
end

# Summary
with_price = updated_rows.count { |r| r["Regular price"].to_s.strip != "" && r["Regular price"].to_f > 0 }
with_upc = updated_rows.count { |r| r["UPC"].to_s.strip != "" }

puts "\nDone!"
puts "  Products with prices: #{with_price}/#{updated_rows.length}"
puts "  Products with UPC: #{with_upc}/#{updated_rows.length}"
puts "  Prices from UPCitemdb: #{api_found}"
puts "  Prices from Gemini: #{gemini_used}"
puts "  Output: #{OUTPUT_CSV}"
