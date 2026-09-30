# Webco Cloud — Project Brief

## Project purpose

Webco Cloud (`webcocloud.net`) is the infrastructure and customer-service platform behind Webco Media-built websites.

The immediate goal is to rebuild Webco Cloud from scratch as a clean, modern static website that works alongside the existing 20i reseller/HostShop platform.

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
- 20i HostShop / StackCP for customer accounts, billing, domains, email, hosting and support

Do not introduce a database, CMS, SaaS backend or external hosting platform unless there is a clear requirement.

---

## Core principle

Keep this project simple.

Webco Cloud v1 is not a new hosting SaaS platform and is not a custom control panel.

For now:

1. Build a strong public-facing Webco Cloud website.
2. Link customers cleanly into the existing branded HostShop / StackCP system.
3. Improve the customer journey and branding around that system.
4. Leave deeper API automation for a later phase only if it becomes useful.

Avoid overengineering.

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
- HostShop for recurring billing and customer management
- HostShop support functionality
- simple 20i site/package cloning
- StackCP white-label capability
- 20i reseller API available for future automation

The service should be designed around this existing infrastructure.

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
- no mandatory runtime server
- no database requirement

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

`Client Login` should point to the existing Webco Cloud / HostShop / StackCP customer area rather than creating a new authentication system.

Do not build a custom login system in v1.

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

A future customer journey may be:

```text
LearnHGV
    ↓
Webco Media training-provider website offer
    ↓
Customer chooses / orders website
    ↓
Webco Cloud account
    ↓
Hosting + domain + email + billing + support
```

Webco Cloud should therefore contain appropriate references to Webco Media where website design/build services are relevant.

Do not duplicate the entire Webco Media website-service sales page inside Webco Cloud.

---

## Relationship with LearnHGV

LearnHGV may later show a small provider-dashboard card such as:

> Need help with your website?

That card can link to a dedicated Webco Media landing page.

Important trust rule:

**Buying Webco services must never affect LearnHGV ranking, verification status or directory treatment.**

The commercial relationship must remain clearly separate from LearnHGV verification and search ranking.

---

## HostShop / StackCP strategy

The existing 20i platform should remain the engine for:

- customer accounts
- recurring billing
- invoices
- domains
- renewals
- hosting
- email
- support
- service management

Do not rebuild these functions in Webco Cloud v1.

However, the current out-of-the-box customer journey is too difficult for non-technical customers.

A major future objective is to reduce signup/payment friction.

Current normal HostShop onboarding can involve:

- creating an account
- verifying email
- entering customer details
- security setup
- payment / purchase

Past Webco customers have often needed manual help through this process.

The desired customer experience is closer to:

> Choose service → enter business details → pay.

Possible later approaches include:

- Webco creating the customer account first
- assigning services manually
- using custom work quotes
- using hidden HostShop products with direct links
- using the 20i reseller API for deeper automation

Do not build API automation yet.

First understand and improve the existing flow.

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

20i provides unlimited MySQL databases, but this does not mean every website should use one.

Default rule:

> Static first.

Use PHP/MySQL only when a genuine feature requires persistent application data.

Examples that may justify a database later:

- course availability
- simple bookings
- protected resources
- customer application tracking
- lightweight client portals

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

### No database in v1

There is currently no requirement for persistent site data.

### No custom authentication

Client Login links to the existing Webco customer platform.

### No premature API work

Do not build against the 20i API until the normal HostShop / StackCP integration has been assessed and there is a clear need.

---

## Suggested first implementation milestone

Create a polished but intentionally small Astro v1 containing:

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

## Initial Cursor task

Start by:

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

## Future phases

These are intentionally **not** part of the first implementation unless explicitly requested.

### Phase 2 — HostShop / StackCP refinement

- improve Webco Cloud branding
- simplify navigation where possible
- remove irrelevant customer-facing options
- test purchase / payment / onboarding friction
- improve support journey
- test direct product links
- investigate hidden products
- investigate custom work quotes

### Phase 3 — Training-provider site master

Build the reusable Astro foundation used for the £595 and £995 website offers.

### Phase 4 — Demo sites

Build two fictional but realistic demo websites:

1. small independent training provider
2. established multi-course / multi-location provider

These demos should also prove the reusable master architecture.

### Phase 5 — Webco Media sales page

Build the dedicated:

> Websites for HGV & transport training providers

landing page on Webco Media.

### Phase 6 — LearnHGV integration

Add a discreet provider-dashboard link/card to the Webco Media offer.

### Phase 7 — Optional automation

Only after real customers expose actual friction:

- 20i reseller API
- streamlined provisioning
- simplified customer dashboard
- automated customer/account creation
- deeper billing workflow integration

---

## Final guiding principle

The technology exists to make Webco more efficient.

The customer should not experience the complexity behind it.

The ideal result is:

> Webco Media builds it.  
> Webco Cloud runs it.  
> The customer simply gets a website, email, domain, billing and support that work.
