require 'csv'

IMAGE_DIR = "products"

# Generate CSV from existing product images
headers = ["Name", "SKU", "Description", "Regular price", "Categories", "Images", "Published", "Type", "UPC", "Status"]

rows = []

Dir.glob("#{IMAGE_DIR}/*.jpg").sort.each do |image_path|
  filename = File.basename(image_path)
  sku = filename.sub(/\.jpg$/, '')

  # Convert SKU to readable name
  name = sku.split('-').map(&:capitalize).join(' ')

  # Determine status based on patterns (verified brands we know matched)
  verified_brands = ['el-mexicano', 'alpina', 'goya', 'danone', 'lala', 'quaker']
  status = verified_brands.any? { |b| sku.start_with?(b) } ? 'verified' : 'needs_review'

  rows << [
    name,
    sku,
    "",  # Description
    "",  # Price
    "",  # Categories
    filename,
    status == 'verified' ? 1 : 0,
    "simple",
    "",  # UPC
    status
  ]
end

CSV.open("products.csv", "wb") do |csv|
  csv << headers
  rows.each { |row| csv << row }
end

puts "Generated products.csv with #{rows.size} products"
puts "  Verified: #{rows.count { |r| r[9] == 'verified' }}"
puts "  Needs review: #{rows.count { |r| r[9] == 'needs_review' }}"
