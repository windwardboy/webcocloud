# Webco Cloud

Public website for [webcocloud.net](https://webcocloud.net). Static Astro site. Customer accounts, billing, domains, email and support stay in the existing Webco Cloud customer area.

## Edit

Business facts, prices and links live in `src/config/site.ts`. Leave unknown contact details, the customer login URL and company details blank until they are confirmed.

## Build

```bash
npm install
npm run check
npm run build
```

`dist/` is the static site to upload to the 20i Linux document root. No Node process, database or CMS is required on the server.

Production deploy should follow the GitHub → 20i approach already used for drivercompliance.co.uk. That workflow is not in this repository yet.
