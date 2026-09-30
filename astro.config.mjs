import sitemap from "@astrojs/sitemap";
import { defineConfig } from "astro/config";

// Static files in dist/ are what get deployed to the 20i Linux package.
// No Node server, database, or CMS is required at runtime.
export default defineConfig({
  site: "https://webcocloud.net",
  output: "static",
  trailingSlash: "always",
  integrations: [sitemap()],
  build: {
    format: "directory",
  },
});
