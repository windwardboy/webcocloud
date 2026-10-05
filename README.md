# Webco Cloud

Public website for [webcocloud.net](https://webcocloud.net). Static Astro site. The longer-term platform architecture is in `WEBCO-CLOUD-PROJECT-BRIEF.md`. **Client area** opens the Webco customer and project area. **Hosting login** opens the existing HostShop account for older hosting customers.

## Edit

Business facts, prices and links live in `src/config/site.ts`. Leave unknown contact details and company details blank until they are confirmed.

## Build

```bash
npm install
npm run check
npm run build
```

`npm run build` writes the static site to `dist/`. That folder is committed so 20i can deploy it. Point the package document root at `dist`. No Node process or CMS is required on the server.

Domain availability is checked by `domain-search.php` on the server. Draft orders are saved by `draft-order.php` into MySQL. The 20i General API key and the database credentials stay in the private file outside this repository and must not be copied into the site or GitHub. That file needs `WEBCO_DB_HOST`, `WEBCO_DB_NAME`, `WEBCO_DB_USER` and `WEBCO_DB_PASSWORD`. `WEBCO_DB_PORT` is optional. The orders table is created on the first successful draft.
