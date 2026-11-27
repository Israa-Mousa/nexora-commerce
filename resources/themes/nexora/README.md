# Nexora storefront theme

A project-local Bagisto theme registered as `nexora` in `config/themes.php`.

- Views live in `resources/themes/nexora/views` and override Shop views by matching their view paths.
- Styling source lives in `resources/themes/nexora/assets/nexora.css`; the served copy is `public/themes/shop/nexora/nexora.css`.
- The theme reuses Bagisto's stock Shop Vite build and Vue app so search, cart, filters, and product interactions keep their upstream behavior.
- Arabic and English copy is selected from the active locale; the layout keeps Bagisto's locale-provided `dir` for RTL/LTR behavior.
- The Nexora mark is cropped from the logo image at https://nexorawebsite-1.onrender.com/.
- The channel remains on its existing theme until Nexora is explicitly activated in the admin.

After editing the stylesheet, run `./build.ps1` from this folder to copy and verify the served CSS asset.

## Beauty & Personal Care edition (Nov 2026)

- Storefront: Women / Men / Kids & Babies. Home page sections (hero, audiences, shop by category, featured,
  best sellers, promos, new arrivals, special offers, brands) are read live from Bagisto data —
  categories by slug, products through `shop.api.products.index` filters (`featured`, `new`,
  `nx_best_seller`, `nx_special_offer`), brands from the built-in `brand` attribute.
- Demo catalog: `database/seeders/NexoraBeautySeeder.php` + `database/seeders/data/nexora-beauty-catalog.json`
  (39 fictional demo products, attribute family `nexora_beauty`). Idempotent; adds only what is missing.
- Artwork: generated WebP in `public/themes/shop/nexora/beauty/` by
  `database/seeders/data/tools/generate_nexora_images.py`.
- Setup on Windows: `powershell -ExecutionPolicy Bypass -File .\nexora-setup.ps1 -Seed [-ActivateTheme]`
  (mysqldump backup first, then seed, reindex, cache clear).
- Admin: flags "Best Seller" / "Special Offer", "Skin / Hair Type", "Age Group", "Size", "Key Ingredients",
  "How to Use" live in the "Beauty & Personal Care Details" group of the Beauty family.
- Previous theme files are backed up in `storage/app/nexora-backups/20261125-before-beauty/`.
