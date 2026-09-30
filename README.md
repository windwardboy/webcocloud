# Webco Cloud

Public website for [webcocloud.net](https://webcocloud.net). Static Astro site. The longer-term platform architecture is in `WEBCO-CLOUD-PROJECT-BRIEF.md`. Client login currently opens the existing HostShop customer area and stays there until Webco Cloud has its own accounts.

## Edit

Business facts, prices and links live in `src/config/site.ts`. Leave unknown contact details and company details blank until they are confirmed.

## Build

```bash
npm install
npm run check
npm run build
```

`npm run build` writes the static site to `dist/`. That folder is committed so 20i can deploy it. Point the package document root at `dist`. No Node process, database or CMS is required on the server.
