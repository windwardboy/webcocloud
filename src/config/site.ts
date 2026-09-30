/**
 * Site-wide facts and links.
 *
 * Leave unknown values as empty strings until they are confirmed.
 * Do not invent phone numbers, email addresses, HostShop URLs,
 * company registration details, or legal addresses.
 *
 * Pending:
 * - clientLoginUrl — Webco Cloud HostShop / StackCP customer login
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
  clientLoginUrl: "",
  webcoMediaUrl: "",
  supportEmail: "",
  supportPhone: "",
  legalName: "",
  companyNumber: "",
  registeredOffice: "",
};

export const nav = [
  { href: "/", label: "Home" },
  { href: "/hosting/", label: "Hosting" },
  { href: "/domains/", label: "Domains" },
  { href: "/email/", label: "Email" },
  { href: "/support/", label: "Support" },
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
