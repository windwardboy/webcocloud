/**
 * Site-wide facts and links.
 *
 * Leave unknown values as empty strings until they are confirmed.
 * Do not invent phone numbers, email addresses, HostShop URLs,
 * company registration details, or legal addresses.
 *
 * Pending:
 * - supportEmail
 * - supportPhone
 * - companyNumber, registeredOffice
 */
export const site = {
  name: "Webco Cloud",
  url: "https://webcocloud.net",
  description:
    "Webco Cloud is the platform for purchasing Webco products, managing accounts and billing, and getting support — for websites built by Webco Media.",
  positioning:
    "Websites, hosting, email, domains and support — managed in one place.",
  /** Webco customer and project area. Access uses the existing brief session. */
  clientAreaPath: "/brief.php",
  /** Existing 20i/HostShop login. This is not the Webco client area. */
  hostingLoginUrl: "https://my.webcocloud.net/basket-summary-login?r=%2Fmanage",
  webcoMediaUrl: "https://webcomedia.net",
  supportEmail: "",
  supportPhone: "",
  legalName: "Webco Services Ltd",
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

const essentialDemoUrl = "https://webco-essential.co.uk/";
const professionalDemoUrl = "https://webco-professional.co.uk/";

/**
 * Website packages. Demos are category examples — HGV training is the first
 * category live today; more industries will be added over time.
 */
export const websiteOffers = [
  {
    id: "essential",
    name: "Essential Website",
    startPackage: "essential",
    startName: "Webco Essential",
    price: "£595",
    audience: "Smaller businesses with one main location",
    summary: "A simple, professional site that brings enquiries for one main location.",
    exampleUrl: essentialDemoUrl,
    demoUrl: essentialDemoUrl,
    previewImage: "/images/website-essential.jpg",
    demoCategory: "HGV training",
    points: [
      "One main location",
      "Core service information",
      "Enquiry and contact path",
      "First year of hosting, SSL, domain setup and business email",
    ],
  },
  {
    id: "professional",
    name: "Professional Website",
    startPackage: "professional",
    startName: "Webco Professional",
    price: "£995",
    audience: "Businesses with several services or locations",
    summary: "Room for each service and each location to have its own page.",
    exampleUrl: professionalDemoUrl,
    demoUrl: professionalDemoUrl,
    previewImage: "/images/website-professional.jpg",
    demoCategory: "HGV training",
    points: [
      "A page for each key service",
      "A page for each location",
      "Stronger local search structure",
      "A fuller path from a service through to enquiry",
      "First year of hosting, SSL, domain setup and business email",
    ],
  },
] as const;

/** Display-only package facts for the funnel. Prices charged are still the server allowlist. */
export const websitePackagePresentation = Object.fromEntries(
  websiteOffers.map((offer) => [
    offer.startPackage,
    {
      name: offer.startName,
      price: offer.price,
      demoUrl: offer.demoUrl,
      previewImage: offer.previewImage,
    },
  ]),
);

/** Agreed website-package prices. */
export const commercial = {
  hostingRenewal: {
    name: "Standard hosting",
    price: "£99/year",
    summary:
      "The first 12 months of hosting are included. After that, standard hosting is £99/year if Managed Care is not taken.",
  },
  managedCare: {
    name: "Managed Care",
    essential: "£39/month",
    professional: "£59/month",
    summary:
      "Hosting, maintenance, routine content updates and support, with a 30-day trial from checkout. Hosting is included, so a separate hosting renewal is not required while Managed Care is active.",
    allowance:
      "About 30 minutes of routine content changes a month, including reasonable service, pricing and location updates. Unused time does not roll over. Larger changes are quoted separately.",
  },
} as const;
