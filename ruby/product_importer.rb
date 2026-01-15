require 'ruby_llm'
require 'ruby_llm/schema'
require 'csv'
require 'dotenv/load'
require 'mini_magick'
require 'fileutils'
require 'replicate'
require 'base64'
require 'open-uri'
require 'json'
require 'net/http'
require 'cgi'
require 'set'
require 'openssl'
require 'concurrent'

# --- Configuration ---
RubyLLM.configure do |config|
  config.gemini_api_key = ENV['GEMINI_API_KEY'] || "AIzaSyAlIwV9vTQB7UqfWk5duDgP9mXFH18NAwE"
  config.default_model = "gemini-2.5-flash"
end

Replicate.configure do |config|
  config.api_token = ENV['REPLICATE_API_TOKEN']
end

IMAGE_OUTPUT_DIR = "products"
FileUtils.mkdir_p(IMAGE_OUTPUT_DIR)

# Thread pool for parallel upscaling
UPSCALE_POOL = Concurrent::FixedThreadPool.new(4)
UPSCALE_FUTURES = Concurrent::Array.new

# --- Schema for identifying a product (focused on brand + name) ---
class ProductIdentification < RubyLLM::Schema
  string :brand, description: "The brand name exactly as shown on the packaging (e.g., 'Goya', 'La Costeña', 'Alpina', 'El Mexicano')"
  string :product_name, description: "The specific product name (e.g., 'Mango Nectar', 'Crema Mexicana', 'Avena Original')"
  string :full_name, description: "Brand + product name combined (e.g., 'Goya Mango Nectar')"
  boolean :is_product, description: "True if you can clearly read a brand name and product name. False if unclear, partial, or not a product."
  string :product_type, description: "Type: 'packaged' for bottles/cans/boxes, 'produce' for fresh fruits/vegetables, 'meat' for meat products, 'bakery' for bread/tortillas"
  string :size, description: "Product size/weight if visible on label (e.g., '16 oz', '450ml', '1 lb', '500g')"
  number :estimated_price_usd, description: "Estimated US retail price based on product type, size, and brand. Yogurt drinks: $2-4, Juice/nectar: $1-3, Cream: $4-6, Cheese: $5-8, Meat: $6-12"
end

# --- Helper: Search UPCitemdb API by product name ---
def search_upcitemdb(brand, product_name)
  # Use trial endpoint (100 requests/day free, no key needed)
  # Or paid endpoint with key: https://api.upcitemdb.com/prod/v1/search
  api_key = ENV['UPCITEMDB_API_KEY'] || "fd1e6386029fde4d3c44fb45f5814a4c"

  base_url = api_key ? "https://api.upcitemdb.com/prod/v1/search" : "https://api.upcitemdb.com/prod/trial/search"
  query = CGI.escape("#{brand} #{product_name}".strip)
  url = "#{base_url}?s=#{query}"

  uri = URI(url)
  http = Net::HTTP.new(uri.host, uri.port)
  http.use_ssl = true
  http.verify_mode = OpenSSL::SSL::VERIFY_NONE  # Skip SSL verification
  http.open_timeout = 10
  http.read_timeout = 10

  request = Net::HTTP::Get.new(uri.request_uri)
  request['Content-Type'] = 'application/json'
  request['Accept'] = 'application/json'
  request['Accept-Encoding'] = 'gzip,deflate'

  if api_key
    request['user_key'] = api_key
    request['key_type'] = '3scale'
  end

  response = http.request(request)

  # Handle rate limiting
  if response.code == '429'
    puts "rate limited"
    return nil
  end

  return nil unless response.code == '200'

  data = JSON.parse(response.body)
  items = data["items"]
  return nil if items.nil? || items.empty?

  # Find best match - prefer exact brand match
  item = items.find { |i| i["brand"]&.downcase&.include?(brand.downcase) } || items.first

  # Get price from offers or recorded prices
  price = nil
  offers = item["offers"] || []
  if offers.any?
    prices = offers.map { |o| o["price"].to_f }.reject { |p| p <= 0 }
    price = prices.any? ? (prices.sum / prices.length).round(2) : nil
  end

  # Fall back to lowest/highest recorded price
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

  {
    found: true,
    name: item["title"],
    brand: item["brand"],
    category: item["category"],
    description: item["description"],
    upc: item["ean"] || item["upc"],
    price: price
  }
rescue => e
  puts "error: #{e.message}"
  nil
end

# --- Helper: Search Open Food Facts ---
def search_open_food_facts(query)
  return nil if query.nil? || query.strip.empty?

  encoded_query = CGI.escape(query.strip)
  url = "https://world.openfoodfacts.org/cgi/search.pl?search_terms=#{encoded_query}&json=1&page_size=5"

  uri = URI(url)
  http = Net::HTTP.new(uri.host, uri.port)
  http.use_ssl = true
  http.verify_mode = OpenSSL::SSL::VERIFY_NONE  # Skip SSL verification
  http.open_timeout = 10
  http.read_timeout = 10

  request = Net::HTTP::Get.new(uri.request_uri)
  request['User-Agent'] = 'ProductImporter/1.0'

  response = http.request(request)
  data = JSON.parse(response.body)

  products = data["products"] || []
  return nil if products.empty?

  # Find best match - prefer exact brand match
  products.first
rescue => e
  puts "    Open Food Facts error: #{e.message}"
  nil
end

# --- Schema for LLM validation of Open Food Facts match ---
class ProductMatchValidation < RubyLLM::Schema
  boolean :is_match, description: "True if the detected product and Open Food Facts product are the SAME product (same brand AND same product type)"
  string :reason, description: "Brief explanation of why this is or is not a match"
end

# --- Helper: Use LLM to validate if detected product matches OFF result ---
def llm_validate_match(detected_brand, detected_name, off_brand, off_name)
  return false if detected_brand.nil? || off_brand.nil?

  chat = RubyLLM.chat.with_schema(ProductMatchValidation)

  prompt = <<~PROMPT
    I detected a product from an image and found a potential match in a database.
    Determine if these are the SAME product.

    DETECTED FROM IMAGE:
    - Brand: "#{detected_brand}"
    - Product: "#{detected_name}"

    DATABASE RESULT:
    - Brand: "#{off_brand}"
    - Product: "#{off_name}"

    RULES:
    - The brands must be the same company (exact match or known variant)
    - The product type must match (both creams, both yogurts, etc.)
    - Minor spelling differences are OK (e.g., "Goya" vs "GOYA")
    - Different products from the same brand are NOT a match
    - If the database brand is completely different, it's NOT a match

    Examples:
    - "El Mexicano" + "Crema" vs "El Mexicano" + "Sour Cream" = MATCH (same brand, same product type)
    - "Del Prado" + "Crema" vs "Verduras Curro" + "Remolacha" = NOT MATCH (different brands, different products)
    - "Saborico" + "Yogurt" vs "gullón" + "Sandwich" = NOT MATCH (different brands, different products)
  PROMPT

  response = chat.ask(prompt)
  content = response.content

  if content.is_a?(Hash)
    is_match = content["is_match"] || content[:is_match] || false
    reason = content["reason"] || content[:reason] || ""
    puts " #{is_match ? 'MATCH' : 'NO MATCH'}: #{reason}"
    return is_match
  end

  false
rescue => e
  puts " LLM validation error: #{e.message}"
  false
end

# --- Helper: Validate product against Open Food Facts ---
def validate_with_open_food_facts(brand, product_name)
  # Try the full search first
  query = "#{brand} #{product_name}".strip
  result = search_open_food_facts(query)

  if result
    off_brand = result["brands"]
    off_name = result["product_name"] || result["product_name_en"]

    # Use LLM to validate the match
    print " checking '#{off_brand}' / '#{off_name}'..."
    if llm_validate_match(brand, product_name, off_brand, off_name)
      return {
        found: true,
        off_name: off_name,
        off_brand: off_brand,
        off_categories: result["categories"],
        off_code: result["code"],
        off_image: result["image_url"]
      }
    end
  end

  sleep 0.2  # Rate limit

  # Try brand-only search
  result = search_open_food_facts(brand)

  if result
    off_brand = result["brands"]
    off_name = result["product_name"] || result["product_name_en"]

    print " checking '#{off_brand}' / '#{off_name}'..."
    if llm_validate_match(brand, product_name, off_brand, off_name)
      return {
        found: true,
        off_name: off_name,
        off_brand: off_brand,
        off_categories: result["categories"],
        off_code: result["code"],
        off_image: result["image_url"]
      }
    end
  end

  { found: false }
end

# --- Helper: Run single DINO query ---
def run_dino_query(base64_image, query, box_thresh = 0.15, text_thresh = 0.15)
  model = Replicate.client.retrieve_model("adirik/grounding-dino")
  version = model.latest_version

  prediction = version.predict(
    image: base64_image,
    query: query,
    box_threshold: box_thresh,
    text_threshold: text_thresh,
    show_visualisation: false
  )

  while prediction.status != 'succeeded' && prediction.status != 'failed'
    sleep 1
    prediction.refetch
  end

  return [] if prediction.status == 'failed'
  prediction.output["detections"] || []
end

# --- Helper: Detect products using Grounding DINO ---
def detect_products_with_dino(image_path)
  puts "  -> Running Grounding DINO detection..."

  image_data = File.read(image_path)
  mime_type = case File.extname(image_path).downcase
              when '.png' then 'image/png'
              when '.webp' then 'image/webp'
              else 'image/jpeg'
              end
  base64_image = "data:#{mime_type};base64,#{Base64.strict_encode64(image_data)}"

  all_detections = []

  queries = [
    "bottle . jar . carton . can . box . package",
    "tomato . lime . pepper . avocado . onion . lettuce . cucumber . chili",
    "chicken . meat . sausage . chorizo",
    "bread . tortilla . pan dulce . pastry",
    "yogurt . cream . cheese . milk"
  ]

  queries.each_with_index do |query, i|
    print "    Query #{i + 1}/#{queries.length}..."
    detections = run_dino_query(base64_image, query, 0.15, 0.15)
    puts " #{detections.length} found"
    all_detections.concat(detections)
  end

  unique_detections = remove_duplicate_boxes(all_detections)
  { "detections" => unique_detections }
end

# --- Helper: Remove overlapping bounding boxes ---
def remove_duplicate_boxes(detections, iou_threshold = 0.5)
  return detections if detections.empty?

  sorted = detections.sort_by { |d| -(d["confidence"] || 0) }

  kept = []
  sorted.each do |det|
    dominated = kept.any? { |k| box_iou(det["bbox"], k["bbox"]) > iou_threshold }
    kept << det unless dominated
  end

  kept
end

# --- Helper: Calculate IoU between two boxes ---
def box_iou(box1, box2)
  x1 = [box1[0], box2[0]].max
  y1 = [box1[1], box2[1]].max
  x2 = [box1[2], box2[2]].min
  y2 = [box1[3], box2[3]].min

  inter_area = [[x2 - x1, 0].max * [y2 - y1, 0].max, 0].max

  box1_area = (box1[2] - box1[0]) * (box1[3] - box1[1])
  box2_area = (box2[2] - box2[0]) * (box2[3] - box2[1])

  union_area = box1_area + box2_area - inter_area

  return 0 if union_area <= 0
  inter_area.to_f / union_area
end

# --- Helper: Upscale image using Nano Banana Pro (async) ---
def upscale_product_image_async(image_path, product_description)
  future = Concurrent::Future.execute(executor: UPSCALE_POOL) do
    upscale_product_image_sync(image_path, product_description)
  end
  UPSCALE_FUTURES << future
  future
end

def upscale_product_image_sync(image_path, product_description)
  image_data = File.read(image_path)
  mime_type = case File.extname(image_path).downcase
              when '.png' then 'image/png'
              when '.webp' then 'image/webp'
              else 'image/jpeg'
              end
  base64_image = "data:#{mime_type};base64,#{Base64.strict_encode64(image_data)}"

  model = Replicate.client.retrieve_model("google/nano-banana-pro")
  version = model.latest_version

  prediction = version.predict(
    prompt: "Product photo of #{product_description}, clean white background, professional product photography",
    image_input: [base64_image],
    aspect_ratio: "1:1",
    resolution: "1K",
    output_format: "jpg"
  )

  while prediction.status != 'succeeded' && prediction.status != 'failed'
    sleep 2
    prediction.refetch
  end

  if prediction.status == 'failed'
    puts "    [#{File.basename(image_path)}] Upscale failed"
    return image_path
  end

  # Download the upscaled image
  output_url = prediction.output
  if output_url.is_a?(Array)
    output_url = output_url.first
  end

  return image_path unless output_url

  upscaled_path = image_path.sub(/\.(jpg|jpeg|png)$/i, '_upscaled.jpg')

  URI.open(output_url) do |remote|
    File.open(upscaled_path, 'wb') do |file|
      file.write(remote.read)
    end
  end

  # Replace original with upscaled
  FileUtils.mv(upscaled_path, image_path)
  puts "    [#{File.basename(image_path)}] Upscaled"
  image_path
rescue => e
  puts "    [#{File.basename(image_path)}] Upscale error: #{e.message}"
  image_path
end

# --- Helper: Crop product from image ---
def crop_product(image_path, bbox, index)
  image = MiniMagick::Image.open(image_path)
  img_width = image.width
  img_height = image.height

  x1, y1, x2, y2 = bbox.map(&:to_i)

  padding = 5
  x1 = [x1 - padding, 0].max
  y1 = [y1 - padding, 0].max
  x2 = [x2 + padding, img_width].min
  y2 = [y2 + padding, img_height].min

  crop_w = x2 - x1
  crop_h = y2 - y1

  return nil if crop_w < 30 || crop_h < 30

  crop_geometry = "#{crop_w}x#{crop_h}+#{x1}+#{y1}"

  output_path = "/tmp/product_crop_#{index}.png"
  image.crop(crop_geometry)
  image.write(output_path)

  output_path
end

# --- Helper: Identify product using Gemini (focused on brand extraction) ---
def identify_product(image_path)
  chat = RubyLLM.chat.with_schema(ProductIdentification)

  prompt = <<~PROMPT
    Look at this product image carefully. Your job is to READ THE LABEL and extract product information.

    CRITICAL: You must identify:
    1. BRAND NAME - exactly as shown on the packaging
    2. PRODUCT NAME - the specific product
    3. SIZE - weight or volume if visible (e.g., "16 oz", "450ml")
    4. BARCODE/UPC - if you can see a barcode number (12-13 digits), read it
    5. ESTIMATED PRICE - estimate a reasonable US retail price

    Examples:
    - Brand: "Goya", Product: "Mango Nectar", Size: "9.6 oz", Price: $1.49
    - Brand: "El Mexicano", Product: "Crema Mexicana", Size: "15 oz", Price: $4.99
    - Brand: "Alpina", Product: "Avena Original", Size: "250ml", Price: $2.29

    PRICING GUIDELINES (USD):
    - Small juice/nectar bottles (8-12 oz): $1.00 - $2.00
    - Large juice bottles (32+ oz): $3.00 - $5.00
    - Yogurt drinks (individual): $1.50 - $3.00
    - Cream/Crema (small): $3.00 - $5.00
    - Cream/Crema (large): $5.00 - $8.00
    - Cheese: $4.00 - $8.00
    - Meat/Chorizo: $5.00 - $10.00

    For fresh produce: Brand="Fresh", product_type="produce"

    RULES:
    - If you cannot clearly read a brand name, set is_product=false
    - Look carefully for the UPC barcode number - it's usually near the barcode lines
    - Estimate price based on product type, size, and brand positioning
  PROMPT

  response = chat.ask(prompt, with: image_path)
  content = response.content

  if content.is_a?(Hash)
    {
      brand: content["brand"] || content[:brand] || "",
      product_name: content["product_name"] || content[:product_name] || "",
      full_name: content["full_name"] || content[:full_name] || "",
      is_product: content["is_product"] || content[:is_product] || false,
      product_type: content["product_type"] || content[:product_type] || "packaged",
      size: content["size"] || content[:size] || "",
      estimated_price: content["estimated_price_usd"] || content[:estimated_price_usd] || 0
    }
  else
    { is_product: false }
  end
rescue => e
  puts "    Gemini error: #{e.message}"
  { is_product: false }
end

# --- Helper: Load existing CSV for idempotency ---
def load_existing_products(csv_path)
  existing = { skus: Set.new, upcs: Set.new }
  return existing unless File.exist?(csv_path)

  CSV.foreach(csv_path, headers: true) do |row|
    existing[:skus].add(row["SKU"]) if row["SKU"]
    existing[:upcs].add(row["UPC"]) if row["UPC"] && !row["UPC"].empty?
  end

  puts "Loaded #{existing[:skus].size} existing products from #{csv_path}"
  existing
end

# --- Main Processing Loop ---
def process_images(folder_path, output_csv)
  headers = ["Name", "SKU", "Description", "Regular price", "Categories", "Images", "Published", "Type", "UPC", "Status"]

  # Load existing products for idempotency
  existing = load_existing_products(output_csv)
  seen_skus = existing[:skus].dup
  seen_upcs = existing[:upcs].dup

  verified_count = 0
  review_count = 0
  skipped_count = 0
  new_rows = []

  Dir.glob("#{folder_path}/*.{jpg,jpeg,png,webp}").each do |image_path|
    puts "\n=== Processing: #{File.basename(image_path)} ==="

    begin
      dino_output = detect_products_with_dino(image_path)
      detections = dino_output["detections"] || []
      puts "  -> Found #{detections.length} potential products"

      detections.each_with_index do |detection, idx|
        bbox = detection["bbox"] || detection[:bbox] || detection
        confidence = detection["confidence"] || 0
        label = detection["label"] || "product"

        puts "  -> Detection #{idx + 1}: #{label} (#{(confidence * 100).round}%)"

        # Crop
        crop_path = crop_product(image_path, bbox, idx)
        next unless crop_path

        # Identify with Gemini
        print "    Identifying... "
        product_info = identify_product(crop_path)

        unless product_info[:is_product]
          puts "not a product"
          File.delete(crop_path) if File.exist?(crop_path)
          next
        end

        brand = product_info[:brand].to_s.strip
        product_name = product_info[:product_name].to_s.strip
        full_name = product_info[:full_name].to_s.strip

        if brand.empty? || product_name.empty?
          puts "missing brand/name"
          File.delete(crop_path) if File.exist?(crop_path)
          next
        end

        puts "#{brand} - #{product_name}"
        size = product_info[:size].to_s.strip
        gemini_price = product_info[:estimated_price] || 0

        puts "    Size: #{size}, Est. price: $#{'%.2f' % gemini_price}"

        # Generate base SKU
        base_sku = "#{brand}-#{product_name}".downcase.gsub(/[^a-z0-9]+/, '-').gsub(/-+/, '-').gsub(/^-|-$/, '')[0..50]
        sku = base_sku

        # Check idempotency - skip if already processed
        if seen_skus.include?(sku) || seen_skus.include?("#{sku}-#{idx}")
          puts "    SKIPPED (already processed)"
          File.delete(crop_path) if File.exist?(crop_path)
          skipped_count += 1
          next
        end

        # Skip produce for now
        if product_info[:product_type] == "produce"
          puts "    Skipping produce"
          File.delete(crop_path) if File.exist?(crop_path)
          next
        end

        # Initialize product data
        status = "needs_review"
        upc = ""
        categories = ""
        final_name = full_name
        final_brand = brand
        price = gemini_price
        description = ""

        # Try UPCitemdb API first (searches by product name, 100 free/day)
        print "    UPCitemdb... "
        upc_data = search_upcitemdb(brand, product_name)
        if upc_data && upc_data[:found]
          puts "FOUND (#{upc_data[:brand]}, $#{upc_data[:price] || 'N/A'})"
          status = "verified"
          upc = upc_data[:upc] || ""
          final_name = upc_data[:name] || full_name
          final_brand = upc_data[:brand] || brand
          categories = upc_data[:category] || ""
          description = upc_data[:description] || ""
          price = upc_data[:price] if upc_data[:price] && upc_data[:price] > 0
        else
          puts "not found"
        end

        # If UPCitemdb failed, try Open Food Facts (free, unlimited)
        if status == "needs_review"
          print "    Open Food Facts... "
          validation = validate_with_open_food_facts(brand, product_name)

          if validation[:found]
            upc = validation[:off_code] || upc
            puts "VERIFIED"
            status = "verified"
            final_name = validation[:off_name] || full_name
            final_brand = validation[:off_brand] || brand
            categories = validation[:off_categories] || categories
          else
            puts "NOT IN DATABASE - marking for review"
          end
        end

        # Check for duplicate UPC
        if !upc.empty? && seen_upcs.include?(upc)
          puts "    DUPLICATE UPC (#{upc})"
          File.delete(crop_path) if File.exist?(crop_path)
          next
        end
        seen_upcs.add(upc) unless upc.empty?

        # Update SKU with final name
        sku = "#{final_brand}-#{final_name}".downcase.gsub(/[^a-z0-9]+/, '-').gsub(/-+/, '-').gsub(/^-|-$/, '')[0..50]

        # Ensure unique SKU
        if seen_skus.include?(sku)
          sku = "#{sku}-#{idx}"
        end
        seen_skus.add(sku)

        # Save image
        final_path = File.join(IMAGE_OUTPUT_DIR, "#{sku}.jpg")
        FileUtils.mv(crop_path, final_path)

        # Queue async upscale
        puts "    Queuing upscale..."
        upscale_product_image_async(final_path, "#{final_brand} #{final_name}")

        if status == "verified"
          verified_count += 1
        else
          review_count += 1
        end

        # Format price for CSV (blank if 0)
        price_str = price > 0 ? sprintf("%.2f", price) : ""

        new_rows << [
          "#{final_brand} #{final_name}".strip,
          sku,
          description,
          price_str,
          categories,
          "#{sku}.jpg",
          status == "verified" ? 1 : 0,  # Only publish verified
          "simple",
          upc,
          status
        ]
      end

    rescue => e
      puts "Error: #{e.message}"
      puts e.backtrace.first(3).join("\n")
    end
  end

  # Wait for all upscales to complete
  puts "\n=== Waiting for #{UPSCALE_FUTURES.size} upscales to complete... ==="
  UPSCALE_FUTURES.each(&:wait)
  puts "All upscales complete!"

  # Write CSV (append to existing or create new)
  if File.exist?(output_csv) && existing[:skus].any?
    # Append new rows
    CSV.open(output_csv, "a") do |csv|
      new_rows.each { |row| csv << row }
    end
  else
    # Create new file with headers
    CSV.open(output_csv, "wb") do |csv|
      csv << headers
      new_rows.each { |row| csv << row }
    end
  end

  puts "\n" + "=" * 50
  puts "RESULTS:"
  puts "  New verified: #{verified_count}"
  puts "  New needs review: #{review_count}"
  puts "  Skipped (already processed): #{skipped_count}"
  puts "  Total in CSV: #{seen_skus.size}"
  puts "  Images: #{IMAGE_OUTPUT_DIR}/"
  puts "  CSV: #{output_csv}"
  puts "=" * 50
end

# --- Execution ---
folder = ARGV[0] || "shelf_images"
output = ARGV[1] || "woo_import_with_images.csv"

unless ENV['REPLICATE_API_TOKEN']
  puts "Error: REPLICATE_API_TOKEN not set"
  exit 1
end

unless Dir.exist?(folder)
  puts "Error: Input folder '#{folder}' does not exist."
  exit 1
end

process_images(folder, output)
