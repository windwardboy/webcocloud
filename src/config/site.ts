/**
 * Site-wide facts and links.
 *
 * Leave unknown values as empty strings until they are confirmed.
 * Do not invent phone numbers, email addresses, HostShop URLs,
 * company registration details, or legal addresses.
 *
 * Pending:
 * - webcoMediaUrl — public Webco Media website
 * - supportEmail
 * - supportPhone
 * - legalName, companyNumber, registeredOffice
 */
export const site = {
  name: "Webco Cloud",
  url: "https://webcocloud.net",
  description:
    "Webco Cloud looks after hosting, domains, business email, renewals and support for websites built by Webco Media.",
  positioning:
    "Websites, hosting, email, domains and support — managed in one place.",
  clientLoginUrl: "https://my.webcocloud.net/basket-summary-login?r=%2Fmanage",
  webcoMediaUrl: "",
  supportEmail: "",
  supportPhone: "",
  legalName: "",
  companyNumber: "",
  registeredOffice: "",
};

export const nav = [
  { href: "/", label: "Home" },
  { href: "/websites/", label: "Websites" },
  { href: "/hosting/", label: "Hosting" },
  { href: "/domains/", label: "Domains" },
  { href: "/email/", label: "Email" },
  { href: "/support/", label: "Support" },
] as const;

export const websiteOffers = [
  {
    id: "essential",
    name: "Essential Website",
    startPackage: "essential",
    startName: "Webco Essential",
    price: "£595",
    audience: "Independent instructors and smaller providers",
    summary: "A simple, professional site that brings enquiries for one main location.",
    exampleUrl: "https://webco-essential.co.uk/",
    points: [
      "One main training location",
      "Core course and service information",
      "Enquiry and contact path",
      "First year of hosting, SSL, domain setup and business email",
    ],
  },
  {
    id: "training",
    name: "Training Provider Website",
    startPackage: "professional",
    startName: "Webco Professional",
    price: "£995",
    audience: "Established providers with several courses or locations",
    summary: "Room for each course and each training location to have its own page.",
    exampleUrl: "https://webco-professional.co.uk/",
    points: [
      "A page for each course",
      "A page for each training location",
      "Stronger local search structure",
      "A fuller path from a course through to enquiry",
      "First year of hosting, SSL, domain setup and business email",
    ],
  },
] as const;

/** Working commercial figures from the project brief. Still subject to review. */
export const commercial = {
  reviewNote: "These are working prices and are still subject to final commercial review.",
  hostingRenewal: {
    name: "Website hosting renewal",
    price: "About £99 a year",
    summary: "Basic website hosting after the first year.",
  },
  managedCare: {
    name: "Managed Care",
    price: "£69 a month",
    summary:
      "Hosting, backups, maintenance, monitoring, support and routine website content changes. This includes hosting, so a separate hosting renewal is not required while Managed Care is active.",
    allowance:
      "About 30 minutes of routine content changes a month, including reasonable course, pricing and location updates. Unused time does not roll over. Larger changes are quoted separately.",
  },
} as const;
