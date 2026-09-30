# Webco Cloud — Project Brief

## Project purpose

Webco Cloud (`webcocloud.net`) is the infrastructure and customer-service platform behind Webco Media-built websites.

The public site is a static Astro website on `webcocloud.net`. That site is the current customer-facing shell. It is not the finished platform.

The latest architecture below overrides any earlier assumption that Webco Cloud is only a marketing front end for HostShop or StackCP.

Webco Cloud should not try to look like a generic commodity hosting company. Its job is to make the customer relationship simple:

- website hosting
- domains
- business email
- billing
- renewals
- support
- client login

The intended brand relationship is:

- **LearnHGV** introduces relevant website services to HGV / transport training providers.
- **Webco Media** designs and builds the websites.
- **Webco Cloud** powers hosting, domains, email, billing, renewals and support.

The public-facing wording should keep those roles clear.

---

## Current infrastructure

The existing WordPress installation for `webcocloud.net` has been removed.

The 20i hosting package has been migrated away from the WordPress-specific hosting platform to a clean standard Linux hosting package.

The project will use:

- **Astro**
- static site output
- GitHub for source control
- local development in Cursor
- 20i hosting for production
- the same GitHub → 20i deployment approach already proven on `drivercompliance.co.uk`
- 20i for hosting, domains, email, SSL and provisioning
- HostShop / StackCP only as the current temporary customer login and as an internal or advanced fallback

The public site stays static. It has no database and no custom accounts. Do not add those until a later phase in the plan below is explicitly started.

---

## Latest architecture

Webco Cloud is becoming the customer-facing platform.

Normal customers should not need to use HostShop or StackCP. Those can remain available to Webco internally, or as an advanced fallback. Do not remove the current HostShop/StackCP client login until Webco Cloud can replace it.

### Webco Cloud owns

- onboarding funnel
- customer accounts
- package selection
- domain selection and search
- checkout and payment
- billing and invoices
- support and the customer relationship

### 20i provides

- hosting infrastructure
- domain provisioning and management
- email infrastructure
- SSL and related hosting services
- provisioning through the 20i Reseller API

Billing should be handled by Webco Cloud. Customers should not be sent through HostShop’s payment-method workflow.

The first commercial funnel is the HGV / transport training-provider website offer:

- Essential Website — £595
- Training Provider Website — £995

A prospect should be able to:

1. understand the two offers
2. view an example site for each
3. clearly select the appropriate package
4. choose a new domain, or say they already have one
5. provide business and contact details
6. pay through a Webco Cloud checkout
7. trigger automatic provisioning in the background
8. create an internal work ticket or order so Webco can customise the provisioned site

Do not build this funnel yet. The sections below map what it will need and in what order.

### Backend components required later

These are not to be built in the current phase.

| Component | Role |
| --- | --- |
| Public Astro site | The existing site, plus the later offer and onboarding pages. Stays static wherever it does not need a secret or a write. |
| Platform API | A small server-side application. The only place that holds the 20i Reseller API credential. The static site must never call 20i directly. |
| Database | Customers, businesses, orders, chosen package, domain choice, contact details, payments, invoices, provisioning jobs, and internal work tickets. |
| Customer accounts | Sign-in owned by Webco Cloud, so a normal customer does not use HostShop. |
| Package catalogue | Essential Website, Training Provider Website, and later Managed Care and hosting renewal. |
| Domain search | 20i domain availability, then the choice between registering a new name and using an existing one. |
| Checkout | Card payment recorded by Webco Cloud, separate from HostShop stored payment methods. The payment provider is still to be chosen. |
| Provisioning worker | Background jobs that call the 20i Reseller API after payment: create the site package from a master, register or attach the domain, and arrange email and SSL. |
| Internal work ticket | An order for Webco to customise the provisioned site: branding, courses, locations, images, forms and SEO. |
| Billing | Invoices, receipts and renewals inside Webco Cloud. |
| Support | The customer relationship inside Webco Cloud. HostShop’s help desk is not the normal path. |
| Staff view | Webco can see orders, payment, provisioning and tickets. HostShop and StackCP stay available for advanced infrastructure work. |
| Secrets and audit | API keys and payment keys stay off the public site and out of the repository. Provisioning and payment events are logged. |

The static files on 20i cannot safely hold the reseller API key. When API work starts, it needs a server-side caller. A small script on the existing 20i package is the simplest place to prove that. A separate application host is only justified when accounts, checkout or the database actually start.

### Phased implementation

Do not start a phase until it is explicitly requested. Do not pull authentication, billing, provisioning or a database forward to make a later phase easier.

**Phase 0 — Public site. Done.**  
The Astro site for Home, Hosting, Domains, Email, Support and Client Login. Client Login still opens the existing HostShop customer area at `my.webcocloud.net`. Keep that link until Webco Cloud accounts exist.

**Phase 1 — Domain availability proof of concept. First API work.**  
`public/domain-search.php` checks one domain through the 20i Reseller API. The domains page calls it. The General API key stays in the private server file, not in this repository.

- Server-side only.
- No customer account, payment, provisioning, order record or database.
- Confirm authentication, the real request and response, failures, and whether the result is good enough for a later “new domain or I already have one” step.
- The check is on the public domains page. It is not registration, checkout or the onboarding funnel.

**Phase 2 — Offers and example sites.**  
Explain Essential Website and Training Provider Website, and show one example site for each. Package selection can be visible. Checkout, accounts and provisioning stay off.

**Phase 3 — Accounts and saved orders.**  
Introduce the database and Webco Cloud customer accounts. Store the chosen package, the domain choice, and the business and contact details. Still no card payment and no 20i provisioning.

**Phase 4 — Webco Cloud checkout.**  
Take payment for the two website packages and record the order and invoice in Webco Cloud. Do not use HostShop’s payment-method workflow.

**Phase 5 — Provision and hand over to Webco.**  
After payment, provision in the background through the 20i Reseller API, then open the internal work ticket so customisation can start. The customer gets a confirmation, not a HostShop or StackCP session.

**Phase 6 — Billing and support.**  
Move renewals, Managed Care, invoices and support into Webco Cloud. HostShop and StackCP become internal tools or an advanced fallback.

Example sites and the reusable training-provider master can be built with Phase 2, because the funnel needs something real to show. LearnHGV may later link to the Webco Cloud offer. Buying Webco services must still never affect LearnHGV ranking, verification or directory treatment.

---

## Target customers

The first important customer group will be small and medium UK businesses whose websites are built by Webco Media.

The first focused commercial use case is HGV / transport training providers reached through LearnHGV.

Typical customers are not technical.

They should never need to understand:

- FTP
- MySQL
- PHP versions
- cron jobs
- hosting control panels
- Git
- deployment
- DNS internals

The customer experience should feel like:

> One place for your website, domain, email and support.

---

## Webco training-provider website offer

This is being developed in parallel and Webco Cloud will provide the infrastructure behind it.

### Essential Website — £595

For independent instructors and smaller training providers with a straightforward training offer.

Expected scope:

- professional responsive website
- approximately one main training location
- core course/service information
- enquiry/contact flow
- click-to-call
- optional WhatsApp contact
- trust / accreditation / testimonial sections
- technical SEO foundations
- sitemap / robots / metadata
- analytics and Search Console setup
- first-year hosting
- SSL
- domain setup / registration where applicable
- professional domain email setup

This is intentionally constrained.

No custom portals, ecommerce, complex booking systems or bespoke applications are included by default.

### Training Provider Website — £995

For established providers with multiple courses, training locations or stronger search requirements.

Expected additional capabilities:

- dedicated course pages
- dedicated location pages
- multi-location architecture
- stronger local SEO structure
- richer enquiry journeys
- more extensive content structure

This package must be differentiated by business need rather than simply advertised as “more pages”.

### Managed Care — £69/month

Optional ongoing service.

Expected inclusions:

- hosting
- backups
- maintenance
- monitoring
- routine website content changes
- reasonable course / pricing / location amendments
- support

Use a clear scope boundary.

Current working assumption:

- up to approximately 30 minutes of routine content changes per month
- unused time does not roll over
- larger changes are quoted separately

### Hosting after year one

Current working idea:

- basic website hosting renewal: approximately **£99/year**
- alternatively the client can use **Managed Care at £69/month**, which includes hosting

These prices are still subject to final commercial review.

---

## Existing Webco / 20i advantages

The business already has useful infrastructure that should be exploited rather than replaced:

- 20i reseller hosting
- current Reseller 50 package
- low-cost upgrade path to a much larger reseller tier
- unlimited email accounts under the reseller platform
- 10GB storage per mailbox
- unlimited MySQL databases where required
- registrar capability
- low-cost domain registration and renewals
- simple 20i site/package cloning, which the later provisioning step should use
- 20i Reseller API for domain search and, later, provisioning
- HostShop and StackCP as an internal or advanced fallback, not the normal customer experience

Use 20i’s infrastructure. Do not make HostShop the product the customer has to learn.

---

## Important business model principle

The economics depend on repeatability.

The goal is not to hand-build every customer site from zero.

For future client websites, the intended workflow is:

1. clone / copy a proven master
2. change branding
3. change business details
4. add courses
5. add real locations
6. replace images
7. configure forms / email
8. complete SEO metadata
9. QA
10. deploy

The customer receives a tailored website.

Webco receives a repeatable production system.

---

## Astro direction

Webco Cloud itself will use Astro.

Future training-provider sites are also likely to use Astro.

Astro is being chosen because it allows:

- reusable components
- static output
- simple hosting on 20i
- low maintenance
- strong performance
- easy Git-based version control
- easy cloning / reuse
- no mandatory runtime server for the public site or the training-provider sites
- no database for those sites

The later Webco Cloud platform is separate. Its database, accounts and API are described in the latest architecture section. They are not part of the Astro site.

The eventual training-provider master architecture may look broadly like:

```text
src/
  components/
  layouts/
  pages/

content/
  courses/
  locations/
  testimonials/
  faqs/

config/
  site.ts
```

The exact structure can evolve during implementation.

---

## Webco Cloud v1 site structure

Keep navigation and page count deliberately small.

Initial public navigation:

- Home
- Hosting
- Domains
- Email
- Support
- Client Login

Potential route structure:

```text
/
 /hosting
 /domains
 /email
 /support
```

`Client Login` currently points at the existing HostShop customer area. That is temporary. Do not build a custom login system until Phase 3.

---

## Homepage goals

The homepage should communicate within seconds:

1. what Webco Cloud is
2. who it is for
3. what services it provides
4. how to get support
5. where existing customers log in

The tone should be:

- clear
- competent
- friendly
- straightforward
- not overly corporate
- not “cheap hosting” marketing
- not cloud-computing jargon

Avoid cliché hosting claims such as:

- blazing fast
- enterprise-grade
- world-class
- unlimited everything
- revolutionary cloud technology

Prefer concrete wording.

Possible positioning direction:

> Websites, hosting, email, domains and support — managed in one place.

or:

> The platform behind Webco websites.

Do not treat either line as final copy yet.

---

## Relationship with Webco Media

Webco Cloud is the platform.

Webco Media is the website design/build service.

The intended journey is:

```text
LearnHGV
    ↓
Webco Cloud offer
    ↓
Choose Essential (£595) or Training Provider (£995)
    ↓
View an example, choose a domain, enter business details, pay
    ↓
20i provisions the site in the background
    ↓
Webco customises it from the master
    ↓
Webco Cloud account for billing, domain, email and support
```

Webco Media still designs and builds. The onboarding funnel for these two packages lives on Webco Cloud, because Webco Cloud owns package selection, domain choice, checkout and the customer relationship.

Refer to Webco Media where the design and build work is relevant. Do not recreate an entire separate sales site.

---

## Relationship with LearnHGV

LearnHGV may later show a small provider-dashboard card such as:

> Need help with your website?

That card can link to a dedicated Webco Media landing page.

Important trust rule:

**Buying Webco services must never affect LearnHGV ranking, verification status or directory treatment.**

The commercial relationship must remain clearly separate from LearnHGV verification and search ranking.

---

## HostShop / StackCP

HostShop and StackCP are not the long-term customer experience. The current out-of-the-box journey is too difficult for non-technical customers. It can involve creating an account, verifying email, entering details, security setup and payment. Past customers have often needed manual help.

The customer experience to build towards is:

> Choose the package → choose the domain → enter business details → pay.

Until Webco Cloud checkout and accounts exist, Client Login on the public site continues to open the existing HostShop customer area. Do not remove that link in the meantime.

Do not invest in making HostShop the onboarding product. Hidden products, custom quotes and HostShop payment methods are not the planned path. Provisioning goes through the 20i Reseller API. Payment and invoices go through Webco Cloud.

---

## Email policy

20i allows many mailboxes at very low marginal cost.

This is a useful customer benefit.

Possible website package wording later:

> Professional business email accounts available with your website.

Do not promise unlimited setup/support merely because the underlying hosting allows unlimited mailboxes.

A reasonable launch scope may be:

- set up a limited number of mailboxes initially
- provide configuration details
- ongoing device troubleshooting is separate from website support

Example customer issue that should not automatically become website-maintenance work:

> “My Outlook stopped syncing on my wife's iPhone.”

Keep service boundaries sensible.

---

## Forms

Client websites should normally be static but may use a small server-side form endpoint on 20i.

Likely approach:

- static front end
- small PHP form handler
- server-side validation
- honeypot
- sensible rate limiting
- SMTP delivery
- add Turnstile or similar only if spam requires it

Do not introduce a full backend framework just for contact forms.

---

## Databases

Two different rules:

**Customer websites.** Static first. 20i provides MySQL, but a training-provider site does not get a database unless a real feature needs stored data, such as course availability, simple bookings, protected resources or a lightweight portal.

**Webco Cloud platform.** Accounts, orders, invoices, provisioning state and internal work tickets will need a database. That database is part of Phase 3 in the plan above. Do not create it while the public site and the domain-search proof of concept are the active work.

---

## Analytics

Future Webco-built sites should support:

- Google Analytics where appropriate
- Google Search Console
- enquiry conversion events
- click-to-call events
- WhatsApp clicks where present
- email clicks where useful

The implementation should remain lightweight.

---

## SEO / performance principles

Sites should be designed for:

- excellent mobile usability
- semantic HTML
- strong Core Web Vitals
- minimal unnecessary JavaScript
- properly sized and compressed images
- clean metadata
- XML sitemap
- robots.txt
- canonical URLs
- sensible schema markup
- accessible navigation and forms

For training-provider sites, dedicated pages should only be created for genuine courses and genuine training locations / legitimate service areas.

Avoid doorway-page or fake-location SEO tactics.

---

## Accessibility principles

Build accessibility into the reusable components by default.

At minimum:

- semantic HTML
- keyboard accessibility
- clear form labels
- visible focus states
- sensible contrast
- alt text support
- reduced-motion consideration
- logical heading structure

Do not treat basic accessibility as a paid extra.

---

## Visual direction

No final visual system has been selected yet.

The design should feel:

- modern
- calm
- trustworthy
- technical without being cold
- uncomplicated
- appropriate for small UK businesses

Avoid:

- generic hosting-template aesthetics
- fake server-room imagery
- excessive gradients
- oversized pricing tables
- crowded feature grids
- dozens of hosting-plan choices
- visual noise

Choice overload is specifically something we want to avoid.

---

## Development rules

### Keep dependencies lean

Do not add packages unless they solve a real problem.

### Prefer Astro-native solutions

Do not add React/Vue/Svelte unless interactive functionality genuinely requires them.

### Static by default

Pages should prerender wherever possible.

### Keep config/content separate

Where sensible, business data and repeated content should be separated from layout components.

### Mobile first

All layouts must work well on small screens first.

### No CMS in v1

There is currently no requirement for a public CMS.

### No database on the public site

The current Astro site does not store customer data. The platform database waits for Phase 3.

### No custom authentication yet

Client Login still links to the existing HostShop customer area. Webco Cloud accounts are Phase 3. Do not add sign-in before then.

### API work starts with domain search

Do not build billing, provisioning or account automation yet. The first 20i Reseller API proof of concept, when requested, is domain availability / search only.

---

## Public site milestone

This milestone is already built. Keep it working. Do not expand it into the platform.

The public site contains:

1. global layout
2. header / navigation
3. footer
4. homepage
5. Hosting page
6. Domains page
7. Email page
8. Support page
9. Client Login CTA
10. responsive styling
11. metadata / canonical support
12. sitemap
13. robots.txt
14. favicon / placeholder branding structure
15. 404 page

Do not spend time on speculative features.

Use placeholders where final business values or links are not yet known.

Never invent:

- phone numbers
- customer support addresses
- HostShop URLs
- prices not stated in this document
- company registration details
- legal addresses

Mark missing values clearly for later configuration.

---

## Initial build

This has been done. The repository is the static Astro site, with `dist/` committed for 20i.

The original task was:

1. inspecting the empty/new repository
2. scaffolding a clean Astro project
3. creating a sensible maintainable project structure
4. implementing the Webco Cloud v1 shell and page structure described above
5. keeping content easy to edit
6. keeping dependencies minimal
7. ensuring the output is suitable for static deployment on 20i Linux hosting

Before adding anything complex, explain why it is needed.

The objective is to establish a strong, reusable foundation — not to finish every possible feature in the first pass.

---

## What not to build yet

The public Astro site stays as it is.

Do not start authentication, checkout, billing, provisioning or a database until that phase is explicitly requested. Domain availability search is the only 20i API call in place.

---

## Final guiding principle

The technology exists to make Webco more efficient.

The customer should not experience the complexity behind it.

The ideal result is:

> Webco Media builds it.  
> Webco Cloud runs it.  
> The customer simply gets a website, email, domain, billing and support that work.
