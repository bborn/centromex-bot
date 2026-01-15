require 'ruby_llm'
require 'ruby_llm/schema'
require 'csv'
require 'json'
require 'net/http'
require 'cgi'
require 'openssl'

# Configure Gemini
RubyLLM.configure do |config|
  config.gemini_api_key = 'AIzaSyAlIwV9vTQB7UqfWk5duDgP9mXFH18NAwE'
  config.default_model = 'gemini-2.5-flash'
end

IMAGE_DIR = 'products'

class PriceEstimation < RubyLLM::Schema
  number :estimated_price_usd, description: 'Estimated US retail price based on product type, size, brand'
  string :size, description: 'Product size if visible (e.g., 16 oz, 450ml)'
end

def search_upcitemdb(brand, product_name)
  api_key = 'fd1e6386029fde4d3c44fb45f5814a4c'
  base_url = 'https://api.upcitemdb.com/prod/v1/search'

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
  request['user_key'] = api_key
  request['key_type'] = '3scale'

  response = http.request(request)
  return nil unless response.code == '200'

  data = JSON.parse(response.body)
  items = data['items']
  return nil if items.nil? || items.empty?

  item = items.find { |i| i['brand']&.downcase&.include?(brand.downcase) } || items.first

  price = nil
  low = item['lowest_recorded_price'].to_f
  high = item['highest_recorded_price'].to_f
  if low > 0 && high > 0
    price = ((low + high) / 2).round(2)
  elsif low > 0
    price = low
  elsif high > 0
    price = high
  end

  { price: price, upc: item['ean'] || item['upc'], brand: item['brand'], name: item['title'] }
rescue => e
  puts "API error: #{e.message}"
  nil
end

def estimate_price_from_image(image_path, product_name)
  chat = RubyLLM.chat.with_schema(PriceEstimation)

  prompt = <<~PROMPT
    Look at this product: #{product_name}

    Estimate a reasonable US retail price based on:
    - Product type (juice, yogurt, cream, cheese, meat, oatmeal)
    - Size/weight if visible
    - Brand positioning (premium vs value)

    PRICING GUIDELINES (USD):
    - Oatmeal/Avena (small): $2.00 - $4.00
    - Oatmeal/Avena (large): $4.00 - $7.00
    - Small juice/nectar (8-12 oz): $1.00 - $2.00
    - Large juice (32+ oz): $3.00 - $5.00
  PROMPT

  response = chat.ask(prompt, with: image_path)
  content = response.content

  if content.is_a?(Hash)
    { price: content['estimated_price_usd'] || content[:estimated_price_usd] || 0 }
  else
    { price: 0 }
  end
rescue => e
  puts "Gemini error: #{e.message}"
  { price: 0 }
end

# Test with 3 products from test_products.csv
rows = CSV.read('test_products.csv', headers: true)
puts "Testing #{rows.length} products\n\n"

rows.each_with_index do |row, idx|
  name = row['Name']
  sku = row['SKU']
  brand = name.split.first

  puts "[#{idx + 1}/#{rows.length}] #{name}"

  # Try UPCitemdb
  result = search_upcitemdb(brand, name)
  if result && result[:price] && result[:price] > 0
    puts "  UPCitemdb: $#{result[:price]} (UPC: #{result[:upc]})"
    puts "  Matched: #{result[:name]}"
  else
    puts "  UPCitemdb: not found"

    # Try Gemini
    image_path = File.join(IMAGE_DIR, "#{sku}.jpg")
    if File.exist?(image_path)
      estimate = estimate_price_from_image(image_path, name)
      puts "  Gemini estimate: $#{'%.2f' % estimate[:price]}"
    else
      puts "  No image available"
    end
  end
  puts
  sleep 0.5
end
