# Slack Post - #dev-with-ai

Built something fun this morning - helped a local grocery store set up online ordering. They had no product catalog, just photos of their shelves on their website.

**The pipeline:**

1. **Firecrawl** to scrape all 54 images from their site
2. **Meta SAM-2** on Replicate to segment individual products from shelf photos (3.5s per image, $0.04/run)
3. **Google nano-banana-pro** on Replicate to upscale the rough crops into clean product images
4. **Gemini** to identify each product and generate names/descriptions/categories
5. **Open Food Facts API** to validate identifications - only accept products that match the database (bonus: gives us UPC codes, proper brand names, no duplicates)
6. **Claude Code** to orchestrate the whole thing and generate WooCommerce import CSVs

Total ML cost: $0.28

The Open Food Facts validation was a nice discovery - has great coverage for Hispanic grocery brands (La Costeña: 323 products, Goya: 49, Alpina: 25). Solved the problem of Gemini returning generic names like "fruit-smoothie-drink" or duplicating products.

Screenshots in thread.
