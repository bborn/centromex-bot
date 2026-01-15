# Tweet Thread

**Tweet 1:**
A lot of folks here in Minneapolis/St. Paul are feeling unsafe going to the grocery store right now.

I got in touch with a local Hispanic grocery who told me they really needed help setting up online ordering so volunteers could do delivery.

So I built it this morning.

[attach: grocery-store-shelves.jpg]

**Tweet 2:**
The problem: they have hundreds of products but no product catalog. No SKUs, no individual product images, nothing ready for e-commerce.

But they did have photos of their shelves on their website.

**Tweet 3:**
Step 1: @fiaborgs to crawl their existing site and pull down all 54 images.

[attach: firecrawl-image-extraction.png]

**Tweet 4:**
Step 2: @replaborgs running Meta's Segment Anything 2 to detect and cut out every individual product from the shelf photos.

3.5 seconds per image. $0.04 per run.

[attach: replicate-sam2-interface.png]

**Tweet 5:**
Step 3: The cropped segments look rough, so we run them through Google's nano-banana-pro on Replicate to upscale them into clean product photos.

[attach: nano-banana-upscaling.png]

**Tweet 6:**
Step 4: Claude Code orchestrates the whole pipeline - calling Gemini to identify each product, generating WooCommerce-ready CSVs with names, descriptions, categories, and prices.

[attach: code-image-format-change.png]

**Tweet 7:**
But generic names like "fruit-smoothie-drink" aren't useful. And we were getting duplicates.

I asked Claude: is there an API for Hispanic grocery products we can validate against?

[attach: claude-validation-discussion.png]

**Tweet 8:**
Claude found Open Food Facts - an open database with excellent coverage:
- Goya: 49 products
- La Costeña: 323 products
- Alpina: 25 products

Now we validate every detection against the database. Only real products make it through.

[attach: open-food-facts-strategy.png]

**Tweet 9:**
Total cost for all the ML: $0.28

Total time: one morning

Now they have online ordering with a real product catalog.

[attach: replicate-dashboard-costs.png]

**Tweet 10:**
This is what AI tools are for. Not replacing people - helping them when they need it most.
