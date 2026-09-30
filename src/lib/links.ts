import { site } from "../config/site";

export function clientLoginHref(): string {
  return site.clientLoginUrl || "/support/#client-login";
}

export function clientLoginIsExternal(): boolean {
  return site.clientLoginUrl.length > 0;
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
