import { site } from "../config/site";

/** Webco client area: project, brief and customer home. */
export function clientAreaHref(): string {
  return site.clientAreaPath;
}

/** Existing hosting account login (20i/HostShop). */
export function hostingLoginHref(): string {
  return site.hostingLoginUrl;
}

export function canonicalUrl(pathname: string): string {
  const path = pathname === "/" ? "/" : pathname.endsWith("/") ? pathname : `${pathname}/`;
  return new URL(path, site.url).href;
}

export function isCurrentPath(pathname: string, href: string): boolean {
  const current = pathname.replace(/\/$/, "") || "/";
  const target = href.replace(/\/$/, "") || "/";
  return current === target;
}
